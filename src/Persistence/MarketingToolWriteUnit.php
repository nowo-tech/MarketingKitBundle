<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Persistence;

use Closure;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

/**
 * One write unit of the marketing tool rows (1.13, REQ-RUNTIME-003 rule 1): every admin create, edit, toggle, delete,
 * catalog seed and YAML import runs its writes here, in ONE transaction on the manager of {@see MarketingTool}, flushed
 * before the commit.
 *
 * - The bundle's own manager — 2.0: always, as the container wires it. Either the bundle's private entity manager
 *   (transaction-doctrine's `PrivateEntityManager`, same connection and table as the application's default manager;
 *   REQ-ARCH-001 rule 6) or a dedicated `doctrine.entity_manager` (the DDD option): transaction-doctrine's
 *   {@see DoctrineTransactionRunner::forEntityManager()}; after a failure that manager is closed and dropped / reset
 *   (it maps the marketing tool rows only), never an application manager. Before the flush of the private manager,
 *   an entity it holds that is not a marketing tool (an application entity loaded or persisted through it, for example
 *   by a Doctrine listener of the application) fails the unit with a LogicException before anything is written: the
 *   private manager only ever writes the bundle's rows, and the application flushes its entities through its own
 *   manager. A dedicated manager is the application's choice and is not inspected.
 * - A manager handed in by code that builds the unit itself with `ownManager: false` (the constructor default; the
 *   container never does since 2.0): an open manager is never cleared, closed or reset (rule 2). Pending changes on any
 *   entity that is not a marketing tool belong to the caller: the unit refuses to start with a LogicException (the
 *   rule of {@see DoctrineTransactionRunner::forHostEntityManager()}). The unit runs on transaction-doctrine's
 *   connection runner (levels rolled back to the entry level, nested units on savepoints, a lost savepoint never taken
 *   as success); after a failure a closed manager is replaced through the registry, and in an open one only the
 *   marketing tool rows are detached, so nothing the rollback undid can be flushed later.
 *
 * Deadlocks, lock wait timeouts and serialization failures leave as {@see TransientStorageFailure} (the storage error as
 * previous, no Retry-After hint: the configured conflict_response decides); anything else (a unique violation, an
 * optimistic lock failure, a lost connection) is rethrown unchanged. No retry here: the answer is the 409 of
 * {@see MarketingToolConflictListener}.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): DoctrineBundle resets a manager service in place, so the
 * injected manager stays the current one; the private manager is a stable decorator dropped at every boundary.
 */
