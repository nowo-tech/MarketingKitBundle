<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\DependencyInjection;

use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\PrivateEntityManagerSourcePass;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use Nowo\MarketingKitBundle\EventSubscriber\MarketingKitEntityManagerClearSubscriber;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;
use function class_exists;

/**
 * 2.0 (REQ-ARCH-001 rule 6): the private entity manager is the only mode in which the bundle uses the application's
 * default manager. The setting table of UPGRADING "To 2.0.0", row by row: which manager the bundle writes through,
 * whether the application's manager can be flushed by it, and the notice / build error.
 */
final class PrivateEntityManagerExtensionTest extends TestCase
{
    /**
     * Zero config (1.x: the bundle flushed the application's default manager, with a notice): the private manager on
     * the default manager, no notice, no host-flush probe.
     */
    public function testZeroConfigWritesThroughThePrivateManagerWithoutNotice(): void
    {
        $container = $this->compile([], 'main');

        $this->assertPrivateWiring($container, 'main');
        self::assertFalse(class_exists('Nowo\\MarketingKitBundle\\Doctrine\\MarketingKitHostFlushReporter'), 'the 1.14 probe is gone');
        self::assertFalse($container->hasDefinition('Nowo\\MarketingKitBundle\\Doctrine\\MarketingKitHostFlushReporter'));
    }

    /**
     * Audit XD-4 / lesson W0-1: `doctrine.entity_manager` naming the application's default manager was the 1.x host mode;
     * 2.0 uses the private manager on it.
     */
    #[DataProvider('defaultManagerNames')]
    public function testTheDefaultManagerNamedAsEntityManagerUsesThePrivateManager(string $default): void
    {
        $container = $this->compile(['doctrine' => ['entity_manager' => $default]], $default);

        $this->assertPrivateWiring($container, $default);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultManagerNames(): iterable
    {
        yield 'named "default"' => ['default'];
        yield 'a default manager not named "default"' => ['main'];
    }

    /**
     * `private_entity_manager: true` (the 1.14 opt-in): accepted, a deprecated no-op without a dedicated manager.
     */
    #[IgnoreDeprecations]
    public function testTrueIsADeprecatedNoOp(): void
    {
        $this->expectUserDeprecationMessageMatches('/Since nowo-tech\/marketing-kit-bundle 2\.0: The "private_entity_manager" option at "nowo_marketing_kit\.doctrine" has no effect since 2\.0/');

        $container = $this->compile(['doctrine' => ['private_entity_manager' => true]], 'default');

        $this->assertPrivateWiring($container, 'default');
    }

    /**
     * `private_entity_manager: false` (the 1.x mode, deprecated in 1.14): a build error that says what to do.
     */
    public function testFalseFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_marketing_kit.doctrine.private_entity_manager: false is no longer supported');

        $this->load(['doctrine' => ['private_entity_manager' => false]]);
    }

    public function testFalseWithADedicatedManagerFailsTheBuildToo(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('doctrine.entity_manager "marketing" stays');

        $this->load(['doctrine' => ['entity_manager' => 'marketing', 'private_entity_manager' => false]]);
    }

