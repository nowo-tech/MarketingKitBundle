<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Service;

/**
 * Renders the consent-gated marketing / analytics tags of a document position (REQ-ARCH-001 rule 1).
 *
 * Default implementation: {@see MarketingScriptRenderer}. The Twig functions `nowo_marketing_head`,
 * `nowo_marketing_body_start` and `nowo_marketing_body_end` call this port; a host may alias it to its own service.
 * Implementations must be worker safe (FrankenPHP worker, kernel kept alive): no request data kept between calls.
 */
interface MarketingScriptRendererInterface
{
    /**
     * HTML of the enabled, consented tools at $position (`head`, `body_start`, `body_end`); '' for an unknown position.
     *
     * @param string|null $profile Profile name; null uses `default_profile`
     * @param string|null $nonce CSP nonce for the built-in providers' `<script>` tags
     */
    public function renderPosition(string $position, ?string $profile = null, ?string $nonce = null): string;

    public function renderHead(?string $profile = null, ?string $nonce = null): string;

    public function renderBodyStart(?string $profile = null, ?string $nonce = null): string;

    public function renderBodyEnd(?string $profile = null, ?string $nonce = null): string;
}
