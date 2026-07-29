<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\MarketingKitBundle\Security\AllowAllMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

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
        $container->setParameter('nowo_marketing_kit.security.access_roles', $config['security']['access_roles']);
        $container->setParameter('nowo_marketing_kit.security.access_checker', $config['security']['access_checker']);
        $container->setParameter('nowo_marketing_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_marketing_kit.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_marketing_kit.web_ui.css_framework', $config['web_ui']['css_framework']);

        $this->registerAccessChecker($container, $config['security']);

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

    /**
     * @param array{access_checker: ?string, access_roles: list<string>, allow_unauthenticated: bool} $security
     */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        if ($security['allow_unauthenticated']) {
            $accessCheckerId = 'nowo_marketing_kit.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllMarketingKitAccessChecker::class));
            $container->setAlias(MarketingKitAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        $accessCheckerId = $security['access_checker'] ?? null;
        if (is_string($accessCheckerId) && $accessCheckerId !== '') {
            $container->setAlias(MarketingKitAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        $hasAuthorizationChecker = $container->hasDefinition('security.authorization_checker')
            || $container->hasAlias('security.authorization_checker');

        $accessCheckerId = 'nowo_marketing_kit.access_checker.default';
        $definition      = new Definition(ConfigurableMarketingKitAccessChecker::class);
        $definition->setArgument('$accessRoles', $security['access_roles']);
        if ($hasAuthorizationChecker) {
            $definition->setArgument('$authorizationChecker', new Reference('security.authorization_checker'));
        } else {
            $definition->setAutowired(true);
        }
        $container->setDefinition($accessCheckerId, $definition);
        $container->setAlias(MarketingKitAccessCheckerInterface::class, $accessCheckerId);
    }
}
