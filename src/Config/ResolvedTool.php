<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Config;

/**
 * Immutable resolved tool ready for rendering.
 */
final readonly class ResolvedTool
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $code,
        public string $type,
        public bool $enabled,
        public string $category,
        public string $position,
        public int $sortOrder,
        public array $options,
        public string $source,
    ) {
    }
}
