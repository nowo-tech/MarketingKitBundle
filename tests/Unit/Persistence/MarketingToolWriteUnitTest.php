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
use Nowo\MarketingKitBundle\Doctrine\MarketingKitHostFlushReporter;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostChild;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostParent;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostProfile;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostTag;
use Nowo\MarketingKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function dirname;

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
     * 1.14 (REQ-ARCH-001 rule 6): host work that enters the host's manager during the unit (an operation or a listener of
     * the host) is flushed by the unit; the probe reports each foreign class once, right before that flush.
     */
    public function testTheHostFlushProbeReportsEachForeignClassFlushedByTheUnit(): void
    {
        [$registry, $manager] = $this->hostManager();
        $unit                 = new MarketingToolWriteUnit($manager, $registry, hostFlushReporter: new MarketingKitHostFlushReporter(true));

        $messages = $this->deprecations(static fn () => $unit->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->persist(new HostNote());
            $em->persist(new HostNote());
            $tag        = new HostTag();
            $tag->label = 'x';
            $em->persist($tag);
        }));

        self::assertSame([
            'Your pending changes to ' . HostNote::class . ' were flushed by NowoMarketingKitBundle; 2.0 no longer does.',
            'Your pending changes to ' . HostTag::class . ' were flushed by NowoMarketingKitBundle; 2.0 no longer does.',
        ], $messages);
        self::assertSame(2, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the 1.x behaviour is unchanged');
    }

    public function testTheProbeIsSilentWithoutForeignWorkAndOnTheBundlesOwnManager(): void
    {
        [$registry, $manager] = $this->hostManager();
        $reporter             = new MarketingKitHostFlushReporter(true);
        $tool                 = static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('ga4'));

        self::assertSame([], $this->deprecations(static fn () => (new MarketingToolWriteUnit($manager, $registry, hostFlushReporter: $reporter))->run($tool)));

        $own = new MarketingToolWriteUnit($manager, $registry, 'default', true, hostFlushReporter: $reporter);
        self::assertSame([], $this->deprecations(static fn () => $own->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('meta'));
            $em->persist(new HostNote());
        })), 'the bundle\'s own manager (doctrine.entity_manager, private manager) is never probed');
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
