<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultProfileShape(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['use_database_config']);
        self::assertTrue($config['respect_cookie_consent']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/admin/marketing', $config['web_ui']['path_prefix']);
        self::assertSame('@NowoMarketingKitBundle/admin/index.html.twig', $config['web_ui']['templates']['index']);
        self::assertSame('@NowoMarketingKitBundle/admin/form.html.twig', $config['web_ui']['templates']['form']);
        self::assertSame(['@NowoUiKit/form/nowo_ui_layout.html.twig'], $config['web_ui']['form_themes']);
        self::assertSame('none', $config['web_ui']['css_framework']);
        self::assertSame('bootstrap-icons', $config['web_ui']['icon_set']);
        self::assertSame('default', $config['default_profile']);
        self::assertArrayHasKey('default', $config['profiles']);
    }

    #[IgnoreDeprecations]
    public function testDoctrineConnectionIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/"connection" option at "nowo_marketing_kit\\.doctrine" is deprecated/');

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'doctrine' => ['connection' => 'other'],
        ]]);

        self::assertSame('other', $config['doctrine']['connection']);
    }

    public function testDoctrineConnectionDefaultDoesNotTriggerDeprecation(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('default', $config['doctrine']['connection']);
    }

    public function testWebUiPathPrefixAndTemplates(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => [
                'enabled'         => false,
                'path_prefix'     => '/back-office/marketing.v2',
                'layout_template' => 'layouts/admin.html.twig',
                'templates'       => ['index' => 'admin/marketing_index.html.twig'],
                'form_themes'     => [],
            ],
        ]]);

        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('/back-office/marketing.v2', $config['web_ui']['path_prefix']);
        self::assertSame('layouts/admin.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('admin/marketing_index.html.twig', $config['web_ui']['templates']['index']);
        self::assertSame('@NowoMarketingKitBundle/admin/form.html.twig', $config['web_ui']['templates']['form']);
        self::assertSame([], $config['web_ui']['form_themes']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPathPrefixes(): iterable
    {
        yield 'no leading slash' => ['admin/marketing'];
        yield 'trailing slash' => ['/admin/marketing/'];
        yield 'root' => ['/'];
        yield 'placeholder' => ['/admin/{x}'];
        yield 'not a string' => [42];
    }

    #[DataProvider('invalidPathPrefixes')]
    public function testInvalidWebUiPathPrefixFails(mixed $prefix): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => $prefix],
        ]]);
    }

    #[IgnoreDeprecations]
    public function testWebUiCssFrameworkIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/"css_framework" option at "nowo_marketing_kit\\.web_ui" is deprecated/');

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['css_framework' => 'bootstrap'],
        ]]);

        self::assertSame('bootstrap5', $config['web_ui']['css_framework']);
    }

    public function testInvalidDefaultProfileFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['enabled' => true, 'tools' => []],
            ],
        ]]);
    }

    public function testToolNodeAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'tools' => [
                        'gtm' => [
                            'type'     => 'gtm',
                            'category' => 'analytics',
                            'options'  => ['container_id' => 'GTM-X'],
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertSame('gtm', $config['profiles']['default']['tools']['gtm']['type']);
        self::assertSame('analytics', $config['profiles']['default']['tools']['gtm']['category']);
    }

    #[IgnoreDeprecations]
    public function testWebUiIconSetIsConfigurable(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['icon_set' => 'fontawesome'],
        ]]);

        self::assertSame('fontawesome', $config['web_ui']['icon_set']);
    }

    public function testToolWithInjectedIdIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid measurement_id for marketing tool type "ga4"');

        (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'tools' => [
                        'ga4' => [
                            'type'    => 'ga4',
                            'options' => ['measurement_id' => "G-1');alert(document.cookie);//"],
                        ],
                    ],
                ],
            ],
        ]]);
    }

    public function testToolWithEmptyIdOrCustomTypeIsAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'tools' => [
                        'meta'   => ['type' => 'meta_pixel', 'options' => ['pixel_id' => '']],
                        'custom' => ['type' => 'custom', 'options' => ['html' => '<script></script>']],
                    ],
                ],
            ],
        ]]);

        self::assertCount(2, $config['profiles']['default']['tools']);
    }

    #[IgnoreDeprecations]
    public function testInvalidCssFrameworkFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => [
                'css_framework' => 'bulma',
            ],
        ]]);
    }

    #[IgnoreDeprecations]
    public function testCssFrameworkAcceptsCanonicalValues(): void
    {
        foreach (Configuration::CSS_FRAMEWORKS as $framework) {
            $expected = $framework === 'bootstrap' ? 'bootstrap5' : $framework;
            $config   = (new Processor())->processConfiguration(new Configuration(), [[
                'web_ui' => ['css_framework' => $framework],
            ]]);

            self::assertSame($expected, $config['web_ui']['css_framework']);
        }
    }

    #[IgnoreDeprecations]
    public function testCssFrameworkBootstrapAlias(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['css_framework' => 'bootstrap'],
        ]]);

        self::assertSame('bootstrap5', $config['web_ui']['css_framework']);
    }
}
