<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Twig;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Twig\MarketingKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\TwigFunction;

final class MarketingKitExtensionTest extends TestCase
{
    public function testRegistersSafeFunctions(): void
    {
        $extension = new MarketingKitExtension(new MarketingScriptRenderer(
            new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => []]], 'default', false),
            new ToolRendererRegistry([]),
            new CookieConsentGate(new RequestStack(), false),
        ));

        $names = array_map(static fn (TwigFunction $f): string => $f->getName(), $extension->getFunctions());

        self::assertSame(['nowo_marketing_head', 'nowo_marketing_body_start', 'nowo_marketing_body_end'], $names);
        self::assertSame('', $extension->head());
        self::assertSame('', $extension->bodyStart());
        self::assertSame('', $extension->bodyEnd());
    }
}
