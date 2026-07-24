<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use PHPUnit\Framework\TestCase;

final class MarketingToolAdminServiceTest extends TestCase
{
    public function testSeedCreatesMissingCatalogEntries(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([]);

        $em        = $this->createMock(EntityManagerInterface::class);
        $persisted = 0;
        $em->expects(self::exactly(9))->method('persist')->willReturnCallback(
            static function (MarketingTool $tool) use (&$persisted): void {
                ++$persisted;
                self::assertFalse($tool->isEnabled());
            },
        );
        $em->expects(self::once())->method('flush');

        $service = new MarketingToolAdminService(
            $repo,
            new MarketingToolCatalog(),
            $em,
            ['default' => ['enabled' => true, 'tools' => []]],
            'default',
        );

        self::assertSame(9, $service->seedCatalog('default'));
        self::assertSame(9, $persisted);
    }

    public function testImportFromYamlUpserts(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = new MarketingToolAdminService(
            $repo,
            new MarketingToolCatalog(),
            $em,
            [
                'default' => [
                    'tools' => [
                        'gtm' => [
                            'type'     => 'gtm',
                            'enabled'  => true,
                            'category' => 'analytics',
                            'position' => 'head',
                            'options'  => ['container_id' => 'GTM-1'],
                        ],
                    ],
                ],
            ],
            'default',
        );

        self::assertSame(1, $service->importFromYaml('default'));
    }

    public function testCatalogLabels(): void
    {
        $catalog = new MarketingToolCatalog();
        self::assertSame('Google Tag Manager', $catalog->labelForCode('gtm'));
        self::assertSame(['container_id'], $catalog->optionKeysForType('gtm'));
        self::assertArrayHasKey('pixel_id', $catalog->optionLabelsForType('meta_pixel'));
    }
}
