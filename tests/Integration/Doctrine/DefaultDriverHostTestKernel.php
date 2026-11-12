<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\MarketingKitBundle\NowoMarketingKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * DoctrineBundle 3.4 regression layout: the host's "default" manager has neither `mappings` nor `auto_mapping` (so
 * DoctrineBundle 3.4 `auto_discover_entities` gives its chain a default attribute driver), and a dedicated "bundle"
 * manager is named by `nowo_marketing_kit.doctrine.entity_manager`. With `$hostDefaultDriver` the host also gives the
 * default manager's chain an explicit catch-all default driver, which reproduces the regression on every
 * DoctrineBundle version.
 */
final class DefaultDriverHostTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug, private readonly bool $hostDefaultDriver = false)
    {
        parent::__construct($environment, $debug);
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
        return $this->getProjectDir() . '/var/cache/default-driver-host-' . ($this->hostDefaultDriver ? 'explicit' : 'none') . '-' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        if (!$this->hostDefaultDriver) {
            return;
        }

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
                $container->getDefinition('doctrine.orm.default_metadata_driver')
                    ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[__DIR__], true])]);
            }
        });
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'default-driver-test',
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
                    'default' => ['url' => 'sqlite:///:memory:'],
                    'bundle'  => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default'],
                    'bundle'  => ['connection' => 'bundle'],
                ],
            ],
        ]);
        $container->extension('nowo_marketing_kit', [
            'use_database_config' => true,
            'security'            => ['allow_unauthenticated' => true],
            'doctrine'            => ['entity_manager' => 'bundle'],
        ]);
        $container->services()->set('logger', NullLogger::class);
    }
}
