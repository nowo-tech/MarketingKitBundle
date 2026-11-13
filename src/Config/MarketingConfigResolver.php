<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function is_array;
use function trigger_deprecation;

/**
 * Resolves the active marketing profile from YAML and the optional tool source (database by default).
 *
 * Merge rules (CookieConsent-style):
 * - YAML is the baseline for profile enabled flag and tools.
 * - When the {@see MarketingToolSourceInterface} returns tools for the profile, they are a full replace. The default
 *   {@see DoctrineMarketingToolSource} returns the DB rows when use_database_config is true and the profile has rows.
 * - Otherwise (null or an empty list), YAML tools are used.
 *
 * Resolved profiles are memoized per main request only: the service may live for many requests
 * (long-running workers without `kernel.reset`), and database edits made by any worker must be
 * visible on the next request. Without a main request (CLI, no RequestStack) nothing is memoized.
 */
final class MarketingConfigResolver implements MarketingConfigResolverInterface, ResetInterface
{
    /** @var WeakMap<Request, array<string, ResolvedMarketingConfig>> */
    private WeakMap $resolvedByRequest;

    private readonly MarketingToolSourceInterface $toolSource;

    /**
     * @param array<string, array{enabled?: bool, tools?: array<string, array<string, mixed>>}> $profiles
     * @param MarketingToolRepository|null $toolRepository Deprecated since 1.12.0 (removed in 2.0): pass $toolSource; used only when $toolSource is null
     * @param MarketingToolSourceInterface|null $toolSource Since 1.12.0; null keeps the 1.x database override ({@see DoctrineMarketingToolSource} over $toolRepository)
     */
    public function __construct(
        private array $profiles,
        private readonly string $defaultProfile,
        bool $useDatabaseConfig,
        ?MarketingToolRepository $toolRepository = null,
        private readonly ?RequestStack $requestStack = null,
        ?MarketingToolSourceInterface $toolSource = null,
    ) {
        if ($toolRepository !== null) {
            trigger_deprecation('nowo-tech/marketing-kit-bundle', '1.12', 'Passing $toolRepository to "%s" is deprecated and removed in 2.0; when $toolSource is also set (the bundle always sets it) $toolRepository is ignored. Alias "%s" to your own service instead.', self::class, MarketingToolSourceInterface::class);
        }

        $this->resolvedByRequest = new WeakMap();
        $this->toolSource        = $toolSource ?? new DoctrineMarketingToolSource($useDatabaseConfig, $toolRepository);
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

        $sourced = $this->toolSource->toolsFor($name);
        if ($sourced !== null && $sourced !== []) {
            return new ResolvedMarketingConfig($name, true, $sourced, true);
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
}
