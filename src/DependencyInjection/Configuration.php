<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Provider\ToolIdValidator;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Validates and normalizes `nowo_marketing_kit` configuration (REQ-CFG-001 profiles).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_marketing_kit';

    /** Default of web_ui.path_prefix. */
    public const DEFAULT_PATH_PREFIX = '/admin/marketing';

    /** @var list<string> Host CSS stacks accepted by web_ui.css_framework (REQ-UI-001). */
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
                            ->info('When false (default), CustomScriptRenderer emits nothing. Enable only for trusted admin/YAML snippets.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
                            if (isset($v['css_framework']) && $v['css_framework'] === 'bootstrap') {
                                $v['css_framework'] = 'bootstrap5';
                            }

                            return $v;
                        })
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
                            ->info('Layout the admin page scaffolds extend (REQ-UI-001/003). Null (default) = nowo_ui_kit.layouts.admin. A host layout must render the UiKit canonical blocks; a 1.x layout that renders {% block body %} is wrapped in a deprecated bridge.')
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
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultValue('none')
                            ->setDeprecated('nowo-tech/marketing-kit-bundle', '1.9', 'The "%node%" option at "%path%" is deprecated: set nowo_ui_kit.css_framework once for every bundle. It only seeds nowo_ui_kit.css_framework when the host leaves it unset.')
                            ->info('Deprecated since 1.9: set nowo_ui_kit.css_framework. Seeds nowo_ui_kit.css_framework when the host has not set it (default none). Values: bootstrap (alias of bootstrap5), bootstrap4, bootstrap5, tabler, tailwind, foundation, custom, none.')
                        ->end()
                        ->scalarNode('icon_set')
                            ->defaultValue('bootstrap-icons')
                            ->cannotBeEmpty()
                            ->setDeprecated('nowo-tech/marketing-kit-bundle', '1.9', 'The "%node%" option at "%path%" is deprecated: set nowo_ui_kit.icon_set once for every bundle. It only seeds nowo_ui_kit.icon_set when the host leaves it unset.')
                            ->info('Deprecated since 1.9: set nowo_ui_kit.icon_set. Seeds nowo_ui_kit.icon_set when the host has not set it.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')->defaultValue('')->end()
                        ->scalarNode('connection')
                            ->defaultValue('default')
                            ->setDeprecated('nowo-tech/marketing-kit-bundle', '1.6', 'The "%node%" option at "%path%" is deprecated and has no effect: the bundle always uses the default Doctrine entity manager and its connection. Remove it from your configuration.')
                            ->info('Deprecated since 1.6, no effect: the default Doctrine entity manager (and its connection) is always used.')
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
                                        ->scalarNode('category')->defaultValue('marketing')->end()
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

                                            return $key !== null && !ToolIdValidator::isValidOrEmpty((string) $tool['type'], $tool['options'][$key] ?? null);
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
        ;

        return $treeBuilder;
    }
}
