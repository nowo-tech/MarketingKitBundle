<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection;

use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validates and normalizes `nowo_marketing_kit` configuration (REQ-CFG-001 profiles).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_marketing_kit';

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
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->defaultValue('@NowoMarketingKitBundle/admin/layout.html.twig')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(['bootstrap5', 'bootstrap4', 'tailwind', 'none'])
                            ->defaultValue('none')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')->defaultValue('')->end()
                        ->scalarNode('connection')->defaultValue('default')->end()
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
