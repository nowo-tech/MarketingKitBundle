<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Config;

use Nowo\MarketingKitBundle\Config\ResolvedMarketingConfig;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use PHPUnit\Framework\TestCase;

final class ResolvedMarketingConfigTest extends TestCase
{
    public function testToolsForPositionFiltersAndSorts(): void
    {
        $config = new ResolvedMarketingConfig('default', true, [
            new ResolvedTool('b', 'gtm', true, 'analytics', 'head', 2, [], 'yaml'),
            new ResolvedTool('a', 'gtm', true, 'analytics', 'head', 1, [], 'yaml'),
            new ResolvedTool('c', 'gtm', false, 'analytics', 'head', 0, [], 'yaml'),
            new ResolvedTool('d', 'gtm', true, 'analytics', 'body_end', 0, [], 'yaml'),
        ], false);

        $head = $config->toolsForPosition('head');
        self::assertCount(2, $head);
        self::assertSame('a', $head[0]->code);
        self::assertSame('b', $head[1]->code);
    }
}
