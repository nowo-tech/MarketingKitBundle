<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Provider\ClarityRenderer;
use Nowo\MarketingKitBundle\Provider\CustomScriptRenderer;
use Nowo\MarketingKitBundle\Provider\Ga4Renderer;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\HotjarRenderer;
use Nowo\MarketingKitBundle\Provider\LinkedInRenderer;
use Nowo\MarketingKitBundle\Provider\MetaPixelRenderer;
use Nowo\MarketingKitBundle\Provider\TikTokRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRenderersTest extends TestCase
{
    public function testGtmHeadAndNoscript(): void
    {
        $renderer = new GtmRenderer();
        $head     = $renderer->render(new ResolvedTool('gtm', 'gtm', true, 'analytics', 'head', 0, ['container_id' => 'GTM-ABC'], 'yaml'));
        $body     = $renderer->render(new ResolvedTool('gtm', 'gtm', true, 'analytics', 'body_start', 0, ['container_id' => 'GTM-ABC'], 'yaml'));

        self::assertStringContainsString('GTM-ABC', $head);
        self::assertStringContainsString('googletagmanager.com/gtm.js', $head);
        self::assertStringContainsString('noscript', $body);
    }

    public function testGa4MetaLinkedInTikTokHotjarClarityCustom(): void
    {
        self::assertStringContainsString('G-123', (new Ga4Renderer())->render(
            new ResolvedTool('ga4', 'ga4', true, 'analytics', 'head', 0, ['measurement_id' => 'G-123'], 'yaml'),
        ));
        self::assertStringContainsString('999', (new MetaPixelRenderer())->render(
            new ResolvedTool('meta', 'meta_pixel', true, 'marketing', 'head', 0, ['pixel_id' => '999'], 'yaml'),
        ));
        self::assertStringContainsString('111', (new LinkedInRenderer())->render(
            new ResolvedTool('li', 'linkedin', true, 'marketing', 'head', 0, ['partner_id' => '111'], 'yaml'),
        ));
        self::assertStringContainsString('TT1', (new TikTokRenderer())->render(
            new ResolvedTool('tt', 'tiktok', true, 'marketing', 'head', 0, ['pixel_id' => 'TT1'], 'yaml'),
        ));
        self::assertStringContainsString('123456', (new HotjarRenderer())->render(
            new ResolvedTool('hj', 'hotjar', true, 'analytics', 'head', 0, ['site_id' => '123456'], 'yaml'),
        ));
        self::assertStringContainsString('abc', (new ClarityRenderer())->render(
            new ResolvedTool('cl', 'clarity', true, 'analytics', 'head', 0, ['project_id' => 'abc'], 'yaml'),
        ));
        self::assertSame('<script>x</script>', (new CustomScriptRenderer())->render(
            new ResolvedTool('c', 'custom', true, 'marketing', 'body_end', 0, ['html' => '<script>x</script>'], 'yaml'),
        ));
    }

    public function testEmptyOptionsYieldEmptyHtml(): void
    {
        self::assertSame('', (new GtmRenderer())->render(
            new ResolvedTool('gtm', 'gtm', true, 'analytics', 'head', 0, [], 'yaml'),
        ));
        self::assertSame('', (new HotjarRenderer())->render(
            new ResolvedTool('hj', 'hotjar', true, 'analytics', 'head', 0, ['site_id' => 'abc'], 'yaml'),
        ));
    }

    public function testRegistryDispatches(): void
    {
        $registry = new ToolRendererRegistry([new GtmRenderer(), new CustomScriptRenderer()]);
        $html     = $registry->render(new ResolvedTool('gtm', 'gtm', true, 'analytics', 'head', 0, ['container_id' => 'GTM-Z'], 'yaml'));

        self::assertStringContainsString('GTM-Z', $html);
        self::assertTrue($registry->supports('gtm'));
        self::assertFalse($registry->supports('unknown'));
    }
}
