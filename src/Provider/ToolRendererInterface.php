<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;

/**
 * Renders HTML for a marketing tool type.
 */
interface ToolRendererInterface
{
    public function supports(string $type): bool;

    public function render(ResolvedTool $tool): string;
}
