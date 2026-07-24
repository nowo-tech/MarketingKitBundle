<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Consent;

/**
 * Decides whether a consent category is allowed for the current request.
 */
interface ConsentGateInterface
{
    public function isCategoryAllowed(string $category): bool;
}
