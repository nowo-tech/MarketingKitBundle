<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Config;

use Nowo\MarketingKitBundle\Config\DoctrineMarketingToolSource;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/**
 * The 1.12.0 tool source port keeps the 1.10.x database override exactly (equivalence with the deprecated
 * `$toolRepository` path).
 */
final class DoctrineMarketingToolSourceTest extends TestCase
{
    private const PROFILES = [
        'default' => ['enabled' => true, 'tools' => ['gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']]]],
        'off'     => ['enabled' => false, 'tools' => []],
    ];

    /**
     * @return iterable<string, array{bool, list<array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>}>, string}>
     */
    public static function cases(): iterable
    {
        $rows = [
            ['code' => 'meta', 'type' => 'meta_pixel', 'enabled' => true, 'category' => 'marketing', 'position' => 'head', 'sortOrder' => 2, 'options' => ['pixel_id' => '123']],
            ['code' => 'ga4', 'type' => 'ga4', 'enabled' => false, 'category' => 'analytics', 'position' => 'head', 'sortOrder' => 1, 'options' => ['measurement_id' => 'G-1']],
        ];

        yield 'db on, rows' => [true, $rows, 'default'];
        yield 'db on, no rows' => [true, [], 'default'];
        yield 'db off, rows' => [false, $rows, 'default'];
        yield 'disabled profile' => [true, $rows, 'off'];
        yield 'unknown profile' => [true, $rows, 'missing'];
    }

    /**
     * @param list<array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>}> $rows
     */
    #[DataProvider('cases')]
    #[IgnoreDeprecations]
    public function testSourcePathEqualsTheDeprecatedRepositoryPath(bool $useDatabaseConfig, array $rows, string $profile): void
    {
        $repo = $this->createStub(MarketingToolRepository::class);
        $repo->method('findToolRowsByProfile')->willReturn($rows);

        $this->expectUserDeprecationMessageMatches('/\$toolRepository.*MarketingToolSourceInterface/');
        $legacy = new MarketingConfigResolver(self::PROFILES, 'default', $useDatabaseConfig, $repo);
        $ported = new MarketingConfigResolver(self::PROFILES, 'default', false, null, null, new DoctrineMarketingToolSource($useDatabaseConfig, $repo));

        self::assertEquals($legacy->resolve($profile), $ported->resolve($profile));
    }

    #[IgnoreDeprecations]
    public function testAnOverriddenToolRepositoryIsIgnoredButDeprecated(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->expects(self::never())->method('findToolRowsByProfile');

        $this->expectUserDeprecationMessageMatches('/\$toolRepository.*ignored.*MarketingToolSourceInterface/');
        $host = new class implements MarketingToolSourceInterface {
            public function toolsFor(string $profile): ?array
            {
                return null;
            }
        };
        $resolver = new MarketingConfigResolver(self::PROFILES, 'default', true, $repo, null, $host);

        self::assertSame('gtm', $resolver->resolve('default')->tools[0]->code);
    }

    public function testReturnsDatabaseToolsInRepositoryOrder(): void
    {
        $repo = $this->createStub(MarketingToolRepository::class);
        $repo->method('findToolRowsByProfile')->willReturn([
            ['code' => 'gtm', 'type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sortOrder' => 0, 'options' => ['container_id' => 'GTM-9']],
        ]);

        self::assertEquals(
            [new ResolvedTool('gtm', 'gtm', true, 'analytics', 'head', 0, ['container_id' => 'GTM-9'], 'database')],
            (new DoctrineMarketingToolSource(true, $repo))->toolsFor('default'),
        );
    }

    public function testNullWithoutDatabaseConfigRepositoryOrRows(): void
    {
        $empty = $this->createStub(MarketingToolRepository::class);
        $empty->method('findToolRowsByProfile')->willReturn([]);

        self::assertNull((new DoctrineMarketingToolSource(false, $empty))->toolsFor('default'));
        self::assertNull((new DoctrineMarketingToolSource(true))->toolsFor('default'));
        self::assertNull((new DoctrineMarketingToolSource(true, $empty))->toolsFor('default'));
    }

    public function testHostSourceReplacesYamlAndEmptyListKeepsYaml(): void
    {
        $host = new class implements MarketingToolSourceInterface {
            /** @var list<ResolvedTool>|null */
            public ?array $tools = null;

            public function toolsFor(string $profile): ?array
            {
                return $this->tools;
            }
        };

        $resolver = new MarketingConfigResolver(self::PROFILES, 'default', true, null, null, $host);
        self::assertFalse($resolver->resolve()->fromDatabase);
        self::assertSame('yaml', $resolver->resolve()->tools[0]->source);

        $host->tools = [];
        self::assertSame('yaml', $resolver->resolve()->tools[0]->source);

        $host->tools = [new ResolvedTool('ga4', 'ga4', true, 'analytics', 'head', 0, ['measurement_id' => 'G-H'], 'host')];
        self::assertTrue($resolver->resolve()->fromDatabase);
        self::assertSame('host', $resolver->resolve()->tools[0]->source);
    }
}
