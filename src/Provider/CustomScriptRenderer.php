<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function is_string;

/**
 * Arbitrary HTML/JS snippet. Options: html (required).
 *
 * The snippet is emitted as configured by the integrator (trusted admin/YAML only).
 */
final class CustomScriptRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::Custom->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $html = $tool->options['html'] ?? '';
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        return $html;
    }
}
