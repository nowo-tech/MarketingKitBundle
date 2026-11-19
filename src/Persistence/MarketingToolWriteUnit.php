<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Persistence;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
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
 * Stateless (FrankenPHP worker mode, kernel reset off): DoctrineBundle resets a manager service in place, so the
 * injected manager stays the current one.
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
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private bool $ownManager = false,
        private ?LoggerInterface $logger = null,
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

        $this->assertNoHostPendingChanges($manager);

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
     * Pending changes on entities that are not marketing tool rows belong to the host: refuse to carry them.
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
