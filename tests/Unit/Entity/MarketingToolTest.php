<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Entity;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use PHPUnit\Framework\TestCase;

final class MarketingToolTest extends TestCase
{
    public function testGettersSetters(): void
    {
        $tool = (new MarketingTool())
            ->setProfile('default')
            ->setCode('gtm')
            ->setType('gtm')
            ->setEnabled(true)
            ->setCategory('analytics')
            ->setPosition('head')
            ->setSortOrder(2)
            ->setOptions(['container_id' => 'GTM-1']);

        self::assertNull($tool->getId());
        self::assertSame('default', $tool->getProfile());
        self::assertSame('gtm', $tool->getCode());
        self::assertSame('gtm', $tool->getType());
        self::assertTrue($tool->isEnabled());
        self::assertSame('analytics', $tool->getCategory());
        self::assertSame('head', $tool->getPosition());
        self::assertSame(2, $tool->getSortOrder());
        self::assertSame(['container_id' => 'GTM-1'], $tool->getOptions());
    }
}
