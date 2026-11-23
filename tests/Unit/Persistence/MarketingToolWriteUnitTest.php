<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Persistence;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostChild;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostParent;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostProfile;
use Nowo\MarketingKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function dirname;
use function sprintf;

#[CoversClass(MarketingToolWriteUnit::class)]
final class MarketingToolWriteUnitTest extends TestCase
{
    use CollectsDeprecations;

    private const BUNDLE = ['Nowo\\MarketingKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    public function testTheHostManagerUnitFlushesTheToolAndCommits(): void
    {
        [$registry, $manager] = $this->hostManager();
        $unit                 = new MarketingToolWriteUnit($manager, $registry);

        $code = $unit->run(static function (EntityManagerInterface $em): string {
            $tool = (new MarketingTool())->setCode('gtm');
            $em->persist($tool);

            return $tool->getCode();
        });

        self::assertSame('gtm', $code);
        self::assertFalse($manager->getConnection()->isTransactionActive());
        self::assertSame(['gtm'], $manager->getConnection()->fetchFirstColumn('SELECT code FROM marketing_tool'));
    }

    public function testAToolFormBoundBeforeTheUnitIsTheBundlesOwnPendingWork(): void
    {
        [$registry, $manager] = $this->hostManager();
        $tool                 = (new MarketingTool())->setCode('gtm');
        $manager->persist($tool);
        $manager->flush();

        $tool->setSortOrder(512); // bound by the form before the unit starts
        (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);

        self::assertSame(512, (int) $manager->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'));
        self::assertSame(2, $tool->getVersion(), 'the optimistic version moved on');
    }

    public function testPendingHostWorkRefusesTheUnitOnTheHostManager(): void
    {
        [$registry, $manager] = $this->hostManager();
        $note                 = new HostNote();
        $manager->persist($note);

        try {
            (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);
            self::fail('The host work must not be carried.');
        } catch (LogicException $refused) {
            self::assertSame(MarketingToolWriteUnit::HOST_PENDING_MESSAGE, $refused->getMessage());
        }

        self::assertTrue($manager->contains($note), 'the host work is left alone');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    /**
     * Audit US-1: a refused unit also forgets the tool the form bound before it, so a later flush of the host's own
     * work never writes the refused values; the host's entity stays managed.
     */
    public function testARefusedUnitForgetsTheBoundToolButNotTheHostWork(): void
    {
        [$registry, $manager] = $this->hostManager();
        $tool                 = (new MarketingTool())->setCode('gtm');
        $manager->persist($tool);
        $manager->flush();

        $tool->setSortOrder(99); // bound by the form
        $note = new HostNote();
        $manager->persist($note); // the host's pending work

        try {
            (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);
            self::fail('The host work must not be carried.');
        } catch (LogicException) {
        }

        self::assertFalse($manager->contains($tool), 'the refused tool is forgotten');
        self::assertTrue($manager->contains($note), 'the host work is left alone');
        $manager->flush(); // the host flushes its own work later
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'), 'the refused value never reached the row');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    public function testPendingHostCollectionChangesRefuseTheUnit(): void
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([new stdClass()]);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        $this->expectExceptionObject(new LogicException(MarketingToolWriteUnit::HOST_PENDING_MESSAGE));
        (new MarketingToolWriteUnit($manager))->run(static fn (EntityManagerInterface $em): null => null);
    }

    /**
     * Audit XD-5: an inverse collection cleared with orphan removal schedules orphan removals only (no entity deletion,
     * no collection change); the unit refuses them, so its flush never deletes the host's children.
     */
    public function testAClearedOrphanRemovalCollectionRefusesTheUnit(): void
    {
        [$registry, $manager] = $this->hostManager();
        $parent               = new HostParent();
        $parent->addChild(new HostChild());
        $parent->addChild(new HostChild());
        $manager->persist($parent);
        $manager->flush();

        $parent->children->clear();
        self::assertNotSame([], $this->orphans($manager));

        $this->assertRefused($registry, $manager);
        self::assertSame(2, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_child'), 'no child deleted');
        self::assertNotSame([], $this->orphans($manager), 'read-only: the host\'s orphan removals are left as they were');
    }

    /** Audit XD-5: an inverse one-to-one set to null with orphan removal is refused too. */
    public function testAnUnsetOrphanRemovalOneToOneRefusesTheUnit(): void
    {
        [$registry, $manager] = $this->hostManager();
        $parent               = new HostParent();
        $parent->setProfile(new HostProfile());
        $manager->persist($parent);
        $manager->flush();

        $parent->profile = null;

        $this->assertRefused($registry, $manager);
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_profile'), 'the profile is not deleted');

        // The host flushes its own orphan removal; ORM then keeps re-scheduling the deleted (unmanaged) profile, which
        // no flush deletes again: not pending work, so the next unit runs.
        $manager->flush();
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_profile'));
        (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('ga4')));
        self::assertSame(['ga4'], $manager->getConnection()->fetchFirstColumn('SELECT code FROM marketing_tool'));
    }

    public function testADomainFailureOnTheHostManagerForgetsOnlyTheToolRows(): void
    {
        [$registry, $manager] = $this->hostManager();
        $note                 = new HostNote();
        $manager->persist($note);
        $manager->flush();

        $failure = new RuntimeException('refused');
        $caught  = $this->failureOf(static fn (): mixed => (new MarketingToolWriteUnit($manager, $registry))->run(static function (EntityManagerInterface $em) use ($failure): never {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->flush();

            throw $failure;
        }));

        self::assertSame($failure, $caught);
        self::assertTrue($manager->isOpen(), 'an open host manager is never closed');
        self::assertTrue($manager->contains($note), 'nor cleared');
        self::assertSame([], $manager->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? [], 'the rolled-back tool is forgotten');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    public function testAStorageFailureThatClosedTheHostManagerReplacesIt(): void
    {
        [$registry, $manager] = $this->hostManager();
        $manager->persist((new MarketingTool())->setCode('gtm'));
        $manager->flush();
        $manager->clear();

        $caught = $this->failureOf(static fn () => (new MarketingToolWriteUnit($manager, $registry))->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
        }));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught, 'a unique violation is not a conflict: rethrown unchanged');
        self::assertFalse($manager->isOpen());
        $replacement = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $replacement);
        self::assertNotSame($manager, $replacement, 'the closed manager was replaced');
        self::assertTrue($replacement->isOpen());
    }

