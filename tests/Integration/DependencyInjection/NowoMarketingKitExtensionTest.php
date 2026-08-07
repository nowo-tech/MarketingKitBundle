<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\DependencyInjection;

use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Twig\MarketingKitExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;

final class NowoMarketingKitExtensionTest extends TestCase
{
    public function testLoadPublishesParametersAndServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'use_database_config'    => true,
            'respect_cookie_consent' => false,
            'doctrine'               => ['table_prefix' => 'app_', 'connection' => 'default'],
            'default_profile'        => 'default',
            'profiles'               => [
                'default' => [
                    'enabled' => true,
                    'tools'   => [],
                ],
            ],
        ]], $container);

        self::assertSame('nowo_marketing_kit', $extension->getAlias());
        self::assertTrue($container->getParameter('nowo_marketing_kit.use_database_config'));
        self::assertFalse($container->getParameter('nowo_marketing_kit.respect_cookie_consent'));
        self::assertSame('app_', $container->getParameter('nowo_marketing_kit.doctrine.table_prefix'));
        self::assertSame(['ROLE_ADMIN'], $container->getParameter('nowo_marketing_kit.security.access_roles'));
        self::assertFalse($container->getParameter('nowo_marketing_kit.security.allow_unauthenticated'));
        self::assertSame('@NowoMarketingKitBundle/admin/layout.html.twig', $container->getParameter('nowo_marketing_kit.web_ui.layout_template'));
        self::assertSame('none', $container->getParameter('nowo_marketing_kit.web_ui.css_framework'));
        self::assertTrue($container->hasDefinition(TablePrefixListener::class));
        self::assertTrue($container->hasDefinition(MarketingScriptRenderer::class));
        self::assertTrue($container->hasDefinition(MarketingKitExtension::class));
        self::assertTrue($container->hasAlias(MarketingKitAccessCheckerInterface::class));
    }

    public function testLoadWithoutTablePrefixSkipsListener(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $extension = new NowoMarketingKitExtension();
        $extension->load([[]], $container);

        self::assertFalse($container->hasDefinition(TablePrefixListener::class));
        self::assertSame('', $container->getParameter('nowo_marketing_kit.doctrine.table_prefix'));
    }

    public function testLoadUsesCustomAccessCheckerAliasWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setDefinition('app.marketing_access_checker', new Definition());

        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'security' => [
                'access_checker' => 'app.marketing_access_checker',
            ],
        ]], $container);

        self::assertSame(
            'app.marketing_access_checker',
            (string) $container->getAlias(MarketingKitAccessCheckerInterface::class),
        );
    }

    public function testLoadUsesAllowAllCheckerWhenUnauthenticatedAccessIsEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'security' => [
                'allow_unauthenticated' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_marketing_kit.access_checker.allow_all'));
        self::assertSame(
            'nowo_marketing_kit.access_checker.allow_all',
            (string) $container->getAlias(MarketingKitAccessCheckerInterface::class),
        );
    }

    public function testLoadWiresAuthorizationCheckerIntoDefaultAccessCheckerWhenPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setDefinition('security.authorization_checker', new Definition());

        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'security' => [
                'access_roles' => ['ROLE_MANAGER'],
            ],
        ]], $container);

        $definition = $container->getDefinition('nowo_marketing_kit.access_checker.default');
        self::assertSame(
            ConfigurableMarketingKitAccessChecker::class,
            $definition->getClass(),
        );
        self::assertSame(['ROLE_MANAGER'], $definition->getArgument('$accessRoles'));
        self::assertEquals(new Reference('security.authorization_checker'), $definition->getArgument('$authorizationChecker'));
    }

    public function testLoadThrowsWhenSecurityBundleMissingAndUnauthenticatedAccessDisabled(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoMarketingKitExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('symfony/security-bundle');

        $extension->load([[
            'security' => [
                'allow_unauthenticated' => false,
            ],
        ]], $container);
    }

    public function testLoadDetectsSecurityBundleViaRegisteredExtension(): void
    {
        $container         = new ContainerBuilder();
        $securityExtension = $this->createMock(ExtensionInterface::class);
        $securityExtension->method('getAlias')->willReturn('security');
        $container->registerExtension($securityExtension);

        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'security' => [
                'allow_unauthenticated' => false,
                'access_roles'          => ['ROLE_ADMIN'],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_marketing_kit.access_checker.default'));
        self::assertSame(
            'nowo_marketing_kit.access_checker.default',
            (string) $container->getAlias(MarketingKitAccessCheckerInterface::class),
        );
    }

    public function testPrependIsNoOpWhenFormKitAndUiKitAreAbsent(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoMarketingKitExtension();
        $extension->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependSeedsFormKitProfileAndCssFrameworkWhenMissing(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_form_kit');

        $extension = new NowoMarketingKitExtension();
        $extension->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertNotEmpty($configs);
        $seed = $configs[0];
        self::assertSame('bootstrap', $seed['css_framework']);
        self::assertArrayHasKey('marketing_kit', $seed['profiles']);
        self::assertSame('NowoMarketingKitBundle', $seed['profiles']['marketing_kit']['translation_domain']);
        self::assertSame('form-select', $seed['profiles']['marketing_kit']['field_types']['choice']['attr']['class']);
    }

    public function testPrependSkipsFormKitKeysAlreadyConfiguredByHost(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_form_kit');
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'tailwind',
            'profiles'      => [
                'marketing_kit' => [
                    'alias' => 'custom_mk',
                ],
            ],
        ]);

        $before = $container->getExtensionConfig('nowo_form_kit');
        (new NowoMarketingKitExtension())->prepend($container);
        $after = $container->getExtensionConfig('nowo_form_kit');

        self::assertSame($before, $after);
    }

    public function testPrependSeedsOnlyMissingFormKitProfileWhenCssFrameworkExists(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_form_kit');
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'tabler',
            'profiles'      => 'not-an-array',
        ]);

        (new NowoMarketingKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertArrayHasKey('profiles', $configs[0]);
        self::assertArrayHasKey('marketing_kit', $configs[0]['profiles']);
        self::assertArrayNotHasKey('css_framework', $configs[0]);
    }

    public function testPrependSeedsOnlyMissingFormKitCssFrameworkWhenProfileExists(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_form_kit');
        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [
                'marketing_kit' => ['alias' => 'marketing_kit'],
            ],
        ]);

        (new NowoMarketingKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertSame(['css_framework' => 'bootstrap'], $configs[0]);
        self::assertArrayNotHasKey('profiles', $configs[0]);
    }

    public function testPrependSeedsUiKitFromWebUiDefaults(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_marketing_kit', [
            'web_ui' => [
                'css_framework' => 'bootstrap',
            ],
        ]);

        (new NowoMarketingKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertSame([
            'css_framework' => 'bootstrap5',
            'icon_set'      => 'bootstrap-icons',
        ], $configs[0]);
    }

    public function testPrependSkipsUiKitWhenHostAlreadyConfiguredBothKeys(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'foundation',
            'icon_set'      => 'bootstrap-icons',
        ]);
        // Defensive branch: ignore non-array host config entries.
        $this->appendRawExtensionConfig($container, 'nowo_ui_kit', 'invalid');

        $before = array_values(array_filter(
            $container->getExtensionConfig('nowo_ui_kit'),
            static fn (mixed $cfg): bool => is_array($cfg),
        ));
        (new NowoMarketingKitExtension())->prepend($container);
        $after = array_values(array_filter(
            $container->getExtensionConfig('nowo_ui_kit'),
            static fn (mixed $cfg): bool => is_array($cfg),
        ));

        self::assertSame($before, $after);
    }

    public function testPrependSeedsOnlyMissingUiKitIconSet(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'tailwind',
        ]);

        (new NowoMarketingKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertSame(['icon_set' => 'bootstrap-icons'], $configs[0]);
        self::assertArrayNotHasKey('css_framework', $configs[0]);
    }

    public function testPrependSeedsOnlyMissingUiKitCssFramework(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'icon_set' => 'fontawesome',
        ]);

        (new NowoMarketingKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertSame(['css_framework' => 'none'], $configs[0]);
        self::assertArrayNotHasKey('icon_set', $configs[0]);
    }

    private function registerNamedExtension(ContainerBuilder $container, string $alias): void
    {
        $named = $this->createMock(ExtensionInterface::class);
        $named->method('getAlias')->willReturn($alias);
        $container->registerExtension($named);
    }

    private function appendRawExtensionConfig(ContainerBuilder $container, string $alias, mixed $config): void
    {
        $property = new ReflectionProperty(ContainerBuilder::class, 'extensionConfigs');
        /** @var array<string, list<mixed>> $configs */
        $configs           = $property->getValue($container);
        $configs[$alias][] = $config;
        $property->setValue($container, $configs);
    }
}
