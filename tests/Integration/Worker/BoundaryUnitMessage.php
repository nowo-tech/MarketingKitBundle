<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

/**
 * Message of the kept-alive boundary test: unit of work number $index.
 */
final readonly class BoundaryUnitMessage
{
    public function __construct(
        public int $index,
    ) {
    }
}
