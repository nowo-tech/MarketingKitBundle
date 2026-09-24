<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ClosedEntityManagerSubscriberTest extends TestCase
{
    public function testSubscribesAfterRouter(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 31]],
            ClosedEntityManagerSubscriber::getSubscribedEvents(),
        );
    }

    public function testClosedManagerFromPreviousRequestIsResetOnNextBundleRequest(): void
    {
        $open = true;
        $uow  = $this->createMock(UnitOfWork::class);
        $uow->method('getIdentityMap')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $em->method('getUnitOfWork')->willReturn($uow);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em, 'other' => $this->createMock(ObjectManager::class)]);
        $registry->expects(self::once())->method('resetManager')->with('default')
            ->willReturnCallback(static function () use (&$open, $em): EntityManagerInterface {
                $open = true;

                return $em;
            });

        $subscriber = new ClosedEntityManagerSubscriber($registry);

        // Request 1: manager open, nothing to do; a failed flush then closes it.
        $subscriber->onKernelRequest($this->event('nowo_marketing_kit_admin_index'));
        $open = false;

        // Request 2 on the same worker, no kernel reset in between.
        $subscriber->onKernelRequest($this->event('nowo_marketing_kit_admin_edit'));
        self::assertTrue($em->isOpen());

        // Request 3: already recovered.
        $subscriber->onKernelRequest($this->event('nowo_marketing_kit_admin_new'));
    }

    public function testDetachesStaleMarketingToolsFromOpenManager(): void
    {
        $tool = new MarketingTool();
        $uow  = $this->createMock(UnitOfWork::class);
        $uow->method('getIdentityMap')->willReturn([MarketingTool::class => ['1' => $tool]]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects(self::once())->method('detach')->with($tool);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $em]);
        $registry->expects(self::never())->method('resetManager');

        $subscriber = new ClosedEntityManagerSubscriber($registry);
        $subscriber->onKernelRequest($this->event('nowo_marketing_kit_admin_index'));
    }

    public function testIgnoresSubRequestsAndForeignRoutes(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagers');

        $subscriber = new ClosedEntityManagerSubscriber($registry);
        $subscriber->onKernelRequest($this->event('nowo_marketing_kit_admin_index', HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelRequest($this->event('app_home'));
        $subscriber->onKernelRequest($this->event(null));
    }

    private function event(?string $route, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    }
}
