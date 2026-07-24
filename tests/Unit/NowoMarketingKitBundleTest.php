<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit;

use Nowo\MarketingKitBundle\NowoMarketingKitBundle;
use PHPUnit\Framework\TestCase;

final class NowoMarketingKitBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        $bundle    = new NowoMarketingKitBundle();
        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('nowo_marketing_kit', $extension->getAlias());
    }
}
