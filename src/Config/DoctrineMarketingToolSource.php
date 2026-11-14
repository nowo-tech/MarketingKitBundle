<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;

use function array_map;

/**
 * Default {@see MarketingToolSourceInterface}: the bundle's `marketing_tool` rows of the profile, when
 * `use_database_config` is on and the profile has rows (the 1.x database override, unchanged).
 */
final readonly class DoctrineMarketingToolSource implements MarketingToolSourceInterface
{
    public function __construct(
        private bool $useDatabaseConfig,
        private ?MarketingToolRepository $toolRepository = null,
    ) {
    }

    public function toolsFor(string $profile): ?array
    {
        if (!$this->useDatabaseConfig || !$this->toolRepository instanceof MarketingToolRepository) {
            return null;
        }

        $rows = $this->toolRepository->findToolRowsByProfile($profile);
        if ($rows === []) {
            return null;
        }

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * @param array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>} $row
     */
    private function fromRow(array $row): ResolvedTool
    {
        return new ResolvedTool(
            code: $row['code'],
            type: $row['type'],
            enabled: $row['enabled'],
            category: $row['category'],
            position: $row['position'],
            sortOrder: $row['sortOrder'],
            options: $row['options'],
            source: 'database',
        );
    }
}
