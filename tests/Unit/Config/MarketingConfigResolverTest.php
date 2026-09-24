<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Config;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findToolRowsByProfile')->with('default')->willReturn([
            $this->row('meta', 'meta_pixel', ['pixel_id' => '123']),
        ]);

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
        $repo->method('findToolRowsByProfile')->willReturn([]);

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

    public function testMemoizesResolvedProfileWithinMainRequestUntilReset(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        $resolver = new MarketingConfigResolver([
            'default' => [
                'enabled' => true,
                'tools'   => [
                    'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']],
                ],
            ],
        ], 'default', false, null, $stack);

        $first  = $resolver->resolve();
        $second = $resolver->resolve();

        self::assertSame($first, $second);

        $stack->push(Request::create('/_fragment'));
        self::assertSame($first, $resolver->resolve(), 'Sub-requests share the main request memo.');
        $stack->pop();

        $resolver->reset();

        $third = $resolver->resolve();

        self::assertNotSame($first, $third);
        self::assertSame('gtm', $third->tools[0]->code);
    }

    public function testConsecutiveRequestsWithoutResetSeeDatabaseChanges(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->expects(self::exactly(3))->method('findToolRowsByProfile')->with('default')->willReturnOnConsecutiveCalls(
            [$this->row('gtm', 'gtm', ['container_id' => 'GTM-OLD'])],
            [$this->row('gtm', 'gtm', ['container_id' => 'GTM-NEW'], false)],
            [],
        );

        $stack    = new RequestStack();
        $resolver = new MarketingConfigResolver([
            'default' => ['enabled' => true, 'tools' => ['ga4' => ['type' => 'ga4']]],
        ], 'default', true, $repo, $stack);

        // Request 1 (worker A), resolved twice: one query.
        $stack->push(new Request());
        self::assertSame('GTM-OLD', $resolver->resolve()->tools[0]->options['container_id']);
        self::assertSame('GTM-OLD', $resolver->resolve('default')->tools[0]->options['container_id']);
        $stack->pop();

        // Request 2: the admin (possibly on another worker) edited the tool; no reset() in between.
        $stack->push(new Request());
        $second = $resolver->resolve();
        self::assertSame('GTM-NEW', $second->tools[0]->options['container_id']);
        self::assertFalse($second->tools[0]->enabled);
        $stack->pop();

        // Request 3: rows deleted, YAML fallback.
        $stack->push(new Request());
        self::assertFalse($resolver->resolve()->fromDatabase);
        self::assertSame('ga4', $resolver->resolve()->tools[0]->code);
    }

    public function testWithoutMainRequestNothingIsMemoized(): void
    {
        $resolver = new MarketingConfigResolver(['default' => ['enabled' => true, 'tools' => []]], 'default', false);

        self::assertNotSame($resolver->resolve(), $resolver->resolve());
        self::assertNotSame($resolver->resolve('unknown'), $resolver->resolve('unknown'));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>}
     */
    private function row(string $code, string $type, array $options, bool $enabled = true): array
    {
        return [
            'code'      => $code,
            'type'      => $type,
            'enabled'   => $enabled,
            'category'  => 'marketing',
            'position'  => 'head',
            'sortOrder' => 0,
            'options'   => $options,
        ];
    }
}
