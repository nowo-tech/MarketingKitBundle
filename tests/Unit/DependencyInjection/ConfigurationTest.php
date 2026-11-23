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
        self::assertArrayNotHasKey('css_framework', $config['web_ui']);
        self::assertArrayNotHasKey('icon_set', $config['web_ui']);
        self::assertArrayNotHasKey('connection', $config['doctrine']);
        self::assertNull($config['doctrine']['private_entity_manager']);
        self::assertSame('default', $config['default_profile']);
        self::assertArrayHasKey('default', $config['profiles']);
    }

    /**
     * 2.0: keys deprecated in 1.x fail the build with a message naming their replacement (never Symfony's generic
     * "Unrecognized option").
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedKeys')]
    public function testARemovedKeyFailsTheBuildNamingItsReplacement(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function removedKeys(): iterable
    {
        yield 'doctrine.connection (1.6)' => [['doctrine' => ['connection' => 'default']], 'The "nowo_marketing_kit.doctrine.connection" option was removed in 2.0: remove it'];
        yield 'web_ui.css_framework (1.9)' => [['web_ui' => ['css_framework' => 'bootstrap']], 'The "nowo_marketing_kit.web_ui.css_framework" option was removed in 2.0: set "nowo_ui_kit.css_framework"'];
        yield 'web_ui.css_framework none (1.9)' => [['web_ui' => ['css_framework' => 'none']], 'The "nowo_marketing_kit.web_ui.css_framework" option was removed in 2.0'];
        yield 'web_ui.icon_set (1.9)' => [['web_ui' => ['icon_set' => 'fontawesome']], 'The "nowo_marketing_kit.web_ui.icon_set" option was removed in 2.0: set "nowo_ui_kit.icon_set"'];
    }

    /**
     * 2.0: `private_entity_manager: false` (the 1.x mode that flushed the application's manager) fails the build;
     * `true` is a deprecated no-op (removed in 3.0).
     */
    public function testPrivateEntityManagerFalseFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_marketing_kit.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which marketing tool writes flush your application\'s entity manager. Remove the key. The bundle then writes through its private entity manager');

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => false]]]);
    }

    public function testPrivateEntityManagerFalseWithADedicatedManagerNamesIt(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('doctrine.entity_manager "marketing" stays: a dedicated manager keeps working as in 1.x');

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['entity_manager' => 'marketing', 'private_entity_manager' => false]]]);
    }

    #[IgnoreDeprecations]
    public function testPrivateEntityManagerTrueIsADeprecatedNoOp(): void
    {
        $this->expectUserDeprecationMessageMatches('/Since nowo-tech\/marketing-kit-bundle 2\.0: The "private_entity_manager" option at "nowo_marketing_kit\.doctrine" has no effect since 2\.0 without a dedicated doctrine\.entity_manager/');

        $config = (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['private_entity_manager' => true]]]);

        self::assertTrue($config['doctrine']['private_entity_manager']);
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
}
