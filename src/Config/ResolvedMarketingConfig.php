<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

/**
 * Resolved marketing profile (YAML defaults, optionally replaced by Doctrine tools).
 */
final readonly class ResolvedMarketingConfig
{
    /**
     * @param list<ResolvedTool> $tools
     */
    public function __construct(
        public string $profile,
        public bool $enabled,
        public array $tools,
        public bool $fromDatabase,
    ) {
    }

    /**
     * @return list<ResolvedTool>
     */
    public function toolsForPosition(string $position): array
    {
        $filtered = array_values(array_filter(
            $this->tools,
            static fn (ResolvedTool $tool): bool => $tool->enabled && $tool->position === $position,
        ));

        usort(
            $filtered,
            static fn (ResolvedTool $a, ResolvedTool $b): int => $a->sortOrder <=> $b->sortOrder ?: strcmp($a->code, $b->code),
        );

        return $filtered;
    }
}
