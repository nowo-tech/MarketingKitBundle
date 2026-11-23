<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use LogicException;
use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\CommitTrackingPass;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\PrivateEntityManagerSourcePass;
use Nowo\MarketingKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\MarketingKitBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use Nowo\MarketingKitBundle\EventSubscriber\MarketingKitEntityManagerClearSubscriber;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Provider\ToolRendererInterface;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\AllowAllMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use function ltrim;
use function sprintf;

/**
 * Loads MarketingKit services and publishes configuration parameters.
 */
final class NowoMarketingKitExtension extends Extension implements PrependExtensionInterface
{
    /** 1.13: real commits counted by {@see self::COMMIT_TRACKING_MIDDLEWARE}, read by the conflict answer. */
    public const COMMIT_LEDGER_SERVICE = 'nowo_marketing_kit.commit_ledger';

    /** 1.13: transaction-doctrine's commit-tracking DBAL middleware (scoped by {@see CommitTrackingPass}). */
    public const COMMIT_TRACKING_MIDDLEWARE = 'nowo_marketing_kit.commit_tracking_middleware';

    /** 1.13: container parameter holding `conflict_response.commit_tracking` (bundle, all or off). */
    public const COMMIT_TRACKING_PARAMETER = 'nowo_marketing_kit.conflict_response.commit_tracking';

    /**
     * Container parameter: true when the bundle writes through its private entity manager (2.0: always, unless
     * `doctrine.entity_manager` names a dedicated manager without `private_entity_manager: true`). Set by
     * {@see PrivateEntityManagerSourcePass}.
     */
    public const PRIVATE_ENTITY_MANAGER_PARAMETER = 'nowo_marketing_kit.doctrine.private_entity_manager';

    /**
     * 1.14: container parameter holding the name of the application's manager the private one is created on:
     * `doctrine.entity_manager`, else the default entity manager (resolved by {@see PrivateEntityManagerSourcePass}).
     */
    public const PRIVATE_SOURCE_MANAGER_PARAMETER = 'nowo_marketing_kit.private_entity_manager.source_manager';

    /**
     * Container parameter holding the deprecated `doctrine.private_entity_manager` as set: null (unset) or true (false
     * is refused by the configuration since 2.0). Read by {@see PrivateEntityManagerSourcePass}.
     */
    public const PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER = 'nowo_marketing_kit.private_entity_manager.setting';

    /** The bundle's private entity manager (1.14 opt-in; 2.0: the default). */
    public const PRIVATE_ENTITY_MANAGER_SERVICE = 'nowo_marketing_kit.private_entity_manager';

    /** The registry the bundle's repository gets with the private manager (MarketingTool → the private manager). */
    public const PRIVATE_REGISTRY_SERVICE = 'nowo_marketing_kit.private_manager_registry';

    /** Tells the private manager how the connection's transactions end (tagged by {@see PrivateEntityManagerSourcePass}). */
    public const PRIVATE_MIDDLEWARE_SERVICE = 'nowo_marketing_kit.private_entity_manager_middleware';

    /** The bundle named in the private manager's log lines. */
    public const BUNDLE_NAME = 'NowoMarketingKitBundle';

    /** The bundle's entity namespace: the only classes the private manager holds. */
    public const OWN_NAMESPACES = ['Nowo\\MarketingKitBundle\\Entity'];

    /**
     * What the bundle seeds into `nowo_ui_kit` when the host leaves a key unset: the 1.x zero-config result (the
     * defaults of the `web_ui.css_framework` / `web_ui.icon_set` keys removed in 2.0), so an application that never
     * configured either key renders as before.
     */
    public const UI_KIT_SEED = ['css_framework' => 'none', 'icon_set' => 'bootstrap-icons'];

