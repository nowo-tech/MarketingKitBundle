<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Service;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Consent\ConsentGateInterface;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;

use function in_array;

/**
 * Builds HTML for marketing tools at a document position, gated by consent.
 */
final readonly class MarketingScriptRenderer
{
    public function __construct(
        private MarketingConfigResolver $configResolver,
        private ToolRendererRegistry $rendererRegistry,
        private ConsentGateInterface $consentGate,
    ) {
    }

    public function renderPosition(string $position, ?string $profile = null): string
    {
        if (!in_array($position, ToolPosition::values(), true)) {
            return '';
        }

        $config = $this->configResolver->resolve($profile);
        if (!$config->enabled) {
            return '';
        }

        $parts = [];
        foreach ($config->toolsForPosition($position) as $tool) {
            if (!$this->shouldRender($tool)) {
                continue;
            }
            $html = $this->rendererRegistry->render($tool);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        return implode("\n", $parts);
    }

    public function renderHead(?string $profile = null): string
    {
        return $this->renderPosition(ToolPosition::Head->value, $profile);
    }

    public function renderBodyStart(?string $profile = null): string
    {
        return $this->renderPosition(ToolPosition::BodyStart->value, $profile);
    }

    public function renderBodyEnd(?string $profile = null): string
    {
        return $this->renderPosition(ToolPosition::BodyEnd->value, $profile);
    }

    private function shouldRender(ResolvedTool $tool): bool
    {
        return $this->consentGate->isCategoryAllowed($tool->category);
    }
}
