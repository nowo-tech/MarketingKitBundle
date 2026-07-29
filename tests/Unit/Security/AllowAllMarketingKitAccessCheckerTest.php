<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Security;

use Nowo\MarketingKitBundle\Security\AllowAllMarketingKitAccessChecker;
use PHPUnit\Framework\TestCase;

final class AllowAllMarketingKitAccessCheckerTest extends TestCase
{
    public function testCanAccessAlwaysReturnsTrue(): void
    {
        self::assertTrue((new AllowAllMarketingKitAccessChecker())->canAccess());
    }
}
