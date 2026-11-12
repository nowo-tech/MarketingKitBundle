<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MarketingKitBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\MarketingKitBundle\EventSubscriber\MarketingKitEntityManagerClearSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit LD: the named entity manager must exist and map the bundle entities.
 */
final class EntityManagerOptionPassTest extends TestCase
{
    private const ENTITIES = 'Nowo\\MarketingKitBundle\\Entity';

    public function testNoOptionIsANoop(): void
    {
        $container = $this->container(null, ['default' => [self::ENTITIES]]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no such Doctrine entity manager');

        (new EntityManagerOptionPass())->process($this->container('missing', ['default' => [self::ENTITIES]]));
    }

    public function testManagerThatDoesNotMapTheEntitiesFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');

        (new EntityManagerOptionPass())->process($this->container('bundle', ['default' => [self::ENTITIES], 'bundle' => ['App\\Entity']]));
    }

    public function testParentNamespaceMappingCounts(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity'], 'bundle' => ['Nowo']]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
    }

    public function testNamedManagerKeepsTheEntities(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity', self::ENTITIES], 'bundle' => [self::ENTITIES], 'other' => []]);
        $container->removeDefinition('doctrine.orm.other_metadata_driver');

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    public function testOtherDriverCallsAreKept(): void
    {
        $container = $this->container('bundle', ['default' => [], 'bundle' => []]);
        $container->getDefinition('doctrine.orm.bundle_metadata_driver')
            ->addMethodCall('setDefaultDriver', [new Reference('driver')])
            ->addMethodCall('addDriver', [new Reference('driver'), self::ENTITIES]);

        (new EntityManagerOptionPass())->process($container);

        self::assertCount(2, $container->getDefinition('doctrine.orm.bundle_metadata_driver')->getMethodCalls());
    }

    /**
     * REQ-RUNTIME-002 rule 4 (MT-M-L3): the boundary subscriber gets the bundle-owned manager as an
     * IGNORE_ON_UNINITIALIZED reference, and nothing when doctrine.entity_manager names the host's default manager.
     */
    public function testWiresTheBoundarySubscriberOnlyForANonDefaultManager(): void
    {
        $container = $this->container('bundle', ['default' => ['App\\Entity'], 'bundle' => [self::ENTITIES]]);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition(MarketingKitEntityManagerClearSubscriber::class, (new Definition())->setArgument('$entityManagers', new IteratorArgument([])));
        (new EntityManagerOptionPass())->process($container);

        $managers = $container->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->getArgument('$entityManagers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        $references = $managers->getValues();
        self::assertCount(1, $references);
        self::assertInstanceOf(Reference::class, $references[0]);
        self::assertSame('doctrine.orm.bundle_entity_manager', (string) $references[0]);
        self::assertSame(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE, $references[0]->getInvalidBehavior());

        $default = $this->container('default', ['default' => [self::ENTITIES]]);
        $default->setParameter('doctrine.default_entity_manager', 'default');
        $default->setDefinition(MarketingKitEntityManagerClearSubscriber::class, (new Definition())->setArgument('$entityManagers', new IteratorArgument([])));
        (new EntityManagerOptionPass())->process($default);
        $managers = $default->getDefinition(MarketingKitEntityManagerClearSubscriber::class)->getArgument('$entityManagers');
        self::assertInstanceOf(IteratorArgument::class, $managers);
        self::assertSame([], $managers->getValues(), 'The host\'s default manager is never cleared by the bundle.');
    }

    /**
     * @param array<string, list<string>> $managers manager => mapped namespaces
     */
    private function container(?string $option, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $option);
        $names = [];
        foreach ($managers as $manager => $namespaces) {
            $names[$manager] = 'doctrine.orm.' . $manager . '_entity_manager';
            $chain           = $container->register('doctrine.orm.' . $manager . '_metadata_driver');
            foreach ($namespaces as $namespace) {
                $chain->addMethodCall('addDriver', [new Reference('driver'), $namespace]);
            }
        }
        $container->setParameter('doctrine.entity_managers', $names);

        return $container;
    }
}
