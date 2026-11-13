<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

/**
 * Provider port for the tools of a profile that replace the YAML ones (REQ-ARCH-001 rule 8).
 *
 * The default {@see DoctrineMarketingToolSource} reads the bundle's `marketing_tool` table when `use_database_config`
 * is on. A host that keeps its tracking settings elsewhere (its own aggregate, a settings service, an API) aliases
 * this interface to its own service; Doctrine is then never queried for configuration.
 *
 * Implementations must be worker safe (FrankenPHP worker, kernel kept alive): read per call, no cache across requests
 * unless it is reset at the request boundary. The resolver already memoizes per main request.
 */
interface MarketingToolSourceInterface
{
    /**
     * Tools that replace the YAML tools of $profile, or null to keep the YAML ones.
     *
     * An empty list is treated like null (YAML stays), as the database source has always done for a profile without rows.
     *
     * @return list<ResolvedTool>|null
     */
    public function toolsFor(string $profile): ?array;
}
