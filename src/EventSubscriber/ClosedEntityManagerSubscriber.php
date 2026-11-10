<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_search;
use function is_string;
use function str_starts_with;

/**
 * Keeps Doctrine usable on long-lived workers without kernel / services reset.
 *
 * - Replaces the bundle's EntityManager (the one that manages {@see MarketingTool}) when a failed flush of a prior
 *   request left it closed, before a bundle admin route runs.
 * - Detaches managed {@see MarketingTool} instances so admin list/edit pages see database state
 *   instead of a stale identity map from an earlier request.
 *
 * Only the bundle's manager is touched (1.10.0): any other manager of the registry, closed or open, belongs to the host.
 */
final readonly class ClosedEntityManagerSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_marketing_kit_';

    /**
     * @param string|null $entityManagerName `nowo_marketing_kit.doctrine.entity_manager` (null: the manager of MarketingTool, the default one)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $entityManagerName = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32) so that `_route` is known.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        $name = $this->bundleManagerName();
        if ($name === null) {
            return;
        }

        $manager = $this->registry->getManager($name);
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }
        if (!$manager->isOpen()) {
            /** @var EntityManagerInterface $manager */
            $manager = $this->registry->resetManager($name);
        }

        $this->detachMarketingTools($manager);
    }

    /**
     * The manager of the MarketingTool entity: `doctrine.entity_manager`, else resolved with getManagerForClass().
     */
    private function bundleManagerName(): ?string
    {
        if ($this->entityManagerName !== null) {
            return $this->entityManagerName;
        }

        $manager = $this->registry->getManagerForClass(MarketingTool::class);
        $name    = $manager instanceof ObjectManager ? array_search($manager, $this->registry->getManagers(), true) : false;

        return is_string($name) ? $name : null;
    }

    private function detachMarketingTools(EntityManagerInterface $em): void
    {
        foreach ($em->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? [] as $entity) {
            $em->detach($entity);
        }
    }
}
