<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\HostPorts;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Provider\ToolRendererInterface;

/**
 * Host renderer registered with plain autoconfiguration (no explicit tag).
 */
final class HostToolRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === 'ga4';
    }

    public function render(ResolvedTool $tool): string
    {
        return '<!-- host-tool:' . $tool->code . ' -->';
    }
}
