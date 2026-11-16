<?php

namespace Base\Video\DependencyInjection;

use Base\Video\Search\SafeIndexer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Typesense\Bundle\EventListener\TypesenseIndexer;

/**
 * The search is optional: where glitchr/typesense-bundle is registered and configured, each of its indexers (Doctrine's
 * listener, and the one the neutral lifecycle events reach) becomes Search\SafeIndexer - same service, same tags and
 * arguments - so an unreachable Typesense never fails a save. Without the bundle, nothing is touched: omnibase/video
 * works, its search features stay off.
 *
 * @internal
 */
final class SafeSearchPass implements CompilerPassInterface
{
    /** The indexer services of glitchr/typesense-bundle. */
    public const INDEXERS = ['typesense.listener.doctrine_indexer', 'typesense.indexer.lifecycle'];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::INDEXERS as $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? TypesenseIndexer::class);
            if (!\is_string($class) || !is_a($class, TypesenseIndexer::class, true) || is_a($class, SafeIndexer::class, true)) {
                continue;
            }
            $definition->setClass(SafeIndexer::class)
                ->addMethodCall('setLogger', [new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        }
    }
}
