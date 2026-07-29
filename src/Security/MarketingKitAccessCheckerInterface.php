<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Security;

/**
 * Decides whether the current request may access MarketingKit admin CRUD (REQ-UI-002).
 */
interface MarketingKitAccessCheckerInterface
{
    public function canAccess(): bool;
}
