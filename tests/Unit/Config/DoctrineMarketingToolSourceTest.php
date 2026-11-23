<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Config;

use Nowo\MarketingKitBundle\Config\DoctrineMarketingToolSource;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

use function array_map;

/**
 * The 1.12.0 tool source port: the bundle's database override (the 1.10.x behaviour), the only path since 2.0.
 */
final class DoctrineMarketingToolSourceTest extends TestCase
{
    private const PROFILES = [
        'default' => ['enabled' => true, 'tools' => ['gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']]]],
        'off'     => ['enabled' => false, 'tools' => []],
    ];

    /**
     * 2.0: the fourth constructor parameter of 1.x (`?MarketingToolRepository $toolRepository`, deprecated since 1.12) is
     * gone; `$requestStack` and `$toolSource` moved up one position, and named arguments keep working.
     */
    public function testTheDeprecatedRepositoryParameterIsRemoved(): void
    {
        $parameters = (new ReflectionMethod(MarketingConfigResolver::class, '__construct'))->getParameters();

        self::assertSame(['profiles', 'defaultProfile', 'useDatabaseConfig', 'requestStack', 'toolSource'], array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters));

        $repo = $this->createStub(MarketingToolRepository::class);
        $repo->method('findToolRowsByProfile')->willReturn([
            ['code' => 'ga4', 'type' => 'ga4', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sortOrder' => 0, 'options' => ['measurement_id' => 'G-1']],
        ]);
        $resolver = new MarketingConfigResolver(self::PROFILES, 'default', true, toolSource: new DoctrineMarketingToolSource(true, $repo));

        self::assertSame('ga4', $resolver->resolve()->tools[0]->code);
        self::assertSame('gtm', (new MarketingConfigResolver(self::PROFILES, 'default', true))->resolve()->tools[0]->code, 'no tool source: YAML only');
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

        $resolver = new MarketingConfigResolver(self::PROFILES, 'default', true, null, $host);
        self::assertFalse($resolver->resolve()->fromDatabase);
        self::assertSame('yaml', $resolver->resolve()->tools[0]->source);

        $host->tools = [];
        self::assertSame('yaml', $resolver->resolve()->tools[0]->source);

        $host->tools = [new ResolvedTool('ga4', 'ga4', true, 'analytics', 'head', 0, ['measurement_id' => 'G-H'], 'host')];
        self::assertTrue($resolver->resolve()->fromDatabase);
        self::assertSame('host', $resolver->resolve()->tools[0]->source);
    }
}
