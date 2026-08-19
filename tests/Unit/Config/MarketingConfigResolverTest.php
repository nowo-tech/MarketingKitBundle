<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Config;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use PHPUnit\Framework\TestCase;

final class MarketingConfigResolverTest extends TestCase
{
    public function testResolvesYamlTools(): void
    {
        $resolver = new MarketingConfigResolver([
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => [
                        'type'       => 'gtm',
                        'enabled'    => true,
                        'category'   => 'analytics',
                        'position'   => 'head',
                        'sort_order' => 1,
                        'options'    => ['container_id' => 'GTM-1'],
                    ],
                ],
            ],
        ], 'default', false);

        $resolved = $resolver->resolve();

        self::assertTrue($resolved->enabled);
        self::assertFalse($resolved->fromDatabase);
        self::assertCount(1, $resolved->tools);
        self::assertSame('gtm', $resolved->tools[0]->code);
        self::assertSame('yaml', $resolved->tools[0]->source);
    }

    public function testDatabaseReplaceWhenRowsExist(): void
    {
        $entity = (new MarketingTool())
            ->setProfile('default')
            ->setCode('meta')
            ->setType('meta_pixel')
            ->setCategory('marketing')
            ->setPosition('head')
            ->setOptions(['pixel_id' => '123']);

        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->with('default')->willReturn([$entity]);

        $resolver = new MarketingConfigResolver([
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']],
                ],
            ],
        ], 'default', true, $repo);

        $resolved = $resolver->resolve();

        self::assertTrue($resolved->fromDatabase);
        self::assertCount(1, $resolved->tools);
        self::assertSame('meta', $resolved->tools[0]->code);
        self::assertSame('database', $resolved->tools[0]->source);
    }

    public function testFallsBackToYamlWhenDbEmpty(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([]);

        $resolver = new MarketingConfigResolver([
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']],
                ],
            ],
        ], 'default', true, $repo);

        $resolved = $resolver->resolve();

        self::assertFalse($resolved->fromDatabase);
        self::assertSame('gtm', $resolved->tools[0]->code);
    }

    public function testDisabledProfile(): void
    {
        $resolver = new MarketingConfigResolver([
            'default' => ['enabled' => false, 'tools' => []],
        ], 'default', false);

        $resolved = $resolver->resolve();

        self::assertFalse($resolved->enabled);
        self::assertSame([], $resolved->tools);
    }

    public function testMemoizesResolvedProfileUntilReset(): void
    {
        $resolver = new MarketingConfigResolver([
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']],
                ],
            ],
        ], 'default', false);

        $first = $resolver->resolve();
        $second = $resolver->resolve();

        self::assertSame($first, $second);

        $resolver->reset();

        $third = $resolver->resolve();

        self::assertNotSame($first, $third);
        self::assertSame('gtm', $third->tools[0]->code);
    }
}
