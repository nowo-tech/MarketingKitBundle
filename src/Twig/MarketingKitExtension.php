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
