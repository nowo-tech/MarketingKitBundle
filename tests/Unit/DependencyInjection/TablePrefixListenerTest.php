<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\MarketingKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use PHPUnit\Framework\TestCase;

final class TablePrefixListenerTest extends TestCase
{
    public function testPrefixesBundleEntityTable(): void
    {
        $listener = new TablePrefixListener('app_');
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(MarketingTool::class);
        $metadata->method('getTableName')->willReturn('marketing_tool');
        $metadata->expects(self::once())->method('setPrimaryTable')->with(['name' => 'app_marketing_tool']);

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        $listener->loadClassMetadata($event);
    }

    public function testIgnoresEmptyPrefix(): void
    {
        $listener = new TablePrefixListener('');
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::never())->method('setPrimaryTable');

        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);

        $listener->loadClassMetadata($event);
    }
}
