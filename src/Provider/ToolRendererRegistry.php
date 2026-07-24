<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

/**
 * Dispatches rendering to the first supporting ToolRendererInterface.
 */
final readonly class ToolRendererRegistry
{
    /**
     * @param iterable<ToolRendererInterface> $renderers
     */
    public function __construct(
        private iterable $renderers,
    ) {
    }

    public function render(ResolvedTool $tool): string
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($tool->type)) {
                return $renderer->render($tool);
            }
        }

        return '';
    }

    public function supports(string $type): bool
    {
        if (ToolType::tryFrom($type) === null) {
            return false;
        }

        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($type)) {
                return true;
            }
        }

        return false;
    }
}
