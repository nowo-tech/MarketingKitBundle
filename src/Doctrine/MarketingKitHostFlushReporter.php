<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\EventSubscriber\MarketingKitEntityManagerClearSubscriber;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * 1.14 (REQ-ARCH-001 rule 6 deprecation layer): reports the application's pending changes that a marketing tool write
 * flushes on the application's manager (2.0 always writes through a private entity manager and no longer flushes them).
 *
 * Called right before {@see MarketingToolWriteUnit} flushes the application's manager: without
 * `doctrine.private_entity_manager: true`, when `doctrine.entity_manager` is unset or names the application's default
 * manager. A dedicated `doctrine.entity_manager` is the bundle's own and is never probed. For each entity class outside
 * `Nowo\MarketingKitBundle\Entity` with pending work in that manager, the host-flush probe of
 * nowo-tech/transaction-doctrine ({@see HostFlushProbe}) triggers one `E_USER_DEPRECATED` per class and unit of work:
 * "Your pending changes to App\X were flushed by NowoMarketingKitBundle; 2.0 no longer does." The bundle never changes
 * an application entity itself, so every class reported is the application's own work.
 *
 * Disabled (nothing is inspected) with `private_entity_manager: true`: the bundle writes through the private manager,
 * which holds no application change. A {@see PrivateEntityManager} is never inspected.
 *
 * Guarded: a probe failure is swallowed, so the notice never breaks the flush it observes (audit XD-7).
 *
 * Read-only: it never flushes, clears, closes or resets a manager (REQ-RUNTIME-003). The probe is the shared
 * `nowo_transaction_doctrine.host_flush_probe` when the application registers NowoTransactionDoctrineBundle, else one
 * of its own. Its "already reported" classes are reset at every outermost unit-of-work boundary (request, Messenger
 * message, console command: {@see MarketingKitEntityManagerClearSubscriber}) and at `kernel.reset`, so a FrankenPHP
 * worker with the kernel reset off starts every unit clean.
 *
 * @internal registered by the bundle; not an extension point
 */
final readonly class MarketingKitHostFlushReporter implements ResetInterface
{
    /** The bundle named in the deprecation. */
    public const BUNDLE_NAME = 'NowoMarketingKitBundle';

    /** The bundle's entity namespace: its classes are never reported. */
    public const OWN_NAMESPACES = ['Nowo\\MarketingKitBundle\\Entity'];

    private HostFlushProbe $probe;

    /**
     * @param bool $enabled false with `doctrine.private_entity_manager: true`
     * @param HostFlushProbe|null $probe transaction-doctrine's shared probe (null: one of its own)
     */
    public function __construct(
        private bool $enabled = false,
        ?HostFlushProbe $probe = null,
    ) {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    /**
     * Right before the bundle flushes `$entityManager`: reports the application's pending changes it would write.
     */
    public function beforeFlush(EntityManagerInterface $entityManager): void
    {
        if (!$this->enabled || $entityManager instanceof PrivateEntityManager) {
            return;
        }

        try {
            $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, self::OWN_NAMESPACES);
        } catch (Throwable) {
            // Audit XD-7: a diagnostic never breaks a write; the flush that follows runs as it did before 1.14.
        }
    }

    /** A new unit of work (request, message, command) starts: nothing was reported yet. */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
