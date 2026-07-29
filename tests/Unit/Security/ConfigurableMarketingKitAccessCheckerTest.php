<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Security;

use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ConfigurableMarketingKitAccessCheckerTest extends TestCase
{
    public function testCanAccessReturnsTrueWhenAnyRoleIsGranted(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker
            ->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnMap([
                ['ROLE_EDITOR', false],
                ['ROLE_ADMIN', true],
            ]);

        $checker = new ConfigurableMarketingKitAccessChecker($authorizationChecker, ['ROLE_EDITOR', 'ROLE_ADMIN']);

        self::assertTrue($checker->canAccess());
    }

    public function testCanAccessReturnsFalseWhenNoRoleIsGranted(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker
            ->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturn(false);

        $checker = new ConfigurableMarketingKitAccessChecker($authorizationChecker, ['ROLE_EDITOR', 'ROLE_ADMIN']);

        self::assertFalse($checker->canAccess());
    }

    public function testCanAccessReturnsTrueWhenAccessRolesAreEmpty(): void
    {
        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::never())->method('isGranted');

        $checker = new ConfigurableMarketingKitAccessChecker($authorizationChecker, []);

        self::assertTrue($checker->canAccess());
    }
}