    /**
     * A dedicated manager (the DDD option, unchanged since 1.10): the bundle uses it directly, no notice; the private
     * manager's services are removed and the repository gets the application's registry back.
     */
    public function testADedicatedManagerIsUsedDirectly(): void
    {
        $container = $this->compile(['doctrine' => ['entity_manager' => 'marketing']], 'main', ['marketing']);

        self::assertFalse($container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_REGISTRY_SERVICE));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        self::assertArrayNotHasKey('$registry', $container->getDefinition(MarketingToolRepository::class)->getArguments());
        $dedicated = 'doctrine.orm.marketing_entity_manager';
        self::assertSame($dedicated, (string) $container->getDefinition(MarketingToolAdminService::class)->getArgument('$entityManager'));
        self::assertSame($dedicated, (string) $container->getDefinition(MarketingToolAdminController::class)->getArgument('$entityManager'));
        $unit = $container->getDefinition(MarketingToolWriteUnit::class);
        self::assertSame($dedicated, (string) $unit->getArgument('$entityManager'));
        self::assertSame('doctrine', (string) $unit->getArgument('$registry'));
        self::assertSame('marketing', $unit->getArgument('$managerName'));
        self::assertTrue($unit->getArgument('$ownManager'));
        $closed = $container->getDefinition(ClosedEntityManagerSubscriber::class);
        self::assertSame('doctrine', (string) $closed->getArgument('$registry'));
        self::assertSame('marketing', $closed->getArgument('$entityManagerName'));
        self::assertSame([], $this->resettables($container));
    }

    /**
     * `true` with a dedicated manager keeps the 1.14 behaviour (the private manager on the dedicated one); the key is
     * deprecated (its removal in 3.0 then switches to the dedicated manager directly).
     */
    #[IgnoreDeprecations]
    public function testTrueWithADedicatedManagerKeepsThePrivateManagerOnIt(): void
    {
        $this->expectUserDeprecationMessageMatches('/has no effect since 2\.0 without a dedicated doctrine\.entity_manager/');

        $container = $this->compile(['doctrine' => ['entity_manager' => 'marketing', 'private_entity_manager' => true]], 'main', ['marketing']);

        $this->assertPrivateWiring($container, 'marketing');
    }

    public function testTheSourcePassRefusesAnUnknownManager(): void
    {
        $container = $this->load(['doctrine' => ['entity_manager' => 'missing']]);
        $container->setParameter('doctrine.default_entity_manager', 'missing');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_marketing_kit.doctrine.entity_manager is "missing"');

        (new PrivateEntityManagerSourcePass())->process($container);
    }

    public function testTheSourcePassFallsBackToDefaultWithoutDoctrineParameters(): void
    {
        $container = $this->load([]);
        $container->getParameterBag()->remove(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER);
        $container->getParameterBag()->remove(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER);

        (new PrivateEntityManagerSourcePass())->process($container);

        self::assertSame('default', $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'default']], $container->getDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testTheSourcePassDoesNothingWithoutThePrivateManager(): void
    {
        $container = $this->load([]);
        $container->removeDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE);
        $container->getParameterBag()->remove(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER);

        (new PrivateEntityManagerSourcePass())->process($container);

        self::assertFalse($container->hasParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    public function testDedicatedWiringToleratesMissingDefinitions(): void
    {
        $container = new ContainerBuilder();

        NowoMarketingKitExtension::useDedicatedEntityManager($container, 'marketing');

        self::assertFalse($container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
    }

    private function assertPrivateWiring(ContainerBuilder $container, string $source): void
    {
        self::assertTrue($container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertSame($source, $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        $manager = $container->getDefinition(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $manager->getClass());
        self::assertTrue($manager->hasTag(PrivateEntityManager::TAG), 'reset by transaction-doctrine\'s boundary listener');
        self::assertTrue($manager->hasTag('kernel.reset'));
        $sourceParameter = '%' . NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
        self::assertSame($sourceParameter, $manager->getArgument(1));
        self::assertSame(NowoMarketingKitExtension::BUNDLE_NAME, $manager->getArgument(3));

        $private  = NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE;
        $registry = NowoMarketingKitExtension::PRIVATE_REGISTRY_SERVICE;
        self::assertSame(NowoMarketingKitExtension::OWN_NAMESPACES, $container->getDefinition($registry)->getArgument(2));
        self::assertSame($registry, (string) $container->getDefinition(MarketingToolRepository::class)->getArgument('$registry'));
        self::assertSame($private, (string) $container->getDefinition(MarketingToolAdminService::class)->getArgument('$entityManager'));
        self::assertSame($private, (string) $container->getDefinition(MarketingToolAdminController::class)->getArgument('$entityManager'));
        $unit = $container->getDefinition(MarketingToolWriteUnit::class);
        self::assertSame($private, (string) $unit->getArgument('$entityManager'));
        self::assertSame($registry, (string) $unit->getArgument('$registry'));
        self::assertSame($sourceParameter, $unit->getArgument('$managerName'));
        self::assertTrue($unit->getArgument('$ownManager'), 'never the application\'s-manager path');
        self::assertArrayNotHasKey('$hostFlushReporter', $unit->getArguments());
        self::assertSame($registry, (string) $container->getDefinition(ClosedEntityManagerSubscriber::class)->getArgument('$registry'));
        self::assertSame([$private], $this->resettables($container), 'dropped at every request, message and command boundary');
        $middleware = $container->getDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE);
        self::assertSame(PrivateEntityManagerMiddleware::class, $middleware->getClass());
        self::assertSame([['connection' => 'app']], $middleware->getTag('doctrine.middleware'), 'the source manager\'s connection only');
    }

    /**
     * Loads the extension and runs the source pass with `$default` as the application's default manager.
     *
     * @param array<string, mixed> $config
     * @param list<string> $otherManagers
     */
    private function compile(array $config, string $default, array $otherManagers = []): ContainerBuilder
    {
        $container = $this->load($config);
        $managers  = [$default => 'doctrine.orm.' . $default . '_entity_manager'];
        foreach ($otherManagers as $name) {
            $managers[$name] = 'doctrine.orm.' . $name . '_entity_manager';
        }
        $container->setParameter('doctrine.default_entity_manager', $default);
        $container->setParameter('doctrine.entity_managers', $managers);
        foreach ($managers as $id) {
            $container->setDefinition($id, new Definition('stdClass', [new Reference('doctrine.dbal.app_connection')]));
        }

        (new PrivateEntityManagerSourcePass())->process($container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        (new NowoMarketingKitExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @return list<string>
     */
    private function resettables(ContainerBuilder $container): array
    {
        $argument = $container->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->getArgument('$resettables');
        self::assertInstanceOf(IteratorArgument::class, $argument);

        return array_values(array_map(static fn (mixed $reference): string => (string) $reference, $argument->getValues()));
    }
}
