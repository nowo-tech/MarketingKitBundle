<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Twig;

use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers: nowo_marketing_head / body_start / body_end.
 */
final class MarketingKitExtension extends AbstractExtension
{
    public function __construct(
        private readonly MarketingScriptRenderer $renderer,
        private readonly string $layoutTemplate = '@NowoMarketingKitBundle/admin/layout.html.twig',
        private readonly string $cssFramework = 'none',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_marketing_head', $this->head(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_marketing_body_start', $this->bodyStart(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_marketing_body_end', $this->bodyEnd(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return array{nowo_marketing_kit_layout: string, nowo_marketing_kit_css_framework: string}
     */
    public function getGlobals(): array
    {
        return [
            'nowo_marketing_kit_layout'        => $this->layoutTemplate,
            'nowo_marketing_kit_css_framework' => $this->cssFramework,
        ];
    }

    public function head(?string $profile = null): string
    {
        return $this->renderer->renderHead($profile);
    }

    public function bodyStart(?string $profile = null): string
    {
        return $this->renderer->renderBodyStart($profile);
    }

    public function bodyEnd(?string $profile = null): string
    {
        return $this->renderer->renderBodyEnd($profile);
    }
}
