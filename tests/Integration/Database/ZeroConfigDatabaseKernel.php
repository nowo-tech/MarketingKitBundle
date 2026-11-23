<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Database;

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
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function md5;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 2.0: a Symfony application with ZERO MarketingKit Doctrine configuration (the 1.x default in which the bundle flushed
 * the application's default manager), on a real database. The application's default manager maps its own entities,
 * one of them related to MarketingTool, and an application Doctrine listener ({@see HostApplicationListener}).
 */
final class ZeroConfigDatabaseKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $databaseUrl)
    {
        parent::__construct('test', true);
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
        return $this->getProjectDir() . '/var/cache/zero-config-db-' . md5($this->databaseUrl);
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'zero-config-db-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'router'                => ['utf8' => true],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'form'                  => ['enabled' => true],
            'csrf_protection'       => ['enabled' => true],
            'php_errors'            => ['log' => true],
        ]);
        $support = dirname(__DIR__, 2) . '/Support';
        $container->extension('doctrine', [
            'dbal' => ['url' => $this->databaseUrl],
            'orm'  => [
                'mappings' => [
                    'HostSupport' => ['type' => 'attribute', 'is_bundle' => false, 'dir' => $support . '/Entity', 'prefix' => 'Nowo\\MarketingKitBundle\\Tests\\Support\\Entity'],
                    'HostLinked'  => ['type' => 'attribute', 'is_bundle' => false, 'dir' => $support . '/LinkedEntity', 'prefix' => 'Nowo\\MarketingKitBundle\\Tests\\Support\\LinkedEntity'],
                ],
            ],
        ]);
        // Nothing under "doctrine": the zero-config default.
        $container->extension('nowo_marketing_kit', [
            'use_database_config' => true,
            'security'            => ['allow_unauthenticated' => true],
            'profiles'            => ['default' => ['tools' => [
                'gtm' => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'options' => ['container_id' => 'GTM-ZERO1']],
            ]]],
        ]);
        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(HostApplicationListener::class)
            ->public()
            ->args([service('doctrine.orm.default_entity_manager')])
            ->tag('doctrine.event_listener', ['event' => 'prePersist'])
            ->tag('doctrine.event_listener', ['event' => 'postPersist']);
    }
}
