<?php

namespace Base\Video\Tests\Entity;

use Base\Contract\Runtime;
use Omnibridge\Contract\Urls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * A film's link where the site imports the bundle's public pages (video_watch), none where it does not: the admin's
 * list of films (/admin/videos) answered a 500 on the musicians' sites, which keep the films in their back office only.
 */
final class VideoLinkTest extends TestCase
{
    use \Base\Video\Tests\Films;

    private ?object $urls = null;

    private ?object $links = null;

    protected function setUp(): void
    {
        // Link::to() asks the host's Links, else one on Runtime::urls(): the latter, on the test's routes.
        $this->urls = Runtime::find(Urls::class);
        $this->links = Runtime::find(\Base\Service\Links::class);
        Runtime::set(\Base\Service\Links::class, null);
    }

    protected function tearDown(): void
    {
        Runtime::set(Urls::class, $this->urls);
        Runtime::set(\Base\Service\Links::class, $this->links);
    }

    public function testTheFilmsPageWhereTheRouteExists(): void
    {
        Runtime::set(Urls::class, self::urls(['video_watch' => '/v/{slug}']));
        $video = self::film();

        self::assertSame('/v/'.$video->getSlug(), $video->__toLink());
    }

    public function testNoLinkWhereTheSiteDoesNotImportIt(): void
    {
        Runtime::set(Urls::class, self::urls([]));

        self::assertNull(self::film()->__toLink());
    }

    /** @param array<string, string> $routes a router's routes, by name: its path, {slug} replaced */
    private static function urls(array $routes): Urls
    {
        return new class($routes) implements Urls {
            /** @param array<string, string> $routes */
            public function __construct(private readonly array $routes)
            {
            }

            public function path(string $name, array $parameters = []): string
            {
                return isset($this->routes[$name]) ? strtr($this->routes[$name], ['{slug}' => (string) ($parameters['slug'] ?? '')]) : throw new RouteNotFoundException(sprintf('Unable to generate a URL for the named route "%s" as such route does not exist.', $name));
            }

            public function url(string $name, array $parameters = []): string
            {
                return 'https://example.test'.$this->path($name, $parameters);
            }

            public function asset(string $path): string
            {
                return '/'.ltrim($path, '/');
            }
        };
    }
}
