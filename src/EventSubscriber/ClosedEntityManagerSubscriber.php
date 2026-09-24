<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Keeps Doctrine usable on long-lived workers without kernel / services reset.
 *
 * - Replaces a closed EntityManager before a bundle admin route runs (failed flush in a prior request).
 * - Detaches managed {@see MarketingTool} instances so admin list/edit pages see database state
 *   instead of a stale identity map from an earlier request.
 *
 * Open managers used by the host application are otherwise left untouched.
 */
final readonly class ClosedEntityManagerSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_marketing_kit_';

    public function __construct(
        private ManagerRegistry $registry,
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

        foreach ($this->registry->getManagers() as $name => $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }

            if (!$manager->isOpen()) {
                /** @var EntityManagerInterface $manager */
                $manager = $this->registry->resetManager($name);
            }

            $this->detachMarketingTools($manager);
        }
    }

    private function detachMarketingTools(EntityManagerInterface $em): void
    {
        foreach ($em->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? [] as $entity) {
            $em->detach($entity);
        }
    }
}
