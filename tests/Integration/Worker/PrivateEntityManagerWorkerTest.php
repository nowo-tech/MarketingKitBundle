<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 2.0 (zero configuration: the private entity manager), FrankenPHP worker mode with the kernel kept alive and never reset
 * (FRANKENPHP_RESET_KERNEL=false, no services resetter): one booted kernel serves every request through the
 * http_kernel service, so kernel.reset never runs. The bundle's private manager is dropped at every request boundary,
 * replaced when closed, and never flushes, clears or resets the application's manager.
 */
final class PrivateEntityManagerWorkerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return PrivateWorkerTestKernel::class;
    }

    public function testAMarketingToolWriteNeverFlushesTheApplicationsPendingChanges(): void
    {
        [$host, $private] = $this->boot();
        $note             = new HostNote();
        $host->persist($note); // the application's own pending work

        $admin = self::getContainer()->get(MarketingToolAdminService::class);
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);
        self::assertGreaterThan(0, $admin->seedCatalog('default'));

        $connection = $host->getConnection();
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM marketing_tool'), 'the tools are committed');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'), 'the application\'s insert was not flushed');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($note), 'the application\'s manager was not cleared');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note));
        self::assertSame([], $host->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? [], 'the tools live on the private manager only');
        self::assertTrue($private->isCreated());

        $host->flush(); // the application flushes its own work when it decides to
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    public function testThePrivateManagerIsDroppedAtEveryRequestAndReplacedWhenClosed(): void
    {
        [$host, $private] = $this->boot();
        $http             = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $repository = self::getContainer()->get(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);
        $admin = self::getContainer()->get(MarketingToolAdminService::class);
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);
        $admin->seedCatalog('default');
        $path = $router->generate('nowo_marketing_kit_admin_index');

        $note = new HostNote();
        $host->persist($note);
        for ($i = 0; $i < 20; ++$i) {
            if ($i % 5 === 0) {
                $private->close(); // a failed bundle write of the previous request left it closed
            }
            $this->serve($http, $path);

            self::assertFalse($private->isCreated(), 'dropped at the end of the request: no tool survives into the next one');
            self::assertTrue($host->isOpen(), 'the application\'s manager is never closed or reset by the bundle');
            self::assertTrue($host->contains($note), 'nor cleared');
        }

        self::assertNotSame([], $repository->findByProfileOrdered('default'), 'the next use creates a fresh private manager');
        self::assertTrue($private->isOpen());
    }

    /**
     * REQ-RUNTIME-002 (kernel reset off): the private manager is also dropped at the end of the outermost console command,
     * of a Messenger message and at `kernel.reset`, so no marketing tool stays managed into the next unit of work.
     */
    public function testThePrivateManagerIsDroppedAtTheCommandMessageAndResetBoundaries(): void
    {
        [$host, $private] = $this->boot();
        $admin            = self::getContainer()->get(MarketingToolAdminService::class);
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);
        $repository = self::getContainer()->get(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $note = new HostNote();
        $host->persist($note);

        // Console: the outermost command's terminate drops it.
        $application = new Application('private-worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $application->setCommandLoader(new FactoryCommandLoader(['app:seed' => static fn (): Command => (new Command('app:seed'))->setCode(static function () use ($admin, $private): int {
            $admin->seedCatalog('default');
            self::assertTrue($private->isCreated());

            return Command::SUCCESS;
        })]));
        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:seed']), new NullOutput()));
        self::assertFalse($private->isCreated(), 'dropped when the command terminated');
        self::assertTrue($host->contains($note), 'the application\'s manager was never cleared by the bundle');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note));

        // Messenger: a handled message drops it (symfony/doctrine-bridge's own worker subscriber then clears the
        // application's managers too; that is the framework's choice, not the bundle's).
        $envelope = new Envelope(new BoundaryUnitMessage(1));
        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        self::assertNotSame([], $repository->findByProfileOrdered('default'), 'tools managed by the private manager');
        self::assertTrue($private->isCreated());
        $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
        self::assertFalse($private->isCreated(), 'dropped when the message was handled');

        // kernel.reset (a worker that does reset services between requests; DoctrineBundle then resets the
        // application's managers itself).
        self::assertNotSame([], $repository->findByProfileOrdered('default'));
        self::assertTrue($private->isCreated());
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        self::assertFalse($private->isCreated(), 'dropped at kernel.reset');
    }

    /**
     * @return array{EntityManagerInterface, PrivateEntityManager}
     */
    private function boot(): array
    {
        self::bootKernel();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host = $doctrine->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        $private = self::getContainer()->get(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertInstanceOf(PrivateEntityManager::class, $private);

        return [$host, $private];
    }

    private function serve(HttpKernelInterface $http, string $path): void
    {
        $request  = Request::create($path);
        $response = $http->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        if ($http instanceof TerminableInterface) {
            $http->terminate($request, $response);
        }
    }
}
