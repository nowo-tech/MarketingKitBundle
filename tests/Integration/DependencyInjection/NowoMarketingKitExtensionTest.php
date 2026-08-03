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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

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
}
