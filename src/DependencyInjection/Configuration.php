<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Provider\ToolIdValidator;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function sprintf;

/**
 * Validates and normalizes `nowo_marketing_kit` configuration (REQ-CFG-001 profiles).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_marketing_kit';

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
                        ->scalarNode('layout_template')
                            ->defaultValue('@NowoMarketingKitBundle/admin/layout.html.twig')
                            ->info('Twig layout extended by admin/base.html.twig via global nowo_marketing_kit_layout (REQ-UI-001). Host apps set this to the project layout.')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultValue('none')
                            ->info('Host-chosen CSS stack (REQ-UI-001). Twig global nowo_marketing_kit_css_framework. Values: bootstrap (alias of bootstrap5), bootstrap4, bootstrap5, tabler, tailwind, foundation, custom, none. Default none matches semantic mk-* / nowo-ui-* demo markup.')
                        ->end()
                        ->scalarNode('icon_set')
                            ->defaultValue('bootstrap-icons')
                            ->cannotBeEmpty()
                            ->info('Icon set seeded into nowo_ui_kit.icon_set when UiKit is installed and the host has not set it.')
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
