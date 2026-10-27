<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Twig;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Twig\MarketingKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\GlobalsInterface;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class MarketingKitExtensionTest extends TestCase
{
    public function testRegistersSafeFunctions(): void
    {
        $extension = new MarketingKitExtension(new MarketingScriptRenderer(
            new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => []]], 'default', false),
            new ToolRendererRegistry([]),
            new CookieConsentGate(new RequestStack(), false),
        ), 'base.html.twig', 'bootstrap5');

        $names = array_map(static fn (TwigFunction $f): string => $f->getName(), $extension->getFunctions());

        self::assertSame(['nowo_marketing_head', 'nowo_marketing_body_start', 'nowo_marketing_body_end'], $names);
        self::assertSame([
            'nowo_marketing_kit_layout'        => 'base.html.twig',
            'nowo_marketing_kit_css_framework' => 'bootstrap5',
        ], $extension->getGlobals());
        self::assertSame('', $extension->head());
        self::assertSame('', $extension->bodyStart());
        self::assertSame('', $extension->bodyEnd());
    }

    public function testNonceIsPassedToTheRenderer(): void
    {
        $extension = new MarketingKitExtension(new MarketingScriptRenderer(
            new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => [
                'gtm' => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'options' => ['container_id' => 'GTM-N1']],
            ]]], 'default', false),
            new ToolRendererRegistry([new GtmRenderer()]),
            new CookieConsentGate(new RequestStack(), false),
        ));

        self::assertStringContainsString('<script nonce="n0nce">', $extension->head(null, 'n0nce'));
        self::assertSame('', $extension->bodyStart(null, 'n0nce'));
        self::assertSame('', $extension->bodyEnd(null, 'n0nce'));
    }

    public function testGlobalsAreExposedToTwigEnvironment(): void
    {
        $extension = new MarketingKitExtension(new MarketingScriptRenderer(
            new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => []]], 'default', false),
            new ToolRendererRegistry([]),
            new CookieConsentGate(new RequestStack(), false),
        ), 'host/layout.html.twig', 'tailwind');

        self::assertContains(GlobalsInterface::class, class_implements($extension));

        $twig = new Environment(new ArrayLoader([
            'probe' => '{{ nowo_marketing_kit_layout|default("unset") }}|{{ nowo_marketing_kit_css_framework|default("unset") }}',
        ]));
        $twig->addExtension($extension);

        self::assertSame('host/layout.html.twig|tailwind', $twig->render('probe'));
    }
}
