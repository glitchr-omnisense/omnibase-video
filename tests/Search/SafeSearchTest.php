<?php

namespace Base\Video\Tests\Search;

use Base\Video\DependencyInjection\SafeSearchPass;
use Base\Video\Repository\VideoRepository;
use Base\Video\Search\SafeIndexer;
use Base\Video\Search\VideoSearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Typesense\Bundle\DBAL\Transaction;
use Typesense\Bundle\EventListener\TypesenseIndexer;

/**
 * The search is optional and never fails a save: with glitchr/typesense-bundle, its indexers are SafeIndexer (an
 * unreachable server is logged, the flush goes on); without it, nothing of the search is required.
 */
final class SafeSearchTest extends TestCase
{
    public function testAFailedUpsertIsLoggedAndTheSaveGoesOn(): void
    {
        $indexer = (new \ReflectionClass(SafeIndexer::class))->newInstanceWithoutConstructor();
        $failing = $this->createStub(Transaction::class);
        // What symfony/http-client throws for "Failed to connect to 127.0.0.1 port 1": no exception the bundle catches.
        $failing->method('commit')->willThrowException(new \RuntimeException('Failed to connect to 127.0.0.1 port 1'));
        (new \ReflectionProperty(TypesenseIndexer::class, 'transactions'))->setValue($indexer, [$failing, $failing]);
        (new \ReflectionProperty(TypesenseIndexer::class, 'cache'))->setValue($indexer, new Psr16Cache(new ArrayAdapter()));
        (new \ReflectionProperty(TypesenseIndexer::class, 'onError'))->setValue($indexer, null);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = strtr((string) $message, ['{message}' => (string) ($context['message'] ?? '')]);
            }
        };
        $indexer->setLogger($logger);

        $indexer->flush();

        self::assertSame(['The search index missed a change (Typesense): Failed to connect to 127.0.0.1 port 1'], $logger->warnings, 'once per flush');
        self::assertSame([], (new \ReflectionProperty(TypesenseIndexer::class, 'transactions'))->getValue($indexer), 'forgotten: not sent twice');
    }

    public function testEveryIndexerOfTheBundleBecomesSafe(): void
    {
        $container = new ContainerBuilder();
        foreach (SafeSearchPass::INDEXERS as $id) {
            $container->setDefinition($id, (new Definition(TypesenseIndexer::class))->addTag('doctrine.event_listener', ['event' => 'postFlush']));
        }

        (new SafeSearchPass())->process($container);

        foreach (SafeSearchPass::INDEXERS as $id) {
            $definition = $container->getDefinition($id);
            self::assertSame(SafeIndexer::class, $definition->getClass(), $id);
            self::assertTrue($definition->hasMethodCall('setLogger'));
            self::assertTrue($definition->hasTag('doctrine.event_listener'), 'the same service: its tags kept');
        }
    }

    public function testAHostWithoutTypesenseHasNoSearchToWire(): void
    {
        $container = new ContainerBuilder();
        $container->register(VideoRepository::class)->setSynthetic(true);
        $container->register(VideoSearch::class)->setAutowired(true)->setPublic(true);

        (new SafeSearchPass())->process($container);
        $container->compile();

        foreach (SafeSearchPass::INDEXERS as $id) {
            self::assertFalse($container->has($id), 'nothing indexes without the bundle');
        }
        $container->set(VideoRepository::class, (new \ReflectionClass(VideoRepository::class))->newInstanceWithoutConstructor());
        self::assertInstanceOf(VideoSearch::class, $container->get(VideoSearch::class), 'the search answers from the database, no Typesense finder asked');
    }
}
