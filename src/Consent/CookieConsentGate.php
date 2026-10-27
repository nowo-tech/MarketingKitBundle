<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Consent;

use Nowo\MarketingKitBundle\Enum\ConsentCookieNames;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * CookieConsent-compatible gate: reads Cookie_Category_{category} === 'true'.
 *
 * Works with or without nowo-tech/cookie-consent-bundle installed.
 */
final readonly class CookieConsentGate implements ConsentGateInterface
{
    /**
     * Request attribute set when the response depends on the consent cookies: ConsentVaryCookieSubscriber then adds
     * `Vary: Cookie`, so a shared cache never serves one visitor's tags to another (SEC-004 I3).
     */
    public const VARY_COOKIE_ATTRIBUTE = '_nowo_marketing_kit_vary_cookie';

    public function __construct(
        private RequestStack $requestStack,
        private bool $respectCookieConsent = true,
    ) {
    }

    public function isCategoryAllowed(string $category): bool
    {
        if (!$this->respectCookieConsent) {
            return true;
        }

        if ($category === 'required') {
            return true;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return false;
        }
        // @igor-ignore - per-request attribute on the current Request, not service state
        $request->attributes->set(self::VARY_COOKIE_ATTRIBUTE, true);

        return $request->cookies->get(ConsentCookieNames::category($category)) === 'true';
    }
}
