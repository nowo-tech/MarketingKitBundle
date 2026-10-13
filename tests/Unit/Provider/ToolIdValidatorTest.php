<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Provider\ClarityRenderer;
use Nowo\MarketingKitBundle\Provider\Ga4Renderer;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\HotjarRenderer;
use Nowo\MarketingKitBundle\Provider\LinkedInRenderer;
use Nowo\MarketingKitBundle\Provider\MetaPixelRenderer;
use Nowo\MarketingKitBundle\Provider\TikTokRenderer;
use Nowo\MarketingKitBundle\Provider\ToolIdValidator;
use Nowo\MarketingKitBundle\Provider\ToolRendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolIdValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, ?string}>
     */
    public static function normalizeCases(): iterable
    {
        yield 'gtm ok' => ['gtm', ' GTM-ABC123 ', 'GTM-ABC123'];
        yield 'gtm lowercase' => ['gtm', 'gtm-abc', null];
        yield 'gtm breakout' => ['gtm', "GTM-X');alert(1);//", null];
        yield 'ga4 ok' => ['ga4', 'G-ABC123', 'G-ABC123'];
        yield 'ga4 breakout' => ['ga4', "G-1');alert(1)//", null];
        yield 'ga4 trailing newline' => ['ga4', "G-1\n", 'G-1'];
        yield 'meta ok int' => ['meta_pixel', 123456, '123456'];
        yield 'meta alpha' => ['meta_pixel', '12a', null];
        yield 'linkedin breakout' => ['linkedin', '1";alert(1);//', null];
        yield 'tiktok ok' => ['tiktok', 'C4ABCDEF123', 'C4ABCDEF123'];
        yield 'tiktok breakout' => ['tiktok', "C4'+alert(1)+'", null];
        yield 'hotjar ok' => ['hotjar', '42', '42'];
        yield 'clarity ok' => ['clarity', 'abc123xyz', 'abc123xyz'];
        yield 'clarity breakout' => ['clarity', 'abc");alert(1);//', null];
        yield 'non scalar' => ['gtm', ['GTM-A'], null];
        yield 'unknown type' => ['custom', 'whatever', null];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $type, mixed $value, ?string $expected): void
    {
        self::assertSame($expected, ToolIdValidator::normalize($type, $value));
    }

    public function testIsValidOrEmpty(): void
    {
        self::assertTrue(ToolIdValidator::isValidOrEmpty('gtm', null));
        self::assertTrue(ToolIdValidator::isValidOrEmpty('gtm', '  '));
        self::assertTrue(ToolIdValidator::isValidOrEmpty('gtm', 'GTM-A1'));
        self::assertFalse(ToolIdValidator::isValidOrEmpty('gtm', '<script>'));
        self::assertTrue(ToolIdValidator::isValidOrEmpty('custom', '<script>'));
        self::assertSame('pixel_id', ToolIdValidator::optionKeyFor('meta_pixel'));
        self::assertNull(ToolIdValidator::optionKeyFor('custom'));
    }

    public function testJsStringIsSafeInScriptElement(): void
    {
        self::assertSame('"G-ABC"', ToolIdValidator::jsString('G-ABC'));
        $encoded = ToolIdValidator::jsString('</script>\'"&');
        foreach (['<', '>', "'", '&'] as $raw) {
            self::assertStringNotContainsString($raw, $encoded);
        }
        self::assertSame(2, substr_count($encoded, '"'));
    }

    /**
     * @return iterable<string, array{ToolRendererInterface, string, string, string}>
     */
    public static function rendererCases(): iterable
    {
        yield 'gtm' => [new GtmRenderer(), 'gtm', 'container_id', 'GTM-ABC'];
        yield 'ga4' => [new Ga4Renderer(), 'ga4', 'measurement_id', 'G-ABC'];
        yield 'meta' => [new MetaPixelRenderer(), 'meta_pixel', 'pixel_id', '123'];
        yield 'linkedin' => [new LinkedInRenderer(), 'linkedin', 'partner_id', '456'];
        yield 'tiktok' => [new TikTokRenderer(), 'tiktok', 'pixel_id', 'C4TT'];
        yield 'hotjar' => [new HotjarRenderer(), 'hotjar', 'site_id', '789'];
        yield 'clarity' => [new ClarityRenderer(), 'clarity', 'project_id', 'abc12'];
    }

    #[DataProvider('rendererCases')]
    public function testRenderersRejectInjectedIds(ToolRendererInterface $renderer, string $type, string $key, string $validId): void
    {
        foreach (["x');alert(document.cookie);//", 'x";alert(1);//', 'x</script><script>alert(1)</script>', "{$validId}'"] as $payload) {
            self::assertSame('', $renderer->render(new ResolvedTool('t', $type, true, 'marketing', 'head', 0, [$key => $payload], 'yaml')), $payload);
        }

        $html = $renderer->render(new ResolvedTool('t', $type, true, 'marketing', 'head', 0, [$key => $validId], 'yaml'));
        self::assertStringContainsString($validId, $html);
        self::assertStringNotContainsString("'" . $validId . "'", $html, 'IDs in JS context are emitted as JSON string literals');
    }
}
