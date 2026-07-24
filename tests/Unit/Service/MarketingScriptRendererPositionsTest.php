<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Service;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class MarketingScriptRendererPositionsTest extends TestCase
{
    public function testBodyHelpersAndInvalidPosition(): void
    {
        $profiles = [
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => [
                        'type'     => 'gtm',
                        'enabled'  => true,
                        'category' => 'required',
                        'position' => 'body_start',
                        'options'  => ['container_id' => 'GTM-B'],
                    ],
                    'gtm_end' => [
                        'type'     => 'gtm',
                        'enabled'  => true,
                        'category' => 'required',
                        'position' => 'body_end',
                        'options'  => ['container_id' => 'GTM-E'],
                    ],
                ],
            ],
        ];

        $renderer = new MarketingScriptRenderer(
            new MarketingConfigResolver($profiles, 'default', false),
            new ToolRendererRegistry([new GtmRenderer()]),
            new CookieConsentGate(new RequestStack(), false),
        );

        self::assertStringContainsString('GTM-B', $renderer->renderBodyStart());
        self::assertStringContainsString('GTM-E', $renderer->renderBodyEnd());
        self::assertSame('', $renderer->renderPosition('nope'));
    }
}
