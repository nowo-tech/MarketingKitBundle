<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Enum;

use Nowo\MarketingKitBundle\Enum\ConsentCookieNames;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    public function testValues(): void
    {
        self::assertContains('gtm', ToolType::values());
        self::assertContains('custom', ToolType::values());
        self::assertContains('head', ToolPosition::values());
        self::assertSame('Cookie_Category_marketing', ConsentCookieNames::category('marketing'));
    }
}
