<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function is_array;

/**
 * Resolves the active marketing profile from YAML and optional Doctrine overrides.
 *
 * Merge rules (CookieConsent-style):
 * - YAML is the baseline for profile enabled flag and tools.
 * - When use_database_config is true and the profile has DB rows, tools are a full replace from DB.
 * - When DB has no rows for the profile, YAML tools are used.
 *
 * Resolved profiles are memoized per main request only: the service may live for many requests
 * (long-running workers without `kernel.reset`), and database edits made by any worker must be
 * visible on the next request. Without a main request (CLI, no RequestStack) nothing is memoized.
 */
final class MarketingConfigResolver implements ResetInterface
{
    /** @var WeakMap<Request, array<string, ResolvedMarketingConfig>> */
    private WeakMap $resolvedByRequest;

    /**
     * @param array<string, array{enabled?: bool, tools?: array<string, array<string, mixed>>}> $profiles
     */
    public function __construct(
        private array $profiles,
        private readonly string $defaultProfile,
        private readonly bool $useDatabaseConfig,
        private readonly ?MarketingToolRepository $toolRepository = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->resolvedByRequest = new WeakMap();
    }

    public function reset(): void
    {
        $this->resolvedByRequest = new WeakMap();
    }

    public function resolve(?string $profile = null): ResolvedMarketingConfig
    {
        $name    = $profile ?? $this->defaultProfile;
        $request = $this->requestStack?->getMainRequest();
        if (!$request instanceof Request) {
            return $this->doResolve($name);
        }

        $memo = $this->resolvedByRequest[$request] ?? [];
        if (!isset($memo[$name])) {
            $memo[$name]                       = $this->doResolve($name);
            $this->resolvedByRequest[$request] = $memo;
        }

        return $memo[$name];
    }

    private function doResolve(string $name): ResolvedMarketingConfig
    {
        $yamlProfile = $this->profiles[$name] ?? null;
        if ($yamlProfile === null) {
            return new ResolvedMarketingConfig($name, false, [], false);
        }

        $enabled = (bool) ($yamlProfile['enabled'] ?? true);
        if (!$enabled) {
            return new ResolvedMarketingConfig($name, false, [], false);
        }

        if ($this->useDatabaseConfig && $this->toolRepository instanceof MarketingToolRepository) {
            $dbRows = $this->toolRepository->findToolRowsByProfile($name);
            if ($dbRows !== []) {
                return new ResolvedMarketingConfig(
                    $name,
                    true,
                    array_map($this->fromRow(...), $dbRows),
                    true,
                );
            }
        }

        /** @var array<string, array<string, mixed>> $yamlTools */
        $yamlTools = $yamlProfile['tools'] ?? [];

        return new ResolvedMarketingConfig(
            $name,
            true,
            $this->fromYamlMap($yamlTools),
            false,
        );
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