    /**
     * Seeds UiKit defaults from web_ui when the host has not set nowo_ui_kit (REQ-UI-001-kit).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependUiKitDefaults($container);
    }

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
                    'alias'                           => 'marketing_kit',
                    'translation_domain'              => 'NowoMarketingKitBundle',
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        // REQ-UI-003: no framework classes in the bundle form; the UiKit form theme of
                        // web_ui.form_themes applies the theme slots (input, check, form_row, ...).
                        'attr'     => [],
                        'row_attr' => [],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set with {@see self::UI_KIT_SEED} when the host has
     * not set them (the 1.x zero-config result; the `web_ui.css_framework` / `icon_set` keys that could change the seed
     * were removed in 2.0). Never overrides a key the host set.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $defaults = self::UI_KIT_SEED;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            foreach (array_keys(self::UI_KIT_SEED) as $key) {
                if (array_key_exists($key, $cfg)) {
                    unset($defaults[$key]);
                }
            }
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

        // A host renderer only implements the interface (REQ-ARCH-001 rule 5); the tag is added by autoconfiguration.
        $container->registerForAutoconfiguration(ToolRendererInterface::class)->addTag('nowo_marketing_kit.tool_renderer');

        $container->setParameter('nowo_marketing_kit.use_database_config', $config['use_database_config']);
        $container->setParameter('nowo_marketing_kit.respect_cookie_consent', $config['respect_cookie_consent']);
        $container->setParameter('nowo_marketing_kit.default_profile', $config['default_profile']);
        $container->setParameter('nowo_marketing_kit.profiles', $config['profiles']);
        $container->setParameter('nowo_marketing_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_marketing_kit.doctrine.entity_manager', $config['doctrine']['entity_manager']);
        $entityManager = $config['doctrine']['entity_manager'];
        $this->registerPrivateEntityManager($container, ($config['doctrine']['private_entity_manager'] ?? null) === true, is_string($entityManager) && $entityManager !== '' ? $entityManager : null);
        if ($config['web_ui']['enabled']) {
            // Audit US-2: without the admin routes nothing answers a conflict, so no ledger, middleware or listener
            // (the host's connection is not wrapped for nothing).
            $this->registerConflictAnswer($container, $config['conflict_response']);
        }
        $container->setParameter('nowo_marketing_kit.security.access_roles', $config['security']['access_roles']);
        $container->setParameter('nowo_marketing_kit.security.access_checker', $config['security']['access_checker']);
        $container->setParameter('nowo_marketing_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_marketing_kit.security.allow_custom_html', (bool) ($config['security']['allow_custom_html'] ?? false));
        $container->setParameter('nowo_marketing_kit.security.allow_required_trackers', (bool) ($config['security']['allow_required_trackers'] ?? false));
        $container->setParameter('nowo_marketing_kit.security.csp_nonce_attribute', $config['security']['csp_nonce_attribute'] ?? null);
        $container->setParameter('nowo_marketing_kit.web_ui.enabled', $config['web_ui']['enabled']);
        $container->setParameter('nowo_marketing_kit.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter('nowo_marketing_kit.web_ui.route_path', ltrim((string) $config['web_ui']['path_prefix'], '/'));
        $container->setParameter('nowo_marketing_kit.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_marketing_kit.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_marketing_kit.web_ui.form_themes', $config['web_ui']['form_themes']);

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

    /**
     * REQ-ARCH-001 rule 6 (2.0): the repository (through a {@see PrivateManagerRegistry}), the admin service, the admin
     * controller and the {@see MarketingToolWriteUnit} work through transaction-doctrine's {@see PrivateEntityManager},
     * created on the connection, configuration and event manager of `doctrine.entity_manager` (else the default
     * manager). Same table, no host configuration. It is dropped at every outermost unit-of-work boundary
     * ({@see MarketingKitEntityManagerClearSubscriber}; also by transaction-doctrine's bundle through the
     * {@see PrivateEntityManager::TAG} tag, and at `kernel.reset`) and replaced when closed.
     *
     * Wired here for every setup; {@see PrivateEntityManagerSourcePass} puts the 1.x wiring of a dedicated
     * `doctrine.entity_manager` back (the DDD option, unchanged: the bundle uses that manager directly) when it is not the
     * application's default manager and `private_entity_manager` is not true: only a compiler pass knows the name of the
     * default manager. The 1.x mode in which the bundle flushed the application's manager no longer exists.
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, bool $privateSetting, ?string $entityManagerName): void
    {
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setParameter(self::PRIVATE_SOURCE_MANAGER_PARAMETER, $entityManagerName ?? '');
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $privateSetting ? true : null);

        $source = '%' . self::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
        $container->register(self::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                $source,
                MarketingTool::class,
                self::BUNDLE_NAME,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag(PrivateEntityManager::TAG)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(self::PRIVATE_REGISTRY_SERVICE, PrivateManagerRegistry::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([
                new Reference('doctrine'),
                new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE),
                self::OWN_NAMESPACES,
                $source,
            ]);
        // Tagged doctrine.middleware for the source manager's connection by PrivateEntityManagerSourcePass.
        $container->register(self::PRIVATE_MIDDLEWARE_SERVICE, PrivateEntityManagerMiddleware::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE), 'nowo_marketing_kit_']);

        $manager  = new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE);
        $registry = new Reference(self::PRIVATE_REGISTRY_SERVICE);
        $container->getDefinition(MarketingToolRepository::class)->setArgument('$registry', $registry);
        $container->getDefinition(MarketingToolAdminService::class)->setArgument('$entityManager', $manager);
        $container->getDefinition(MarketingToolAdminController::class)->setArgument('$entityManager', $manager);
        // The private manager is the bundle's own: a failed unit closes it and the private registry drops it.
        $container->getDefinition(MarketingToolWriteUnit::class)
            ->setArgument('$entityManager', $manager)
            ->setArgument('$registry', $registry)
            ->setArgument('$managerName', $source)
            ->setArgument('$ownManager', true);
        $container->getDefinition(ClosedEntityManagerSubscriber::class)
            ->setArgument('$registry', $registry)
            ->setArgument('$entityManagerName', $source);
        $container->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument([
            new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
        ]));
    }

    /**
     * A dedicated `doctrine.entity_manager` that is not the application's default manager, without
     * `private_entity_manager: true` (the DDD option, unchanged since 1.10): the repository gets the application's
     * registry back (autowired again: the `$registry` argument is dropped before autowiring runs), the admin and the
     * write unit use that manager directly, and the private manager's services are removed. Called by
     * {@see PrivateEntityManagerSourcePass}; {@see EntityManagerOptionPass} marks that manager as the bundle's own and
     * hands it to the boundary subscriber.
     */
    public static function useDedicatedEntityManager(ContainerBuilder $container, string $entityManagerName): void
    {
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, false);

