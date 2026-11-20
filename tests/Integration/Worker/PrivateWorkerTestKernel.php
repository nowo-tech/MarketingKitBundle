<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\MarketingKitBundle\NowoMarketingKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * 1.14: the host of {@see WorkerTestKernel} with `doctrine.private_entity_manager: true` (the 2.0 default). The default
 * manager also maps a host entity (HostNote), whose pending work a marketing tool write must never flush.
 */
final class PrivateWorkerTestKernel extends Kernel
{
    use MicroKernelTrait;

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
        return $this->getProjectDir() . '/var/cache/private-worker-' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'worker-test',
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
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default'   => ['url' => 'sqlite:///:memory:'],
                    'reporting' => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default', 'mappings' => ['HostSupport' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => dirname(__DIR__, 2) . '/Support/Entity',
                        'prefix'    => 'Nowo\\MarketingKitBundle\\Tests\\Support\\Entity',
                    ]]],
                    'reporting' => ['connection' => 'reporting'],
                ],
            ],
        ]);
        $container->extension('nowo_marketing_kit', [
            'use_database_config' => true,
            'security'            => ['allow_unauthenticated' => true],
            'doctrine'            => ['private_entity_manager' => true],
        ]);
        $container->services()->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoMarketingKitBundle/Resources/config/routing.yaml');
    }
}
