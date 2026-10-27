<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Service;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Enum\ConsentCookieNames;
use Nowo\MarketingKitBundle\Provider\CustomScriptRenderer;
use Nowo\MarketingKitBundle\Provider\GtmRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function substr_count;

/**
 * SEC-004 L2 (no consent bypass for built-in trackers in `required`) and L4 (CSP nonce on built-in scripts).
 */
final class MarketingScriptRendererHardeningTest extends TestCase
{
    public function testRequiredTrackerIsGatedAsMarketingWithoutTheOptIn(): void
    {
        $request = Request::create('/');
        self::assertSame('', $this->renderer($request)->renderHead());

        $request->cookies->set(ConsentCookieNames::category('marketing'), 'true');
        self::assertStringContainsString('GTM-REQ1', $this->renderer($request)->renderHead());
    }

    public function testRequiredTrackerBypassesConsentWithTheOptIn(): void
    {
        self::assertStringContainsString('GTM-REQ1', $this->renderer(Request::create('/'), allowRequired: true)->renderHead());
    }

    public function testRequiredCustomSnippetStillBypassesConsent(): void
    {
        $html = $this->renderer(Request::create('/'))->renderBodyEnd();

        self::assertSame('<script>custom()</script>', $html);
    }

    public function testExplicitNonceIsAddedToBuiltInScriptsOnly(): void
    {
        $renderer = $this->renderer(Request::create('/'), allowRequired: true);

        self::assertStringContainsString('<script nonce="abc+/=_-123">', $renderer->renderHead(null, 'abc+/=_-123'));
        self::assertStringNotContainsString('<script nonce="abc+/=_-123">', $renderer->renderBodyStart(null, 'abc+/=_-123'));
        self::assertSame('<script>custom()</script>', $renderer->renderBodyEnd(null, 'abc123'));
    }

    public function testNonceFromRequestAttribute(): void
    {
        $request = Request::create('/');
        $request->attributes->set('csp_script_nonce', 'fromAttr42');

        self::assertStringContainsString('<script nonce="fromAttr42">', $this->renderer($request, true, 'csp_script_nonce')->renderHead());
        self::assertStringContainsString('<script nonce="explicit">', $this->renderer($request, true, 'csp_script_nonce')->renderHead(null, 'explicit'));
    }

    public function testInvalidOrMissingNonceIsIgnored(): void
    {
        $request = Request::create('/');
        $request->attributes->set('csp_script_nonce', ['not-a-string']);

        self::assertStringNotContainsString('nonce=', $this->renderer($request, true, 'csp_script_nonce')->renderHead());
        self::assertStringNotContainsString('nonce=', $this->renderer($request, true)->renderHead(null, '"><script>'));
        self::assertStringNotContainsString('nonce=', (new MarketingScriptRenderer(
            new MarketingConfigResolver($this->profiles(), 'default', false),
            new ToolRendererRegistry([new GtmRenderer()]),
            new CookieConsentGate(new RequestStack(), false),
            null,
            true,
            'csp_script_nonce',
        ))->renderHead());
    }

    public function testToolRenderingNothingIsSkipped(): void
    {
        $renderer = new MarketingScriptRenderer(
            new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => [
                'gtm'   => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'options' => ['container_id' => 'bad id']],
                'gtm_2' => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'options' => ['container_id' => 'GTM-OK2']],
            ]]], 'default', false),
            new ToolRendererRegistry([new GtmRenderer()]),
            new CookieConsentGate(new RequestStack(), false),
        );

        $html = $renderer->renderHead(null, 'n1');
        self::assertSame(1, substr_count($html, '<script nonce="n1">'));
        self::assertStringNotContainsString("\n", $html);
    }

    private function renderer(Request $request, bool $allowRequired = false, ?string $nonceAttribute = null): MarketingScriptRenderer
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new MarketingScriptRenderer(
            new MarketingConfigResolver($this->profiles(), 'default', false),
            new ToolRendererRegistry([new GtmRenderer(), new CustomScriptRenderer(true)]),
            new CookieConsentGate($stack, true),
            $stack,
            $allowRequired,
            $nonceAttribute,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profiles(): array
    {
        return [
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm'          => ['type' => 'gtm', 'enabled' => true, 'category' => 'required', 'position' => 'head', 'options' => ['container_id' => 'GTM-REQ1']],
                    'gtm_noscript' => ['type' => 'gtm', 'enabled' => true, 'category' => 'required', 'position' => 'body_start', 'options' => ['container_id' => 'GTM-REQ1']],
                    'custom'       => ['type' => 'custom', 'enabled' => true, 'category' => 'required', 'position' => 'body_end', 'options' => ['html' => '<script>custom()</script>']],
                ],
            ],
        ];
    }
}
