<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\DependencyInjection;

use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\PrivateEntityManagerSourcePass;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Doctrine\MarketingKitHostFlushReporter;
use Nowo\MarketingKitBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use Nowo\MarketingKitBundle\EventSubscriber\MarketingKitEntityManagerClearSubscriber;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;

/**
 * 1.14 (REQ-ARCH-001 rule 6): `doctrine.private_entity_manager`, its notices and the host-flush probe wiring.
 */
final class PrivateEntityManagerExtensionTest extends TestCase
{
    #[IgnoreDeprecations]
    public function testUnsetTriggersTheNoticeAndKeepsTheHostModeWithTheProbe(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/marketing-kit-bundle 1.14: ' . NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION);

        $container = $this->load([]);

        self::assertFalse($container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        $this->assertProbeEnabled($container, true);
        self::assertFalse($container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$ownManager'));
        self::assertSame([MarketingKitHostFlushReporter::class], $this->resettables($container));
    }

    #[IgnoreDeprecations]
    public function testExplicitFalseTriggersTheRemovalNoticeAndKeepsTheProbe(): void
    {
        $this->expectUserDeprecationMessage('Since nowo-tech/marketing-kit-bundle 1.14: ' . NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION);

        $container = $this->load(['doctrine' => ['private_entity_manager' => false]]);

        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        $this->assertProbeEnabled($container, true);
    }

    /**
     * A dedicated manager (the DDD option, kept in 2.0) gets no notice; the write unit never probes it (own manager).
     */
    public function testADedicatedEntityManagerGetsNoNotice(): void
    {
        $container = $this->load(['doctrine' => ['entity_manager' => 'marketing', 'private_entity_manager' => false]]);
        $this->load(['doctrine' => ['entity_manager' => 'marketing']]);

        self::assertSame('doctrine.orm.marketing_entity_manager', (string) $container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$entityManager'));
    }

    /**
     * Audit XD-4: `doctrine.entity_manager` naming the application's default manager writes through that manager as when
     * unset, so the source pass triggers the same notice (the extension cannot know the default manager's name) and
     * the write unit keeps the application's-manager path with the probe.
     */
    #[IgnoreDeprecations]
    public function testTheDefaultManagerNamedAsEntityManagerTriggersTheUnsetNotice(): void
    {
        $container = $this->load(['doctrine' => ['entity_manager' => 'main']]);
        $container->setParameter('doctrine.default_entity_manager', 'main');

        $this->expectUserDeprecationMessage('Since nowo-tech/marketing-kit-bundle 1.14: ' . NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION);
        (new PrivateEntityManagerSourcePass())->process($container);

        $this->assertProbeEnabled($container, true);
        self::assertFalse($container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$ownManager'));
    }

    #[IgnoreDeprecations]
    public function testTheDefaultManagerNamedWithExplicitFalseTriggersTheRemovalNotice(): void
    {
        $container = $this->load(['doctrine' => ['entity_manager' => 'default', 'private_entity_manager' => false]]);

        $this->expectUserDeprecationMessage('Since nowo-tech/marketing-kit-bundle 1.14: ' . NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION);
        (new PrivateEntityManagerSourcePass())->process($container); // no doctrine.default_entity_manager: "default"
    }

    public function testTheSourcePassTriggersNothingForADedicatedManagerOrThePrivateMode(): void
    {
        $dedicated = $this->load(['doctrine' => ['entity_manager' => 'marketing']]);
        $dedicated->setParameter('doctrine.default_entity_manager', 'main');
        (new PrivateEntityManagerSourcePass())->process($dedicated);

        $private = $this->load(['doctrine' => ['entity_manager' => 'main', 'private_entity_manager' => true]]);
        $private->setParameter('doctrine.default_entity_manager', 'main');
        $private->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager']);
        $private->setDefinition('doctrine.orm.main_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.default_connection')]));
        (new PrivateEntityManagerSourcePass())->process($private);

        $withoutSetting = $this->load(['doctrine' => ['entity_manager' => 'default']]);
        $withoutSetting->getParameterBag()->remove(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER);
        (new PrivateEntityManagerSourcePass())->process($withoutSetting);

        self::assertSame('main', $private->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    public function testTrueWiresThePrivateManagerWithoutNotice(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);

        self::assertTrue($container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER));
        $manager = $container->getDefinition(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $manager->getClass());
        self::assertTrue($manager->hasTag(PrivateEntityManager::TAG), 'reset by transaction-doctrine\'s boundary listener');
        self::assertTrue($manager->hasTag('kernel.reset'));
        $source = '%' . NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER . '%';
        self::assertSame($source, $manager->getArgument(1));

        $private  = NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE;
        $registry = NowoMarketingKitExtension::PRIVATE_REGISTRY_SERVICE;
        self::assertSame($registry, (string) $container->getDefinition(MarketingToolRepository::class)->getArgument('$registry'));
        self::assertSame($private, (string) $container->getDefinition(MarketingToolAdminService::class)->getArgument('$entityManager'));
        self::assertSame($private, (string) $container->getDefinition(MarketingToolAdminController::class)->getArgument('$entityManager'));
        $unit = $container->getDefinition(MarketingToolWriteUnit::class);
        self::assertSame($private, (string) $unit->getArgument('$entityManager'));
        self::assertSame($registry, (string) $unit->getArgument('$registry'));
        self::assertSame($source, $unit->getArgument('$managerName'));
        self::assertTrue($unit->getArgument('$ownManager'));
        self::assertSame($registry, (string) $container->getDefinition(ClosedEntityManagerSubscriber::class)->getArgument('$registry'));
        $this->assertProbeEnabled($container, false);
        self::assertSame([MarketingKitHostFlushReporter::class, $private], $this->resettables($container));
        self::assertSame(PrivateEntityManagerMiddleware::class, $container->getDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getClass());
    }

    public function testTheSourcePassResolvesTheDefaultManagerAndTagsTheMiddlewareForItsConnection(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager', 'other' => 'doctrine.orm.other_entity_manager']);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.app_connection')]));

        (new PrivateEntityManagerSourcePass())->process($container);

        self::assertSame('main', $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'app']], $container->getDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testTheSourcePassFollowsTheEntityManagerOptionAndRefusesAnUnknownOne(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true, 'entity_manager' => 'missing']]);
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_marketing_kit.doctrine.entity_manager is "missing"');

        (new PrivateEntityManagerSourcePass())->process($container);
    }

    public function testTheSourcePassFallsBackToDefaultWithoutDoctrineParameters(): void
    {
        $container = $this->load(['doctrine' => ['private_entity_manager' => true]]);
        $container->getParameterBag()->remove(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER);

        (new PrivateEntityManagerSourcePass())->process($container);

        self::assertSame('default', $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'default']], $container->getDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testTheSourcePassDoesNothingInHostMode(): void
    {
        $container = $this->load(['doctrine' => ['entity_manager' => 'marketing']]);

        (new PrivateEntityManagerSourcePass())->process($container);

        self::assertSame('marketing', $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
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

    private function assertProbeEnabled(ContainerBuilder $container, bool $enabled): void
    {
        $reporter = $container->getDefinition(MarketingKitHostFlushReporter::class);
        self::assertSame($enabled, $reporter->getArgument(0));
        self::assertSame(HostFlushProbe::SERVICE_ID, (string) $reporter->getArgument(1));
        self::assertTrue($reporter->hasTag('kernel.reset'));
        self::assertSame(MarketingKitHostFlushReporter::class, (string) $container->getDefinition(MarketingToolWriteUnit::class)->getArgument('$hostFlushReporter'));
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
