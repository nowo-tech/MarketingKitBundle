<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

/**
 * Resolves the tools of a marketing profile (REQ-ARCH-001 rule 1).
 *
 * Default implementation: {@see MarketingConfigResolver} (YAML baseline, replaced by the tools of the
 * {@see MarketingToolSourceInterface} when it returns some). Returns plain value objects only.
 */
interface MarketingConfigResolverInterface
{
    /**
     * @param string|null $profile Profile name; null uses `default_profile`
     */
    public function resolve(?string $profile = null): ResolvedMarketingConfig;
}
