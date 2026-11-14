<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\HostPorts;

use Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface;
use Nowo\MarketingKitBundle\Config\ResolvedTool;

/**
 * Host tool source (e.g. a DDD host's own settings aggregate): plain PHP, no Doctrine.
 */
final class HostToolSource implements MarketingToolSourceInterface
{
    public function toolsFor(string $profile): ?array
    {
        if ($profile !== 'default') {
            return null;
        }

        return [new ResolvedTool('ga4', 'ga4', true, 'analytics', 'head', 0, ['measurement_id' => 'G-HOST0001'], 'host')];
    }
}