    public function testAClosedHostManagerWithoutARegistryIsLeftToTheHost(): void
    {
        [, $manager] = $this->hostManager();
        $manager->persist((new MarketingTool())->setCode('gtm'));
        $manager->flush();
        $manager->clear();

        $caught = $this->failureOf(static fn () => (new MarketingToolWriteUnit($manager))->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
        }));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught);
        self::assertFalse($manager->isOpen());
    }

    public function testALockConflictLeavesAsATransientStorageFailureWithoutHint(): void
    {
        [$registry, $manager] = $this->hostManager();
        $deadlock             = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);

        $caught = $this->failureOf(static fn (): mixed => (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): never => throw $deadlock));

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame($deadlock, $caught->getPrevious());
        self::assertNull($caught->retryAfterSeconds(), 'the configured conflict_response decides');
    }

    public function testTheBundlesOwnManagerIsResetAfterAStorageFailure(): void
    {
        $registry = new SqliteManagerRegistry(['default' => ['Nowo\\MarketingKitBundle\\Tests\\Support\\Entity' => dirname(__DIR__, 2) . '/Support/Entity'], 'mk' => self::BUNDLE]);
        $manager  = $registry->getManager('mk');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $unit = new MarketingToolWriteUnit($manager, $registry, 'mk', true);

        $unit->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));

        $caught = $this->failureOf(static fn () => $unit->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm'))));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught);
        self::assertNotSame($manager, $registry->getManager('mk'), 'the bundle\'s own manager was reset');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    /**
     * 2.0: the 1.14 host-flush probe is gone. A unit built by hand on a caller's manager (`ownManager: false`, never
     * wired by the container) still flushes what the operation persisted there (the caller chose that manager), and the
     * deprecated `$hostFlushReporter` argument is accepted and ignored: no notice is triggered.
     */
    public function testTheDeprecatedReporterArgumentIsIgnoredAndNothingIsReported(): void
    {
        [$registry, $manager] = $this->hostManager();
        $unit                 = new MarketingToolWriteUnit($manager, $registry, hostFlushReporter: new stdClass());

        $messages = $this->deprecations(static fn () => $unit->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->persist(new HostNote());
        }));

        self::assertSame([], $messages);
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    /**
     * 2.0 (wave-1 lesson W1-2): the private manager only ever writes marketing tool rows. An application entity persisted
     * through it (for example by a prePersist listener of the application that uses the event's manager) fails the unit
     * before anything is written; the application's manager is untouched and keeps its own pending work.
     */
    public function testThePrivateManagerRefusesAnApplicationEntityBeforeWriting(): void
    {
        [$registry, $host, $private, $privateRegistry] = $this->privateManager();
        $pending                                       = new HostNote();
        $host->persist($pending);
        $unit = new MarketingToolWriteUnit($private, $privateRegistry, 'default', true);

        $failure = $this->failureOf(static fn () => $unit->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->persist(new HostNote());
        }));

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame(sprintf(MarketingToolWriteUnit::FOREIGN_ENTITY_MESSAGE, HostNote::class, HostNote::class), $failure->getMessage());
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'), 'nothing written');
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
        self::assertSame($host, $registry->getManager(), 'the application\'s manager was not reset');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'its pending work is intact');

        // An application entity merely loaded through the private manager is refused too.
        $host->flush();
        $unit->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('ga4')));
        $loaded = $this->failureOf(static fn () => $unit->run(static function (EntityManagerInterface $em) use ($pending): void {
            $em->find(HostNote::class, $pending->id);
            $em->persist((new MarketingTool())->setCode('meta'));
        }));
        self::assertInstanceOf(LogicException::class, $loaded);
        self::assertSame(['ga4'], $host->getConnection()->fetchFirstColumn('SELECT code FROM marketing_tool'));
    }

    /**
     * The guard inspects only the private manager: a dedicated `doctrine.entity_manager` (the DDD option) is the
     * application's own choice.
     */
    public function testADedicatedManagerIsNotInspected(): void
    {
        [$registry, $manager] = $this->hostManager();
        $unit                 = new MarketingToolWriteUnit($manager, $registry, 'default', true);

        $unit->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->persist(new HostNote());
        });

        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    /**
     * @return array{0: SqliteManagerRegistry, 1: EntityManagerInterface, 2: PrivateEntityManager, 3: PrivateManagerRegistry}
     */
    private function privateManager(): array
    {
        [$registry, $host] = $this->hostManager();
        $private           = new PrivateEntityManager($registry, 'default', MarketingTool::class, NowoMarketingKitExtension::BUNDLE_NAME);

        return [$registry, $host, $private, new PrivateManagerRegistry($registry, $private, NowoMarketingKitExtension::OWN_NAMESPACES, 'default')];
    }

    /**
     * @return array{0: SqliteManagerRegistry, 1: EntityManagerInterface}
     */
    private function hostManager(): array
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE + ['Nowo\\MarketingKitBundle\\Tests\\Support\\Entity' => dirname(__DIR__, 2) . '/Support/Entity']]);
        $manager  = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

        return [$registry, $manager];
    }

    private function assertRefused(SqliteManagerRegistry $registry, EntityManagerInterface $manager): void
    {
        try {
            (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
            self::fail('The host\'s orphan removals must not be carried.');
        } catch (LogicException $refused) {
            self::assertSame(MarketingToolWriteUnit::HOST_PENDING_MESSAGE, $refused->getMessage());
        }

        self::assertTrue($manager->isOpen());
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    /**
     * @return array<object>
     */
    private function orphans(EntityManagerInterface $manager): array
    {
        /** @var array<object> $orphans */
        $orphans = Closure::bind(static fn (UnitOfWork $unitOfWork): array => $unitOfWork->orphanRemovals, null, UnitOfWork::class)($manager->getUnitOfWork());

        return $orphans;
    }

    /**
     * @param callable(): mixed $write
     */
    private function failureOf(callable $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The unit must fail.');
    }
}
