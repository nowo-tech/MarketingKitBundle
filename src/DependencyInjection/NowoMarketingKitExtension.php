<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\MarketingKitBundle\Security\AllowAllMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Loads MarketingKit services and publishes configuration parameters.
 */
final class NowoMarketingKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Seeds UiKit defaults from web_ui when the host has not set nowo_ui_kit (REQ-UI-001-kit).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependUiKitDefaults($container);
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from web_ui
     * so kit macros resolve the same stack. Does not override keys the host already set.
     * web_ui.icon_set is optional — defaults to bootstrap-icons when seeding UiKit.
     */

    /**
     * When FormKit is installed, register the marketing_kit profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('marketing_kit', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'marketing_kit' => [
                    'alias'              => 'marketing_kit',
                    'translation_domain' => 'NowoMarketingKitBundle',
                    'defaults'           => [
                        'attr'     => ['class' => 'nowo-ui-input form-control'],
                        'row_attr' => ['class' => 'mb-2'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check mb-2'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'entity' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config   = $this->processConfiguration(new BundleConfiguration(), $container->getExtensionConfig(BundleConfiguration::ALIAS));
        $webUi    = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];
        $defaults = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($webUi['css_framework'] ?? 'none');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = (string) ($webUi['icon_set'] ?? 'bootstrap-icons');
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

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

        if (
            !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoMarketingKitBundle admin UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

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

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }
}