        $reference = new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName));
        if ($container->hasDefinition(MarketingToolRepository::class)) {
            $repository = $container->getDefinition(MarketingToolRepository::class);
            $arguments  = $repository->getArguments();
            unset($arguments['$registry']);
            $repository->setArguments($arguments);
        }
        foreach ([MarketingToolAdminService::class, MarketingToolAdminController::class] as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setArgument('$entityManager', $reference);
            }
        }
        if ($container->hasDefinition(MarketingToolWriteUnit::class)) {
            $container->getDefinition(MarketingToolWriteUnit::class)
                ->setArgument('$entityManager', $reference)
                ->setArgument('$registry', new Reference('doctrine'))
                ->setArgument('$managerName', $entityManagerName);
        }
        if ($container->hasDefinition(ClosedEntityManagerSubscriber::class)) {
            $container->getDefinition(ClosedEntityManagerSubscriber::class)
                ->setArgument('$registry', new Reference('doctrine'))
                ->setArgument('$entityManagerName', $entityManagerName);
        }
        if ($container->hasDefinition(MarketingKitEntityManagerClearSubscriber::class)) {
            $container->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument([]));
        }

        $container->removeDefinition(self::PRIVATE_MIDDLEWARE_SERVICE);
        $container->removeDefinition(self::PRIVATE_REGISTRY_SERVICE);
        $container->removeDefinition(self::PRIVATE_ENTITY_MANAGER_SERVICE);
    }

    /**
     * 1.13 (REQ-RUNTIME-003 rule 6): 409 (or 503) + CONFLICT_001 / CONFLICT_002 instead of a 500 for lock conflicts on
     * the marketing tool admin. Unset options follow transaction-doctrine's policy service when its bundle is registered.
     *
     * Commit tracking (`conflict_response.commit_tracking`): `bundle` (default) and `all` register transaction-doctrine's
     * commit-tracking middleware; {@see CommitTrackingPass} scopes it to the bundle's connection (`bundle`), or drops it
     * and reuses transaction-doctrine's own ledger when that bundle's `commit_detection` is on. `off` registers neither
     * the ledger nor the middleware.
     *
     * @param array{status: int|null, retry_after: int|null, commit_tracking: string} $conflictResponse
     */
    private function registerConflictAnswer(ContainerBuilder $container, array $conflictResponse): void
    {
        $container->setParameter(self::COMMIT_TRACKING_PARAMETER, $conflictResponse['commit_tracking']);
        $tracking = $conflictResponse['commit_tracking'] !== 'off';
        if ($tracking) {
            $container->setDefinition(self::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
            $container->setDefinition(self::COMMIT_TRACKING_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(self::COMMIT_LEDGER_SERVICE)]))
                ->addTag('doctrine.middleware'));
        }

        $policy = $conflictResponse['status'] === null && $conflictResponse['retry_after'] === null
            ? new Reference(ConflictResponsePolicy::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Definition(ConflictResponsePolicy::class, [
                $conflictResponse['status'] ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $conflictResponse['retry_after'] ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]);

        $container->setDefinition(MarketingToolConflictListener::class, (new Definition(MarketingToolConflictListener::class, [
            $policy,
            $tracking ? new Reference(self::COMMIT_LEDGER_SERVICE) : null,
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => MarketingToolConflictListener::REQUEST_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => MarketingToolConflictListener::PRIORITY]));
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
