<?php

namespace Base\Video\Tests\Player;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A film read ahead into the visitor's browser (assets/prefetch.js): the
 * player's hls.js reads that cache first, the watch page offers it for the
 * films the player itself reads (ours, remote ones), never for a platform's
 * player. prefetch.test.mjs checks the read itself under node, run here
 * where node is installed.
 */
class PrefetchTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testThePlayerReadsWhatWasReadAheadFirst(): void
    {
        $player = (string) file_get_contents(self::ROOT.'/assets/player.js');

        $this->assertStringContainsString("import Prefetch from './prefetch.js';", $player);
        $this->assertMatchesRegularExpression("/if \\(data.type === 'hls'\\) el.config = Prefetch.config\\(\\);\\s*el.setAttribute\\('src', data.src\\);/", $player, 'the loaders given before the address: <hls-video> reads its config when it loads');
        $this->assertStringNotContainsString('serviceWorker', (string) file_get_contents(self::ROOT.'/assets/prefetch.js'), "omnibase's /sw.js holds the site's scope");
    }

    public function testTheWatchPageOffersItForTheFilmsThePlayerReads(): void
    {
        $watch = (string) file_get_contents(self::ROOT.'/templates/client/watch.html.twig');

        $this->assertStringContainsString("prefetchable.kind in ['hls', 'remote-hls']", $watch);
        $this->assertStringContainsString("prefetchable.kind == 'remote-hls' ? path('video_manifest', {slug: video.slug}) : prefetchable.url", $watch, 'the address the stage gives the player');
        $this->assertMatchesRegularExpression('/<button type="button" class="video-button" hidden[^>]*data-video-prefetch=/', $watch, 'hidden until the browser can keep it');
    }

    public function testItsWordsInEachLanguage(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $words = Yaml::parseFile(self::ROOT.'/translations/video+intl-icu.'.$locale.'.yaml')['watch']['prefetch'] ?? [];
            $this->assertSame(['idle', 'running', 'partial', 'done', 'help', 'stop', 'remove', 'confirm_remove'], array_keys($words), $locale);
            $this->assertStringContainsString('{percent}', $words['running'], $locale);
            $this->assertStringContainsString('{size}', $words['done'], $locale);
        }
    }

    public function testTheReadItself(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ('' === $node) {
            $this->markTestSkipped('node is not installed here: node --test tests/Player/prefetch.test.mjs');
        }
        exec(escapeshellarg($node).' --test '.escapeshellarg(self::ROOT.'/tests/Player/prefetch.test.mjs').' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
