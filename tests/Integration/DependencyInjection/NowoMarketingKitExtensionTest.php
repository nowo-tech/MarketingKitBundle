<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\DependencyInjection;

use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\PrivateEntityManagerSourcePass;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Security\ConfigurableMarketingKitAccessChecker;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Twig\MarketingKitExtension;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_values;

/**
 * Zero configuration: 2.0 writes through the bundle's private entity manager without any notice (the wiring is
 * asserted in {@see PrivateEntityManagerExtensionTest}).
 */
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
            'doctrine'               => ['table_prefix' => 'app_'],
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
        self::assertNull($container->getParameter('nowo_marketing_kit.web_ui.layout_template'));
        self::assertTrue($container->getParameter('nowo_marketing_kit.web_ui.enabled'));
        self::assertSame('/admin/marketing', $container->getParameter('nowo_marketing_kit.web_ui.path_prefix'));
        self::assertSame('admin/marketing', $container->getParameter('nowo_marketing_kit.web_ui.route_path'));
        self::assertSame(['index' => '@NowoMarketingKitBundle/admin/index.html.twig', 'form' => '@NowoMarketingKitBundle/admin/form.html.twig'], $container->getParameter('nowo_marketing_kit.web_ui.templates'));
        self::assertSame(['@NowoUiKit/form/nowo_ui_layout.html.twig'], $container->getParameter('nowo_marketing_kit.web_ui.form_themes'));
        self::assertFalse($container->hasParameter('nowo_marketing_kit.web_ui.css_framework'), '2.0: removed with the key');
        self::assertFalse($container->hasParameter('nowo_marketing_kit.doctrine.connection'), '2.0: removed with the key');
        self::assertTrue($container->hasDefinition(TablePrefixListener::class));
        self::assertTrue($container->hasDefinition(MarketingScriptRenderer::class));
        self::assertTrue($container->hasDefinition(MarketingKitExtension::class));
        self::assertTrue($container->hasAlias(MarketingKitAccessCheckerInterface::class));
    }

    /**
     * 1.13 (REQ-RUNTIME-003): zero config registers the conflict answer (transaction-doctrine's policy service when
     * present), the bundle's commit ledger and middleware, and the write unit on the bundle's private manager (2.0).
     */
    public function testLoadRegistersTheConflictAnswerAndTheWriteUnit(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        (new NowoMarketingKitExtension())->load([[]], $container);

        self::assertSame('bundle', $container->getParameter(NowoMarketingKitExtension::COMMIT_TRACKING_PARAMETER));
        self::assertSame(CommitLedger::class, $container->getDefinition(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE)->getClass());
        self::assertTrue($container->getDefinition(NowoMarketingKitExtension::COMMIT_TRACKING_MIDDLEWARE)->hasTag('doctrine.middleware'));
        $listener = $container->getDefinition(MarketingToolConflictListener::class);
        $policy   = $listener->getArgument(0);
        self::assertInstanceOf(Reference::class, $policy);
        self::assertSame(ConflictResponsePolicy::class, (string) $policy);
        self::assertSame(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE, (string) $listener->getArgument(1));
        $tags = $listener->getTag('kernel.event_listener');
        self::assertCount(2, $tags);
        $exception = array_values(array_filter($tags, static fn (array $tag): bool => ($tag['event'] ?? null) === 'kernel.exception'));
        // Audit US-3: before the framework's ErrorListener::logKernelException() (priority 0), so an expected 409 is
        // not logged as a CRITICAL storage error.
        self::assertGreaterThan(0, $exception[0]['priority'] ?? 0);
        self::assertTrue($container->hasDefinition(MarketingToolWriteUnit::class));
        self::assertTrue($container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$ownManager'));
        self::assertSame(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE, (string) $container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$entityManager'));
    }

    public function testLoadHonoursTheConflictResponseOptionsAndTheBundleManager(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        (new NowoMarketingKitExtension())->load([[
            'doctrine'          => ['entity_manager' => 'marketing'],
            'conflict_response' => ['status' => 503, 'commit_tracking' => 'off'],
        ]], $container);

        $policy = $container->getDefinition(MarketingToolConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([503, ConflictResponsePolicy::DEFAULT_RETRY_AFTER], $policy->getArguments());
        self::assertNull($container->getDefinition(MarketingToolConflictListener::class)->getArgument(1), 'no ledger');
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::COMMIT_TRACKING_MIDDLEWARE));
        // A dedicated manager (the DDD option) is put back by the source pass, which knows the default manager's name.
        (new PrivateEntityManagerSourcePass())->process($container);
        self::assertSame('doctrine.orm.marketing_entity_manager', (string) $container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$entityManager'));
    }

    /**
     * Audit US-2: without the admin routes nothing answers a conflict: no ledger, middleware or listener (the host's
     * connection is not wrapped); the write unit stays for host code calling the admin service.
     */
    public function testLoadSkipsTheConflictAnswerWhenTheWebUiIsDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        (new NowoMarketingKitExtension())->load([['web_ui' => ['enabled' => false]]], $container);

        self::assertFalse($container->hasDefinition(MarketingToolConflictListener::class));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertTrue($container->hasDefinition(MarketingToolWriteUnit::class));
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
        self::assertSame(['attr' => [], 'row_attr' => []], $seed['profiles']['marketing_kit']['defaults']);
        self::assertArrayNotHasKey('field_types', $seed['profiles']['marketing_kit']);
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

    /**
     * 2.0: the `web_ui.css_framework` / `icon_set` keys are gone; the bundle still seeds the 1.x zero-config result
     * (none / bootstrap-icons) into the keys the host left unset, so an application that never set them renders as
     * before.
     */
    public function testPrependSeedsUiKitWithTheZeroConfigDefaults(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');

        (new NowoMarketingKitExtension())->prepend($container);

        self::assertSame(['css_framework' => 'none', 'icon_set' => 'bootstrap-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependSkipsUiKitWhenHostAlreadyConfiguredBothKeys(): void
    {
        $container = new ContainerBuilder();
        $this->registerNamedExtension($container, 'nowo_ui_kit');
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'foundation',
            'icon_set'      => 'bootstrap-icons',
        ]);

        $before = $container->getExtensionConfig('nowo_ui_kit');
        (new NowoMarketingKitExtension())->prepend($container);

        self::assertSame($before, $container->getExtensionConfig('nowo_ui_kit'));
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
}
