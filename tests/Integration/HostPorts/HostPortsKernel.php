<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\HostPorts;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolverInterface;
use Nowo\MarketingKitBundle\Config\MarketingToolSourceInterface;
use Nowo\MarketingKitBundle\NowoMarketingKitBundle;
use Nowo\MarketingKitBundle\Service\MarketingScriptRendererInterface;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * Host kernel for the REQ-ARCH-001 ports (1.11.0). Mode "default" keeps the bundle wiring; "source" aliases
 * {@see MarketingToolSourceInterface} to a host source (the `marketing_tool` table is never created, so a database
 * read would fail); "renderer" aliases {@see MarketingScriptRendererInterface} to a host renderer. All aliases are
 * the plain `services` lines a host writes in config/services.yaml.
 */
final class HostPortsKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $mode)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new NowoMarketingKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/host-ports-' . $this->mode;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'host-ports-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'router'                => ['utf8' => true],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'form'                  => ['enabled' => true],
            'csrf_protection'       => ['enabled' => true],
            'php_errors'            => ['log' => true],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [],
        ]);
        $container->extension('nowo_marketing_kit', [
            'use_database_config'    => true,
            'respect_cookie_consent' => false,
            'security'               => ['allow_unauthenticated' => true],
            'profiles'               => [
                'default' => ['tools' => [
                    'ga4' => ['type' => 'ga4', 'category' => 'analytics', 'options' => ['measurement_id' => 'G-YAML0001']],
                ]],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->alias('test.marketing_resolver', MarketingConfigResolverInterface::class)->public();
        $services->alias('test.marketing_renderer', MarketingScriptRendererInterface::class)->public();
        $services->alias('test.marketing_source', MarketingToolSourceInterface::class)->public();
        $services->alias('test.twig', 'twig')->public();

        if ($this->mode === 'source') {
            $services->set(HostToolSource::class);
            $services->alias(MarketingToolSourceInterface::class, HostToolSource::class);
        }
        if ($this->mode === 'renderer') {
            $services->set(HostScriptRenderer::class);
            $services->alias(MarketingScriptRendererInterface::class, HostScriptRenderer::class);
        }
    }
}
