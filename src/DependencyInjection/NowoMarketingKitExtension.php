<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use LogicException;
use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\CommitTrackingPass;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\PrivateEntityManagerSourcePass;
use Nowo\MarketingKitBundle\DependencyInjection\Configuration as BundleConfiguration;
use Nowo\MarketingKitBundle\Doctrine\MarketingKitHostFlushReporter;
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
use Nowo\TransactionDoctrine\HostFlushProbe;
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
use function is_array;
use function is_string;
use function ltrim;
use function sprintf;
use function trigger_deprecation;

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

    /** 1.14: container parameter holding `doctrine.private_entity_manager === true`. */
    public const PRIVATE_ENTITY_MANAGER_PARAMETER = 'nowo_marketing_kit.doctrine.private_entity_manager';

    /**
     * 1.14: container parameter holding the name of the application's manager the private one is created on:
     * `doctrine.entity_manager`, else the default entity manager (resolved by {@see PrivateEntityManagerSourcePass}).
     */
    public const PRIVATE_SOURCE_MANAGER_PARAMETER = 'nowo_marketing_kit.private_entity_manager.source_manager';

    /**
     * 1.14: container parameter holding `doctrine.private_entity_manager` as configured (null when unset), read by
     * {@see PrivateEntityManagerSourcePass} to trigger the notice when `doctrine.entity_manager` names the application's
     * default manager.
     */
    public const PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER = 'nowo_marketing_kit.private_entity_manager.setting';

    /** 1.14: the bundle's private entity manager (`doctrine.private_entity_manager: true`). */
    public const PRIVATE_ENTITY_MANAGER_SERVICE = 'nowo_marketing_kit.private_entity_manager';

    /** 1.14: the registry the bundle's repository gets in private mode (MarketingTool → the private manager). */
    public const PRIVATE_REGISTRY_SERVICE = 'nowo_marketing_kit.private_manager_registry';

    /** 1.14: tells the private manager how the connection's transactions end (tagged by {@see PrivateEntityManagerSourcePass}). */
    public const PRIVATE_MIDDLEWARE_SERVICE = 'nowo_marketing_kit.private_entity_manager_middleware';

    /** 1.14: the notice of a host that sets neither `doctrine.private_entity_manager` nor `doctrine.entity_manager`. */
    public const PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION = 'Not setting "nowo_marketing_kit.doctrine.private_entity_manager" is deprecated: marketing tool writes flush the application\'s entity manager, including its own pending changes. 2.0 always writes through a private entity manager on the same connection and table that never flushes them. Opt in now with "nowo_marketing_kit: { doctrine: { private_entity_manager: true } }", or set "doctrine.entity_manager" to a manager of the bundle\'s own (see UPGRADING.md "To 1.14.0").';

    /** 1.14: the notice of a host that sets `doctrine.private_entity_manager: false` without `doctrine.entity_manager`. */
    public const PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION = '"nowo_marketing_kit.doctrine.private_entity_manager: false" is deprecated and removed in 2.0: marketing tool writes flush the application\'s entity manager, including its own pending changes, and 2.0 always writes through a private entity manager on the same connection and table that never flushes them. Set it to true now, or set "doctrine.entity_manager" to a manager of the bundle\'s own (see UPGRADING.md "To 1.14.0").';

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
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from web_ui
     * so kit macros resolve the same stack. Does not override keys the host already set.
     * web_ui.icon_set defaults to bootstrap-icons.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
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

        $container->prependExtensionConfig('nowo_ui_kit', $defaults);
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
        $container->setParameter('nowo_marketing_kit.doctrine.connection', $config['doctrine']['connection']);
        $container->setParameter('nowo_marketing_kit.doctrine.entity_manager', $config['doctrine']['entity_manager']);
        $entityManager = $config['doctrine']['entity_manager'];
        $private       = $config['doctrine']['private_entity_manager'] ?? null;
        $this->registerPrivateEntityManager($container, $private === null ? null : (bool) $private, is_string($entityManager) && $entityManager !== '' ? $entityManager : null);
        if (is_string($entityManager) && $private !== true) {
            // 1.10.0 (audit LD-MK-M1): the admin reads and writes MarketingTool through its own manager.
            $reference = new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManager));
            $container->getDefinition(MarketingToolAdminService::class)->setArgument('$entityManager', $reference);
            $container->getDefinition(MarketingToolAdminController::class)->setArgument('$entityManager', $reference);
            $container->getDefinition(MarketingToolWriteUnit::class)->setArgument('$entityManager', $reference);
        }
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

    /**
     * 1.14: the deprecation notice for a `doctrine.private_entity_manager` setting that writes through the application's
     * manager (null: unset; false: removed in 2.0), or null for true.
     */
    public static function privateEntityManagerNotice(?bool $private): ?string
    {
        return match ($private) {
            null    => self::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION,
            false   => self::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION,
            default => null,
        };
    }

    /**
     * 1.14 (REQ-ARCH-001 rule 6): `doctrine.private_entity_manager`.
     *
     * - true: the repository (through a {@see PrivateManagerRegistry}), the admin service, the admin controller and the
     *   {@see MarketingToolWriteUnit} work through transaction-doctrine's {@see PrivateEntityManager}, created on the
     *   connection, configuration and event manager of `doctrine.entity_manager` (else the default manager). Same table,
     *   no host configuration. It is dropped at every outermost unit-of-work boundary
     *   ({@see MarketingKitEntityManagerClearSubscriber}; also by transaction-doctrine's bundle through the
     *   {@see PrivateEntityManager::TAG} tag, and at `kernel.reset`) and replaced when closed.
     * - false or null: the bundle writes through the manager that maps MarketingTool (1.x). With
     *   `doctrine.entity_manager` unset, both trigger a deprecation notice (null: the default flips; false: removed in
     *   2.0); {@see PrivateEntityManagerSourcePass} triggers the same notice when `doctrine.entity_manager` names the
     *   application's default manager (audit XD-4: the bundle flushes it as when unset).
     *   {@see MarketingKitHostFlushReporter} reports in both, whenever the write unit flushes the application's manager.
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, ?bool $private, ?string $entityManagerName): void
    {
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_PARAMETER, $private === true);
        $container->setParameter(self::PRIVATE_SOURCE_MANAGER_PARAMETER, $entityManagerName ?? '');
        $container->setParameter(self::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER, $private);

        $notice = self::privateEntityManagerNotice($private);
        if ($notice !== null && $entityManagerName === null) {
            trigger_deprecation('nowo-tech/marketing-kit-bundle', '1.14', $notice);
        }

        $container->register(MarketingKitHostFlushReporter::class, MarketingKitHostFlushReporter::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setArguments([$private !== true, new Reference(HostFlushProbe::SERVICE_ID, ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->getDefinition(MarketingToolWriteUnit::class)
            ->setArgument('$hostFlushReporter', new Reference(MarketingKitHostFlushReporter::class));

        $resettables = [new Reference(MarketingKitHostFlushReporter::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)];

        if ($private === true) {
            $source = '%' . self::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
            $container->register(self::PRIVATE_ENTITY_MANAGER_SERVICE, PrivateEntityManager::class)
                ->setAutowired(false)
                ->setAutoconfigured(false)
                ->setArguments([
                    new Reference('doctrine'),
                    $source,
                    MarketingTool::class,
                    MarketingKitHostFlushReporter::BUNDLE_NAME,
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
                    MarketingKitHostFlushReporter::OWN_NAMESPACES,
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

            $resettables[] = new Reference(self::PRIVATE_ENTITY_MANAGER_SERVICE, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        }

        $container->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->setArgument('$resettables', new IteratorArgument($resettables));
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