final readonly class MarketingToolWriteUnit
{
    /** 2.0: an entity that is not a marketing tool is held by the private manager (format: the entity class, twice). */
    public const FOREIGN_ENTITY_MESSAGE = 'A %s entity is held by NowoMarketingKitBundle\'s private entity manager: the bundle writes only its own marketing tool rows there, never your application\'s. Load, persist and flush %s through your own entity manager (see UPGRADING.md "To 2.0.0", "Private manager only").';

    public const HOST_PENDING_MESSAGE = 'The entity manager of the marketing tools has pending changes on other entities (the host\'s): flush or clear them before a marketing tool write; the marketing tool transaction would otherwise carry them into a transaction that may roll back.';

    /**
     * @param EntityManagerInterface $entityManager the manager of {@see MarketingTool}
     * @param ManagerRegistry|null $registry the Doctrine registry (reset of a closed manager)
     * @param string|null $managerName `doctrine.entity_manager` (null: the default manager)
     * @param bool $ownManager whether that manager is the bundle's own (2.0: the container always passes true)
     * @param LoggerInterface|null $logger structured failure / repair notices of transaction-doctrine (no SQL)
     * @param object|null $hostFlushReporter deprecated since 2.0, ignored; removed in 3.0 (1.14: the host-flush probe of
     *                                       the host's-manager path, removed with that mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private bool $ownManager = false,
        private ?LoggerInterface $logger = null,
        ?object $hostFlushReporter = null,
    ) {
        unset($hostFlushReporter); // deprecated since 2.0, ignored (BC: kept until 3.0)
    }

    /**
     * Runs $operation in one transaction and flushes the manager before the commit.
     *
     * @template T
     *
     * @param callable(EntityManagerInterface): T $operation receives the manager of the marketing tool rows
     *
     * @throws TransientStorageFailure on a deadlock, lock wait timeout or serialization failure (nothing committed)
     * @throws LogicException when the host's manager has pending changes on entities that are not marketing tool rows
     * @throws DBALException any other storage error, unchanged (a unique violation, a lost connection, ...)
     *
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $manager = $this->entityManager;
        if ($this->ownManager) {
            /** @var T $result */
            $result = DoctrineTransactionRunner::forEntityManager($manager, $this->registry, $this->managerName, null, $this->logger)
                ->run(static function () use ($operation, $manager): mixed {
                    $result = $operation($manager);
                    self::assertOnlyBundleEntities($manager);

                    return $result;
                });

            return $result;
        }

        try {
            $this->assertNoHostPendingChanges($manager);
        } catch (LogicException $refused) {
            // Audit US-1: the form already bound its values into a managed tool; forget it, so a later flush of the
            // host's own work never writes the refused (unsaved) values.
            $this->releaseHostManager($manager);

            throw $refused;
        }

        try {
            /** @var T $result */
            $result = DoctrineTransactionRunner::forConnection($manager->getConnection(), null, $this->logger)
                ->run(static function () use ($operation, $manager): mixed {
                    $result = $operation($manager);
                    $manager->flush();

                    return $result;
                });

            return $result;
        } catch (Throwable $failure) {
            $this->releaseHostManager($manager);

            throw $failure;
        }
    }

    /**
     * 2.0 (REQ-ARCH-001 rule 6, wave-1 lesson W1-2): fails when the private manager is about to write an entity that is
     * not a marketing tool. Read-only (identity map and scheduled inserts / deletes; nothing is computed, flushed,
     * cleared or detached). A dedicated `doctrine.entity_manager` is the application's own choice and is not inspected.
     *
     * @throws LogicException naming the first foreign class; nothing has been written
     */
    private static function assertOnlyBundleEntities(EntityManagerInterface $manager): void
    {
        if (!$manager instanceof PrivateEntityManager) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $classes    = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if ($entities !== []) {
                $classes[] = $class;
            }
        }
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            $classes[] = $manager->getClassMetadata($entity::class)->getName();
        }

        foreach ($classes as $class) {
            if ($class !== MarketingTool::class) {
                throw new LogicException(sprintf(self::FOREIGN_ENTITY_MESSAGE, $class, $class));
            }
        }
    }

    /**
     * Pending changes on entities that are not marketing tool rows belong to the caller: refuse to carry them. Read-only:
     * change sets are computed (as the flush would), nothing is flushed, cleared, detached or reset here.
     */
    private function assertNoHostPendingChanges(EntityManagerInterface $manager): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $pending = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];
        foreach ($pending as $entity) {
            if (!$entity instanceof MarketingTool) {
                throw new LogicException(self::HOST_PENDING_MESSAGE);
            }
        }

        // A marketing tool row has no collection: any pending collection change is the host's.
        if ($unitOfWork->getScheduledCollectionUpdates() !== [] || $unitOfWork->getScheduledCollectionDeletions() !== []) {
            throw new LogicException(self::HOST_PENDING_MESSAGE);
        }

        // Audit XD-5 (1.14): orphan removals are deletions the flush performs too (an inverse collection cleared, an
        // inverse one-to-one set to null), held apart from the scheduled deletions until the flush. A marketing tool
        // row has no association, so every orphan is the host's. Only a managed orphan is deleted by the flush: ORM
        // keeps the loaded value of an inverse one-to-one after its orphan was deleted, so every later change-set
        // computation schedules that (no longer managed) entity again, and the flush skips it.
        foreach ($this->orphanRemovals($unitOfWork) as $orphan) {
            if ($manager->contains($orphan)) {
                throw new LogicException(self::HOST_PENDING_MESSAGE);
            }
        }
    }

    /**
     * The entities orphan removal deletes at the next flush. UnitOfWork keeps them private: read without changing them
     * (as transaction-doctrine's HostFlushProbe does).
     *
     * @return array<object>
     */
    private function orphanRemovals(UnitOfWork $unitOfWork): array
    {
        /** @var array<object> $orphans */
        $orphans = Closure::bind(static fn (UnitOfWork $unitOfWork): array => $unitOfWork->orphanRemovals, null, UnitOfWork::class)($unitOfWork);

        return $orphans;
    }

    /**
     * After a failed unit on the host's manager: a closed manager is replaced (rule 2 allows only that), an open one
     * forgets the marketing tool rows only (managed or scheduled for insertion), never the host's entities.
     */
    private function releaseHostManager(EntityManagerInterface $manager): void
    {
        if (!$manager->isOpen()) {
            $this->registry?->resetManager($this->managerName);

            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $entities   = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...($unitOfWork->getIdentityMap()[$manager->getClassMetadata(MarketingTool::class)->rootEntityName] ?? []),
        ];
        foreach ($entities as $entity) {
            if ($entity instanceof MarketingTool && $manager->contains($entity)) {
                $manager->detach($entity);
            }
        }
    }
}
