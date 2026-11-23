<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Twig;

use Nowo\MarketingKitBundle\Service\MarketingScriptRendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers: nowo_marketing_head / body_start / body_end.
 *
 * 2.0: the Twig globals `nowo_marketing_kit_layout` and `nowo_marketing_kit_css_framework` (deprecated since 1.9,
 * unused by the admin pages) were removed; use UiKit's `nowo_ui_layout` and `nowo_ui_kit.*` instead.
 */
final class MarketingKitExtension extends AbstractExtension
{
    /**
     * @param string|null $layoutTemplate deprecated since 2.0, ignored (it fed the removed global); removed in 3.0
     * @param string $cssFramework deprecated since 2.0, ignored (it fed the removed global); removed in 3.0
     */
    public function __construct(// @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter (BC: kept until 3.0)
        private readonly MarketingScriptRendererInterface $renderer,
        ?string $layoutTemplate = null,
        string $cssFramework = 'none',
    ) {
        unset($layoutTemplate, $cssFramework);
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
     * @param string|null $nonce CSP script nonce for the built-in tags (e.g. `csp_nonce('script')`)
     */
    public function head(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderer->renderHead($profile, $nonce);
    }

    /**
     * @param string|null $nonce CSP script nonce for the built-in tags (e.g. `csp_nonce('script')`)
     */
    public function bodyStart(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderer->renderBodyStart($profile, $nonce);
    }

    /**
     * @param string|null $nonce CSP script nonce for the built-in tags (e.g. `csp_nonce('script')`)
     */
    public function bodyEnd(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderer->renderBodyEnd($profile, $nonce);
    }
}
