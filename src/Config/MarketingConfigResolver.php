<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;

use function is_array;

/**
 * Resolves the active marketing profile from YAML and optional Doctrine overrides.
 *
 * Merge rules (CookieConsent-style):
 * - YAML is the baseline for profile enabled flag and tools.
 * - When use_database_config is true and the profile has DB rows, tools are a full replace from DB.
 * - When DB has no rows for the profile, YAML tools are used.
 */
final readonly class MarketingConfigResolver
{
    /**
     * @param array<string, array{enabled?: bool, tools?: array<string, array<string, mixed>>}> $profiles
     */
    public function __construct(
        private array $profiles,
        private string $defaultProfile,
        private bool $useDatabaseConfig,
        private ?MarketingToolRepository $toolRepository = null,
    ) {
    }

    public function resolve(?string $profile = null): ResolvedMarketingConfig
    {
        $name        = $profile ?? $this->defaultProfile;
        $yamlProfile = $this->profiles[$name] ?? null;
        if ($yamlProfile === null) {
            return new ResolvedMarketingConfig($name, false, [], false);
        }

        $enabled = (bool) ($yamlProfile['enabled'] ?? true);
        if (!$enabled) {
            return new ResolvedMarketingConfig($name, false, [], false);
        }

        if ($this->useDatabaseConfig && $this->toolRepository instanceof MarketingToolRepository) {
            $dbTools = $this->toolRepository->findByProfileOrdered($name);
            if ($dbTools !== []) {
                return new ResolvedMarketingConfig(
                    $name,
                    true,
                    array_map($this->fromEntity(...), $dbTools),
                    true,
                );
            }
        }

        /** @var array<string, array<string, mixed>> $yamlTools */
        $yamlTools = $yamlProfile['tools'] ?? [];

        return new ResolvedMarketingConfig($name, true, $this->fromYamlMap($yamlTools), false);
    }

    /**
     * @param array<string, array<string, mixed>> $tools
     *
     * @return list<ResolvedTool>
     */
    private function fromYamlMap(array $tools): array
    {
        $resolved = [];
        foreach ($tools as $code => $tool) {
            $resolved[] = new ResolvedTool(
                code: (string) $code,
                type: (string) ($tool['type'] ?? 'custom'),
                enabled: (bool) ($tool['enabled'] ?? true),
                category: (string) ($tool['category'] ?? 'marketing'),
                position: (string) ($tool['position'] ?? 'head'),
                sortOrder: (int) ($tool['sort_order'] ?? 0),
                options: is_array($tool['options'] ?? null) ? $tool['options'] : [],
                source: 'yaml',
            );
        }

        usort(
            $resolved,
            static fn (ResolvedTool $a, ResolvedTool $b): int => $a->sortOrder <=> $b->sortOrder ?: strcmp($a->code, $b->code),
        );

        return $resolved;
    }

    private function fromEntity(MarketingTool $tool): ResolvedTool
    {
        return new ResolvedTool(
            code: $tool->getCode(),
            type: $tool->getType(),
            enabled: $tool->isEnabled(),
            category: $tool->getCategory(),
            position: $tool->getPosition(),
            sortOrder: $tool->getSortOrder(),
            options: $tool->getOptions(),
            source: 'database',
        );
    }
}
