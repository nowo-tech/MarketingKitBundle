<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\CommitTrackingPass;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit UE-CF-1: the bundle's commit-tracking middleware never wraps the host's other connections by default, and is
 * not registered at all when transaction-doctrine's commit_detection already counts commits.
 */
#[CoversClass(CommitTrackingPass::class)]
final class CommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = NowoMarketingKitExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('bundle', 'marketing_kit');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.mk'), 'the bundle connection');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host connection');
        self::assertSame(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(MarketingToolConflictListener::class)->getArgument(1));
    }

    public function testWithoutDoctrineEntityManagerTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container('bundle', null);
        $container->setParameter('doctrine.default_entity_manager', 'marketing_kit');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.mk'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('bundle', 'missing');
        $container->setParameter('doctrine.default_connection', 'mk');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.mk'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle', null);
        $container->getParameterBag()->remove(NowoMarketingKitExtension::COMMIT_TRACKING_PARAMETER);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter: bundle mode; no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.mk'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all', 'marketing_kit');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.mk'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all', 'marketing_kit');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.mk'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(MarketingToolConflictListener::class)->getArgument(1));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle', 'marketing_kit');
        $container->removeDefinition(MarketingToolConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        (new CommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    public function testOffRegistersNothingAndThePassLeavesTheContainerAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoMarketingKitExtension::COMMIT_TRACKING_PARAMETER, 'off');

        (new CommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    private function container(string $mode, ?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoMarketingKitExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $entityManager);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'mk' => 'doctrine.dbal.mk_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.mk_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.marketing_kit_entity_manager', new Definition(null, [new Reference('doctrine.dbal.mk_connection')]));
        $container->setDefinition(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(MarketingToolConflictListener::class, new Definition(MarketingToolConflictListener::class, [null, new Reference(NowoMarketingKitExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        (new CommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
