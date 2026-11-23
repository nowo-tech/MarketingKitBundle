<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\Enum\ToolCategory;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Provider\ToolIdValidator;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_key_exists;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * Validates and normalizes `nowo_marketing_kit` configuration (REQ-CFG-001 profiles).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_marketing_kit';

    /** Default of web_ui.path_prefix. */
    public const DEFAULT_PATH_PREFIX = '/admin/marketing';

    /**
     * @var list<string> Host CSS stacks the removed `web_ui.css_framework` accepted (1.x). Kept as a public constant;
     *                   nothing reads it since 2.0.
     */
    public const CSS_FRAMEWORKS = [
        'bootstrap',
        'bootstrap4',
        'bootstrap5',
        'tabler',
        'tailwind',
        'foundation',
        'custom',
        'none',
    ];

    /**
     * `web_ui` keys removed in 2.0 (deprecated since 1.9) => what replaces them. A leftover key fails the build with this
     * text instead of Symfony's generic "Unrecognized option".
     */
    public const REMOVED_WEB_UI_KEYS = [
        'css_framework' => 'set "nowo_ui_kit.css_framework" once for every bundle instead',
        'icon_set'      => 'set "nowo_ui_kit.icon_set" once for every bundle instead',
    ];

    /** `doctrine` keys removed in 2.0 => what replaces them. */
    public const REMOVED_DOCTRINE_KEYS = [
        'connection' => 'remove it (deprecated since 1.6, it had no effect: the bundle uses the connection of its entity manager)',
    ];

    /** Deprecation of `doctrine.private_entity_manager` (any value) since 2.0; the key is removed in 3.0. */
    public const PRIVATE_ENTITY_MANAGER_DEPRECATION = 'The "%node%" option at "%path%" has no effect since 2.0 without a dedicated doctrine.entity_manager (the private entity manager is then the only mode): remove it. With a dedicated doctrine.entity_manager, true still selects the private manager on that manager, and removing the key makes the bundle write through the dedicated manager directly, so keep it until 3.0 unless you want that. The key is removed in 3.0.';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('use_database_config')->defaultFalse()->end()
                ->booleanNode('respect_cookie_consent')->defaultTrue()->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('allow_custom_html')
                            ->defaultFalse()
                            ->info('When false (default), CustomScriptRenderer emits nothing and the admin refuses to save custom HTML. Enable only for trusted admin/YAML snippets.')
                        ->end()
                        ->booleanNode('allow_required_trackers')
                            ->defaultFalse()
                            ->info('When false (default), built-in trackers (every type but custom) cannot use the "required" category, which bypasses the consent gate; such rows are gated as "marketing" at render time.')
                        ->end()
                        ->scalarNode('csp_nonce_attribute')
                            ->defaultNull()
                            ->info('Request attribute holding the CSP script nonce (e.g. set by your CSP listener). When set (or when a nonce is passed to the Twig functions), built-in <script> tags get nonce="…"; custom HTML is emitted as-is.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                                ->thenInvalid('nowo_marketing_kit.security.csp_nonce_attribute must be null or a non-empty string, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $v): array => self::refuseRemovedKeys($v, self::REMOVED_WEB_UI_KEYS, 'web_ui'))
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Admin UI on/off (REQ-UI-003). When false the admin routes answer 404.')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->defaultValue(self::DEFAULT_PATH_PREFIX)
                            ->info('URL prefix of the admin pages (REQ-UI-003).')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $value) !== 1)
                                ->thenInvalid('nowo_marketing_kit.web_ui.path_prefix must start with "/" and contain URL path segments only, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout the admin page scaffolds extend (REQ-UI-001/003). Null (default) = nowo_ui_kit.layouts.admin. A host layout must render the UiKit canonical blocks: since 2.0 a 1.x layout that renders only {% block body %} fails at render (the 1.x bridge was removed).')
                        ->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->info('Per-page template overrides (REQ-UI-003): extend the bundle template and override a data block.')
                            ->children()
                                ->scalarNode('index')->defaultValue(MarketingKitPages::TEMPLATES['index'])->cannotBeEmpty()->end()
                                ->scalarNode('form')->defaultValue(MarketingKitPages::TEMPLATES['form'])->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                        ->arrayNode('form_themes')
                            ->info('Form themes of the tool form (UiKit FormPage formThemes). Empty = only the host twig.form_themes.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([MarketingKitPages::DEFAULT_FORM_THEME])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $v): array => self::refuseRemovedKeys($v, self::REMOVED_DOCTRINE_KEYS, 'doctrine'))
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => ($v['private_entity_manager'] ?? null) === false)
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(self::privateEntityManagerFalseMessage(is_string($v['entity_manager'] ?? null) ? $v['entity_manager'] : null));
                        })
                    ->end()
                    ->children()
                        ->scalarNode('table_prefix')->defaultValue('')->end()
                        ->scalarNode('entity_manager')
                            ->defaultNull()
                            ->info('Since 1.10.0: entity manager that maps MarketingTool (e.g. a dedicated manager in a DDD host, the DDD option). The entity is mapped on it only, the admin reads and writes through it, and the bundle resets only this manager when a failed flush left it closed (never a host manager). The build fails when it does not exist. null (default): the default manager, through the bundle\'s private entity manager (2.0: the bundle never flushes your manager). Naming the default manager is the same as null.')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || $v === ''))
                                ->thenInvalid('nowo_marketing_kit.doctrine.entity_manager must be an entity manager name or null; got %s.')
                            ->end()
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/marketing-kit-bundle', '2.0', self::PRIVATE_ENTITY_MANAGER_DEPRECATION)
                            ->info('Deprecated since 2.0, removed in 3.0 (REQ-ARCH-001 rule 6). The bundle always reads and writes MarketingTool through a private entity manager of its own, created on the connection, mapping and event manager of the default entity manager (or of doctrine.entity_manager when it names the default one): same table, nothing to configure, and a marketing tool write never flushes the application\'s manager. true: accepted, no effect (with a dedicated doctrine.entity_manager it keeps the 1.14 behaviour: the private manager on that manager). false: refused (the 1.x mode that flushed the application\'s manager was removed in 2.0).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('conflict_response')
                    ->info('Since 1.13 (REQ-RUNTIME-003): answer of a lock conflict (deadlock, lock wait timeout, serialization failure) on the marketing tool admin. Unset: nowo-tech/transaction-doctrine\'s conflict_response when that bundle is registered, else 409 with Retry-After 1.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('status')
                            ->info('409 (default, CONFLICT_001 / CONFLICT_002) or 503. Null: follow transaction-doctrine, else 409.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && !in_array($value, ConflictResponsePolicy::STATUSES, true))
                                ->thenInvalid('nowo_marketing_kit.conflict_response.status must be 409 or 503, got %s.')
                            ->end()
                        ->end()
                        ->integerNode('retry_after')
                            ->info('Retry-After seconds of a CONFLICT_001 answer. Null: follow transaction-doctrine, else 1.')
                            ->defaultNull()
                            ->min(0)
                        ->end()
                        ->enumNode('commit_tracking')
                            ->values(['bundle', 'all', 'off'])
                            ->defaultValue('bundle')
                            ->info('Commits counted for CONFLICT_002 when nowo-tech/transaction-doctrine\'s commit_detection is off: bundle (default, the connection of the bundle\'s entity manager only), all (every Doctrine connection) or off (no middleware; only the failure itself and transaction-doctrine\'s request mark decide). With commit_detection on, transaction-doctrine\'s ledger is reused and the bundle adds no middleware; with web_ui.enabled false nothing is registered.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_profile')->defaultValue('default')->end()
                ->arrayNode('profiles')
                    ->useAttributeAsKey('name')
                    ->defaultValue([
                        'default' => [
                            'enabled' => true,
                            'tools'   => [],
                        ],
                    ])
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->arrayNode('tools')
                                ->useAttributeAsKey('code')
                                ->arrayPrototype()
                                    ->children()
                                        ->enumNode('type')
                                            ->values(ToolType::values())
                                            ->isRequired()
                                        ->end()
                                        ->booleanNode('enabled')->defaultTrue()->end()
                                        ->enumNode('category')
                                            ->values(ToolCategory::values())
                                            ->defaultValue(ToolCategory::Marketing->value)
                                        ->end()
                                        ->enumNode('position')
                                            ->values(ToolPosition::values())
                                            ->defaultValue(ToolPosition::Head->value)
                                        ->end()
                                        ->integerNode('sort_order')->defaultValue(0)->end()
                                        ->arrayNode('options')
                                            ->variablePrototype()->end()
                                            ->defaultValue([])
                                        ->end()
                                    ->end()
                                    ->validate()
                                        ->ifTrue(static function (array $tool): bool {
                                            $key = ToolIdValidator::optionKeyFor((string) $tool['type']);

                                            if ($key === null) {
                                                return false;
                                            }
                                            $value = $tool['options'][$key] ?? null;

                                            // %env()% values are placeholders at compile time; the render-time check covers them (SEC-004 I1).
                                            return !self::isEnvPlaceholder($value) && !ToolIdValidator::isValidOrEmpty((string) $tool['type'], $value);
                                        })
                                        ->then(static function (array $tool): never {
                                            throw new InvalidConfigurationException(sprintf('Invalid %s for marketing tool type "%s": the value does not match the provider ID format.', (string) ToolIdValidator::optionKeyFor((string) $tool['type']), (string) $tool['type']));
                                        })
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => !isset($v['profiles'][$v['default_profile']]))
                ->thenInvalid('default_profile must exist under profiles.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => self::requiredTrackerCodes($v) !== [])
                ->then(static function (array $v): never {
                    throw new InvalidConfigurationException(sprintf('Built-in trackers cannot use the "required" consent category (it bypasses the consent gate): %s. Use analytics / marketing / preferences, or set nowo_marketing_kit.security.allow_required_trackers: true.', implode(', ', self::requiredTrackerCodes($v))));
                })
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Build error of `doctrine.private_entity_manager: false` (2.0 removed the 1.x mode it kept).
     */
    public static function privateEntityManagerFalseMessage(?string $entityManager): string
    {
        $message = 'nowo_marketing_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which marketing tool writes flush your application\'s entity manager. Remove the key.';
        if ($entityManager !== null && $entityManager !== '') {
            $message .= sprintf(' doctrine.entity_manager "%s" stays: a dedicated manager keeps working as in 1.x; if it names your default entity manager, 2.0 uses the private entity manager on it.', $entityManager);
        } else {
            $message .= ' The bundle then writes through its private entity manager (same connection and table), which never flushes your pending changes; or set doctrine.entity_manager to a dedicated manager.';
        }

        return $message . ' See UPGRADING.md "To 2.0.0".';
    }

    /**
     * Fails on a key removed in 2.0, naming what replaces it.
     *
     * @param array<mixed> $values
     * @param array<string, string> $removed key => what replaces it
     *
     * @return array<mixed>
     */
    private static function refuseRemovedKeys(array $values, array $removed, string $path): array
    {
        foreach ($removed as $key => $replacement) {
            if (array_key_exists($key, $values)) {
                throw new InvalidConfigurationException(sprintf('The "%s.%s.%s" option was removed in 2.0: %s. See UPGRADING.md "To 2.0.0".', self::ALIAS, $path, $key, $replacement));
            }
        }

        return $values;
    }

    /**
     * Whether a configured value is (or contains) an unresolved %env()% parameter: the raw `%env(...)%` string seen by
     * prepend(), or the `env_<hash>_…` placeholder seen by load().
     */
    public static function isEnvPlaceholder(mixed $value): bool
    {
        return is_string($value) && (str_contains($value, '%env(') || preg_match('/env_[0-9a-f]{16}_/', $value) === 1);
    }

    /**
     * "profile.code" of every built-in tracker in the `required` category when security.allow_required_trackers is off.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function requiredTrackerCodes(array $config): array
    {
        if (($config['security']['allow_required_trackers'] ?? false) === true) {
            return [];
        }

        $codes = [];
        foreach ($config['profiles'] ?? [] as $profile => $profileConfig) {
            foreach ($profileConfig['tools'] ?? [] as $code => $tool) {
                if (($tool['category'] ?? null) === ToolCategory::Required->value && !ToolCategory::allowsRequired((string) $tool['type'], false)) {
                    $codes[] = $profile . '.' . $code;
                }
            }
        }

        return $codes;
    }
}
