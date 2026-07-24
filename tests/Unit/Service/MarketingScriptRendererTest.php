<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Service;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Enum\ConsentCookieNames;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class MarketingScriptRendererTest extends TestCase
{
    public function testRendersOnlyWhenConsentAllows(): void
    {
        $profiles = [
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => [
                        'type'     => 'gtm',
                        'enabled'  => true,
                        'category' => 'analytics',
                        'position' => 'head',
                        'options'  => ['container_id' => 'GTM-OK'],
                    ],
                ],
            ],
        ];

        $request = Request::create('/');
        $stack   = new RequestStack();
        $stack->push($request);
        $renderer = new MarketingScriptRenderer(
            new MarketingConfigResolver($profiles, 'default', false),
            new ToolRendererRegistry([new GtmRenderer()]),
            new CookieConsentGate($stack, true),
        );

        self::assertSame('', $renderer->renderHead());

        $request->cookies->set(ConsentCookieNames::category('analytics'), 'true');
        self::assertStringContainsString('GTM-OK', $renderer->renderHead());
    }
}
