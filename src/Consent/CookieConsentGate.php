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

        return $request->cookies->get(ConsentCookieNames::category($category)) === 'true';
    }
}
