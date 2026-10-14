<?php

namespace Base\Video\Tests\Css;

use PHPUnit\Framework\TestCase;

/**
 * The watch page's grid (public/css/video.css): the stage's row is as tall as
 * the player, and what "À suivre" holds beyond it goes to the row of the
 * title. With two auto rows the aside, spanning both, shared its height
 * between them: some 170 px of nothing under the player on Taratata, desktop.
 * On a phone the page is one column, its rows back to auto.
 */
class WatchLayoutTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/public/css/video.css');
    }

    /** @return array<string, string> the declarations of the first rule with exactly this selector, in $css */
    private static function rule(string $css, string $selector): array
    {
        self::assertSame(1, preg_match('/(?:^|[}\n])\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $m), 'a rule for '.$selector);
        $declarations = [];
        foreach (array_filter(array_map('trim', explode(';', $m[1]))) as $declaration) {
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $declarations[$property] = $value;
        }

        return $declarations;
    }

    public function testTheStagesRowIsAsTallAsThePlayer(): void
    {
        $watch = self::rule(self::css(), '.video-watch');

        self::assertSame('auto 1fr', $watch['grid-template-rows'] ?? null);
        self::assertSame('"stage aside" "main aside"', $watch['grid-template-areas'] ?? null);
    }

    public function testAPhoneHasOneColumnAndItsOwnRows(): void
    {
        self::assertSame(1, preg_match('/@media \(max-width: 960px\) \{(.*?)\n\}/s', self::css(), $m));
        $watch = self::rule($m[1], '.video-watch, html.video-cinema .video-watch');

        self::assertSame('minmax(0, 1fr)', $watch['grid-template-columns'] ?? null);
        self::assertSame('none', $watch['grid-template-rows'] ?? null);
        self::assertSame('"stage" "main" "aside"', $watch['grid-template-areas'] ?? null);
    }
}
