<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Database;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;

/**
 * An application's Doctrine listener that reacts to marketing tool inserts (an audit trail, say) by writing a row of its
 * own — the code a 1.x application could have relied on the bundle's flush for (the 1.14 probe's case).
 *
 * - `own-manager`: it persists its note into the application's own manager (injected) and never flushes it;
 * - `event-manager`: it persists its note into the manager of the event, which since 2.0 is the bundle's private one.
 *
 * Test-only: the mode is set by each test on a freshly booted kernel.
 */
final class HostApplicationListener
{
    /** @var 'event-manager'|'own-manager'|null */
    public ?string $mode = null;

    public int $notes = 0;

    public function __construct(private readonly EntityManagerInterface $applicationManager)
    {
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        if ($this->mode === 'event-manager' && $args->getObject() instanceof MarketingTool) {
            $args->getObjectManager()->persist(new HostNote());
            ++$this->notes;
        }
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        if ($this->mode === 'own-manager' && $args->getObject() instanceof MarketingTool) {
            $this->applicationManager->persist(new HostNote());
            ++$this->notes;
        }
    }
}
