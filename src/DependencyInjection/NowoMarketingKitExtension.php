<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads MarketingKit services and publishes configuration parameters.
 */
final class NowoMarketingKitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new BundleConfiguration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setParameter('nowo_marketing_kit.use_database_config', $config['use_database_config']);
        $container->setParameter('nowo_marketing_kit.respect_cookie_consent', $config['respect_cookie_consent']);
        $container->setParameter('nowo_marketing_kit.default_profile', $config['default_profile']);
        $container->setParameter('nowo_marketing_kit.profiles', $config['profiles']);
        $container->setParameter('nowo_marketing_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_marketing_kit.doctrine.connection', $config['doctrine']['connection']);

        $tablePrefix = (string) $config['doctrine']['table_prefix'];
        if ($tablePrefix !== '') {
            $definition = new Definition(TablePrefixListener::class, [$tablePrefix]);
            $definition->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
            $container->setDefinition(TablePrefixListener::class, $definition);
        }
    }

    public function getAlias(): string
    {
        return BundleConfiguration::ALIAS;
    }
}
