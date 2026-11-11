<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\EventSubscriber\ClosedEntityManagerSubscriber;
use Nowo\MarketingKitBundle\Tests\Support\SqliteManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;

/**
 * Rule 6 (W0): the subscriber resets only the manager of MarketingTool. A closed host manager is never reset by the
 * bundle. Real ORM: in-memory SQLite entity managers behind a real ManagerRegistry.
 */
final class ClosedEntityManagerSubscriberHostManagerTest extends TestCase
{
    private const BUNDLE = ['Nowo\\MarketingKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    public function testClosedHostManagerIsNotResetWhenTheBundleManagerIs(): void
    {
        $registry = new SqliteManagerRegistry(['default' => ['App\\Entity' => dirname(__DIR__, 2)], 'marketing' => self::BUNDLE]);
        $host     = $this->closed($registry, 'default');
        $bundle   = $this->closed($registry, 'marketing');

        (new ClosedEntityManagerSubscriber($registry))->onKernelRequest($this->event());

        self::assertSame($host, $registry->getManager('default'), 'The bundle must never reset a host manager.');
        self::assertFalse($host->isOpen());
        self::assertNotSame($bundle, $registry->getManager('marketing'));
        self::assertTrue($this->manager($registry, 'marketing')->isOpen());
    }

    public function testConfiguredEntityManagerIsTheOnlyOneReset(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE, 'marketing' => self::BUNDLE]);
        $host     = $this->closed($registry, 'default');
        $this->closed($registry, 'marketing');

        (new ClosedEntityManagerSubscriber($registry, 'marketing'))->onKernelRequest($this->event());

        self::assertSame($host, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'marketing')->isOpen());
    }

    public function testDefaultOnlyHostKeepsTheRecovery(): void
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE]);
        $closed   = $this->closed($registry, 'default');

        (new ClosedEntityManagerSubscriber($registry))->onKernelRequest($this->event());

        self::assertNotSame($closed, $registry->getManager('default'));
        self::assertTrue($this->manager($registry, 'default')->isOpen());
    }

    private function closed(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $this->manager($registry, $name);
        $manager->close();

        return $manager;
    }

    private function manager(SqliteManagerRegistry $registry, string $name): EntityManagerInterface
    {
        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function event(): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', 'nowo_marketing_kit_admin_index');

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
