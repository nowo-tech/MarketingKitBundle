<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\HostPorts;

use Nowo\MarketingKitBundle\Config\DoctrineMarketingToolSource;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolverInterface;
use Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface;
use Nowo\MarketingKitBundle\Provider\ToolRendererInterface;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Service\MarketingScriptRendererInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Traversable;
use Twig\Environment;

use function array_map;
use function array_unique;
use function array_values;
use function iterator_to_array;

/**
 * REQ-ARCH-001 rules 1 and 8 (1.12.0): the ports are aliased by default and a host replaces them with one
 * `services` alias each.
 */
final class HostPortsTest extends TestCase
{
    private ?HostPortsKernel $kernel = null;

    protected function tearDown(): void
    {
        $dir = $this->kernel?->getCacheDir();
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($dir !== null) {
            (new Filesystem())->remove($dir);
        }
        parent::tearDown();
    }

    public function testDefaultAliasesPointAtTheBundleServices(): void
    {
        $container = $this->boot('default');

        self::assertInstanceOf(MarketingConfigResolver::class, $container->get('test.marketing_resolver'));
        self::assertInstanceOf(MarketingScriptRenderer::class, $container->get('test.marketing_renderer'));
        self::assertInstanceOf(DoctrineMarketingToolSource::class, $container->get('test.marketing_source'));
    }

    public function testHostToolSourceReplacesYamlWithoutTouchingTheDatabase(): void
    {
        $container = $this->boot('source');

        $source = $container->get('test.marketing_source');
        self::assertInstanceOf(MarketingToolSourceInterface::class, $source);
        self::assertInstanceOf(HostToolSource::class, $source);

        $resolver = $container->get('test.marketing_resolver');
        self::assertInstanceOf(MarketingConfigResolverInterface::class, $resolver);
        $resolved = $resolver->resolve();
        self::assertTrue($resolved->fromDatabase);
        self::assertSame('host', $resolved->tools[0]->source);

        $renderer = $container->get('test.marketing_renderer');
        self::assertInstanceOf(MarketingScriptRendererInterface::class, $renderer);
        $head = $renderer->renderHead();
        self::assertStringContainsString('G-HOST0001', $head);
        self::assertStringNotContainsString('G-YAML0001', $head);
    }

    public function testHostRendererIsUsedByTheTwigFunctions(): void
    {
        $container = $this->boot('renderer');

        $twig = $container->get('test.twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate('{{ nowo_marketing_head() }}|{{ nowo_marketing_body_start() }}|{{ nowo_marketing_body_end() }}')->render();

        self::assertSame('<!-- host:head -->|<!-- host:body_start -->|<!-- host:body_end -->', $html);
    }

    public function testAutoconfiguredToolRendererJoinsTheRegistry(): void
    {
        $container = $this->boot('tool-renderer');

        $registry = $container->get('test.tool_registry');
        self::assertInstanceOf(ToolRendererRegistry::class, $registry);
        $renderers = (new ReflectionProperty($registry, 'renderers'))->getValue($registry);
        self::assertInstanceOf(Traversable::class, $renderers);
        self::assertContainsOnlyInstancesOf(ToolRendererInterface::class, $renderers);
        $classes = array_map(static fn (object $r): string => $r::class, iterator_to_array($renderers, false));
        self::assertContains(HostToolRenderer::class, $classes);
        self::assertSame($classes, array_values(array_unique($classes)), 'autoconfiguration must not register a built-in renderer twice');
    }

    private function boot(string $mode): ContainerInterface
    {
        $this->kernel = new HostPortsKernel($mode);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }
}
