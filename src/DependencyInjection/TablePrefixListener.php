<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Applies a configurable table prefix to MarketingKit entity metadata.
 */
final readonly class TablePrefixListener
{
    public function __construct(
        private string $tablePrefix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        if ($this->tablePrefix === '') {
            return;
        }

        $metadata = $event->getClassMetadata();
        if (!str_starts_with($metadata->getName(), 'Nowo\\MarketingKitBundle\\Entity\\')) {
            return;
        }

        $metadata->setPrimaryTable([
            'name' => $this->tablePrefix . $metadata->getTableName(),
        ]);
    }
}
