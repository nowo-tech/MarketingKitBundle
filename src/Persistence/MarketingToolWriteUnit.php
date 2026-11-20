<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Persistence;

use Closure;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MarketingKitBundle\Doctrine\MarketingKitHostFlushReporter;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One write unit of the marketing tool rows (1.13, REQ-RUNTIME-003 rule 1): every admin create, edit, toggle, delete,
 * catalog seed and YAML import runs its writes here, in ONE transaction on the manager of {@see MarketingTool}, flushed
 * before the commit.
 *
 * - The bundle's own manager (`doctrine.entity_manager` set and not the host's default):
 *   transaction-doctrine's {@see DoctrineTransactionRunner::forEntityManager()}; after a failure that manager is closed
 *   and reset (it maps the marketing tool rows only).
 * - The host's manager (the default case, zero config): an open manager is never cleared, closed or reset (rule 2).
 *   The admin form binds into a managed {@see MarketingTool} before the unit starts, so pending changes on that class
 *   are the bundle's own work and are flushed by the unit. Pending changes on any OTHER entity belong to the host: the
 *   unit refuses to start with a LogicException (the rule of {@see DoctrineTransactionRunner::forHostEntityManager()}),
 *   since its flush would carry them into a transaction that may roll back. Entities whose loaded state is rewritten
 *   in place by a listener (for example decrypted after loading) always count as pending; such hosts set a dedicated
 *   `doctrine.entity_manager`. The unit runs on transaction-doctrine's connection runner (levels rolled back to the
 *   entry level, nested units on savepoints, a lost savepoint never taken as success); after a failure a closed
 *   manager is replaced through the registry, and in an open one only the marketing tool rows are detached, so nothing
 *   the rollback undid can be flushed later by the host.
 *
 * Deadlocks, lock wait timeouts and serialization failures leave as {@see TransientStorageFailure} (the storage error as
 * previous, no Retry-After hint: the configured conflict_response decides); anything else (a unique violation, an
 * optimistic lock failure, a lost connection) is rethrown unchanged. No retry here: the answer is the 409 of
 * {@see MarketingToolConflictListener}.
 *
 * 1.14: with `doctrine.private_entity_manager: true` the manager is the bundle's private one (transaction-doctrine's
 * `PrivateEntityManager`, same connection and table) and the unit takes the first path: a failure closes it and the
 * private registry drops it, never an application manager. In the host's-manager path the host-flush probe
 * ({@see MarketingKitHostFlushReporter}) runs right before the flush and reports each application entity class the
 * flush would write (2.0 no longer flushes them).
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): DoctrineBundle resets a manager service in place, so the
 * injected manager stays the current one; the private manager is a stable decorator dropped at every boundary.
 */
final readonly class MarketingToolWriteUnit
{
    public const HOST_PENDING_MESSAGE = 'The entity manager of the marketing tools has pending changes on other entities (the host\'s): flush or clear them before a marketing tool write; the marketing tool transaction would otherwise carry them into a transaction that may roll back.';

    /**
     * @param EntityManagerInterface $entityManager the manager of {@see MarketingTool}
     * @param ManagerRegistry|null $registry the Doctrine registry (reset of a closed manager)
     * @param string|null $managerName `doctrine.entity_manager` (null: the default manager)
     * @param bool $ownManager whether that manager is the bundle's own (configured and not the host's default)
     * @param LoggerInterface|null $logger structured failure / repair notices of transaction-doctrine (no SQL)
     * @param MarketingKitHostFlushReporter|null $hostFlushReporter 1.14: the host-flush probe of the host's-manager path
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private bool $ownManager = false,
        private ?LoggerInterface $logger = null,
        private ?MarketingKitHostFlushReporter $hostFlushReporter = null,
    ) {
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
                ->run(static fn (): mixed => $operation($manager));

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

        $reporter = $this->hostFlushReporter;

        try {
            /** @var T $result */
            $result = DoctrineTransactionRunner::forConnection($manager->getConnection(), null, $this->logger)
                ->run(static function () use ($operation, $manager, $reporter): mixed {
                    $result = $operation($manager);
                    // 1.14 (REQ-ARCH-001 rule 6): report the application's pending work this flush writes (read-only).
                    $reporter?->beforeFlush($manager);
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
     * Pending changes on entities that are not marketing tool rows belong to the host: refuse to carry them. Read-only:
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
