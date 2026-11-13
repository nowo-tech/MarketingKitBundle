<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\MarketingKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(MarketingTool::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(MarketingTool::class);
        $driver->loadMetadataForClass(MarketingTool::class, new ClassMetadata(MarketingTool::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(MarketingTool::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), 'Nowo\\MarketingKitBundle\\Entity');

        self::assertTrue($chain->isTransient(MarketingTool::class));
        self::assertFalse($chain->isTransient(self::class), 'other classes still go to the default driver');
    }
}
