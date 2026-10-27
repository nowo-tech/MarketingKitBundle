<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_marketing_kit.security.access_roles.
 *
 * Fails closed: an empty role list grants nobody (SEC-004 M1). Use security.allow_unauthenticated (demo only) or a
 * custom security.access_checker for any other policy.
 */
final readonly class ConfigurableMarketingKitAccessChecker implements MarketingKitAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
    ) {
    }

    public function canAccess(): bool
    {
        foreach ($this->accessRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
