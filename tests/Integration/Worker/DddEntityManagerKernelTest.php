<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use RuntimeException;
use Symfony\Bridge\Doctrine\Messenger\DoctrineClearEntityManagerWorkerSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function gc_collect_cycles;
use function is_array;
use function is_callable;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function sprintf;
use function str_contains;
use function strtoupper;

/**
 * Audit LD-MK-M1, kept-alive kernel (FRANKENPHP_RESET_KERNEL=false, no kernel.reset): with
 * `doctrine.entity_manager: bundle` and `auto_mapping: true` on the host's default manager, MarketingTool is mapped on
 * "bundle" only, the admin writes there, a closed bundle manager is reset before the next admin request, and the host's
 * closed default manager is never touched.
 *
 * REQ-RUNTIME-002 rule 4 (1.10.1): the services resetter is never called (a host that removes or bypasses
 * services_resetter and drives http_kernel directly, `messenger:consume --no-reset`, long-running console commands).
 * Two tenants (profiles "alpha" and "beta", one GA4 tool each) alternate over N = 500 units per path: admin pages
 * through http_kernel, messages through a real Worker without ResetServicesListener and without DoctrineBundle's
 * DoctrineClearEntityManagerWorkerSubscriber (every 7th handler fails), and console Application runs. Before every unit
 * "another worker" rewrites the tenant's tool with plain SQL. After every unit the bundle manager's identity map is
 * empty; the console / Messenger units re-import the YAML profile and the row must hold the YAML values again (with a
 * stale identity map the import computes an empty change set and writes nothing). Memory margin: 2 MiB after a
 * 50-unit warm-up.
 */
final class DddEntityManagerKernelTest extends KernelTestCase
{
    private const ITERATIONS    = 500;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    /** @var list<string> */
    private array $leaks = [];

    private int $memoryAtWarmup = 0;

    private int $memoryAtEnd = 0;

    protected static function getKernelClass(): string
    {
        return DddWorkerTestKernel::class;
    }

    public function testEntityLivesOnTheConfiguredManagerAndRecoversThere(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host   = $this->manager($doctrine, 'default');
        $bundle = $this->manager($doctrine, 'bundle');
        self::assertTrue($host->getMetadataFactory()->isTransient(MarketingTool::class), 'MarketingTool is not mapped on the host manager.');
        self::assertSame($bundle, $doctrine->getManagerForClass(MarketingTool::class));
        (new SchemaTool($bundle))->createSchema([$bundle->getClassMetadata(MarketingTool::class)]);
        $admin = $container->get('test.ddd.admin_service');
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_marketing_kit_admin_index');

        self::assertGreaterThan(0, $admin->seedCatalog('default'));
        self::assertGreaterThan(0, (int) $bundle->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));

        $host->close();
        for ($i = 0; $i < 20; ++$i) {
            $bundle->close();
            $this->serve($http, $path);

            self::assertTrue($bundle->isOpen(), 'The bundle manager is reset before the next admin request.');
            self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');
        }
        self::assertGreaterThanOrEqual(0, $admin->seedCatalog('second'), 'Admin writes keep working on the bundle manager.');
    }

    public function testManyAdminRequestsAlternateTenantsWithAnEmptyBundleManager(): void
    {
        [$host, $bundle] = $this->bootTenants();
        $http            = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $tenant = $this->tenantFor($i);
            $this->tamper($bundle, $tenant, 'G-' . strtoupper($tenant) . $i);
            $this->memoryMark($i);
            $request  = Request::create($router->generate('nowo_marketing_kit_admin_index', ['profile' => $tenant]));
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $content = (string) $response->getContent();
            if (!str_contains($content, 'G-' . strtoupper($tenant) . $i) || str_contains($content, $this->other($tenant) . '_ga4')) {
                $this->leaks[] = sprintf('request %d (tenant %s) did not render its own tool alone', $i, $tenant);
            }
            $http->terminate($request, $response);
            $this->checkEmpty($bundle, $i);
        }

        self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');
        $this->assertNoLeakAndFlatMemory('admin requests');
    }

    public function testManyMessengerMessagesReimportTenantsWithAnEmptyBundleManager(): void
    {
        [$host, $bundle] = $this->bootTenants();
        $admin           = $this->admin();
        $dispatcher      = $this->dispatcher();
        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            self::assertFalse(
                is_array($listener) && $listener[0] instanceof ResetServicesListener,
                'The worker must run without ResetServicesListener (messenger:consume --no-reset).',
            );
        }

        $handled = 0;
        $bus     = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BoundaryUnitMessage::class => [function (BoundaryUnitMessage $message) use ($admin, $bundle, &$handled): void {
                $this->memoryMark($message->index);
                $this->importUnit($admin, $bundle, $message->index);
                ++$handled;
                if ($message->index % 7 === 3) {
                    throw new RuntimeException('Tag manager down for tenant ' . $this->tenantFor($message->index));
                }
            }],
        ]))]);

        $finished  = 0;
        $afterUnit = function () use ($bundle, &$finished): void {
            $this->checkEmpty($bundle, $finished++);
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $afterUnit, -8192);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $afterUnit, -8192);

        try {
            $this->runWorker(new Worker(['async' => $this->receiver(self::ITERATIONS)], $bus, $dispatcher), $dispatcher);
        } finally {
            $dispatcher->removeListener(WorkerMessageHandledEvent::class, $afterUnit);
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $afterUnit);
        }

        self::assertSame(self::ITERATIONS, $handled);
        self::assertSame(self::ITERATIONS, $finished);
        self::assertFalse($host->isOpen());
        $this->assertNoLeakAndFlatMemory('Messenger messages');
    }

    public function testManyConsoleRunsReimportTenantsWithAnEmptyBundleManager(): void
    {
        [$host, $bundle] = $this->bootTenants();
        $admin           = $this->admin();
        $command         = (new Command('app:import-tenant'))
            ->addArgument('index', InputArgument::REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($admin, $bundle): int {
                $this->importUnit($admin, $bundle, (int) $input->getArgument('index'));

                return Command::SUCCESS;
            });
        $application = $this->application();
        $application->setCommandLoader(new FactoryCommandLoader(['app:import-tenant' => static fn (): Command => $command]));

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->memoryMark($i);
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:import-tenant', 'index' => (string) $i]), new NullOutput()));
            $this->checkEmpty($bundle, $i);
        }

        self::assertFalse($host->isOpen());
        $this->assertNoLeakAndFlatMemory('console command runs');
    }

    /**
     * A command run in-process from another command (Application::doRun()) does not clear the outer command's
     * manager; the outermost console.terminate does.
     */
    public function testNestedCommandKeepsTheOuterCommandsTools(): void
    {
        [, $bundle]  = $this->bootTenants();
        $repository  = $this->repository();
        $application = $this->application();
        $kept        = [];
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $outer       = (new Command('app:outer'))->setCode(static function (InputInterface $input, OutputInterface $output) use ($application, $bundle, $repository, &$kept): int {
            $tools = $repository->findByProfileOrdered('alpha');
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
            $kept[] = $tools !== [] && $bundle->contains($tools[0]);

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:outer' => static fn (): Command => $outer,
            'app:inner' => static fn (): Command => $inner,
        ]));

        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:outer']), new NullOutput()));
        self::assertSame([true], $kept, 'A nested command cleared the outer command\'s manager.');
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'The outermost console.terminate clears the manager.');
    }

    /**
     * A command run in-process from a Messenger handler does not clear the handler's manager; the message boundaries
     * still clear it, and a standalone command clears again once the worker stopped.
     */
    public function testCommandRunFromAMessageHandlerKeepsTheHandlersTools(): void
    {
        [, $bundle]  = $this->bootTenants();
        $repository  = $this->repository();
        $dispatcher  = $this->dispatcher();
        $application = $this->application();
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader(['app:inner' => static fn (): Command => $inner]));

        $kept = [];
        $bus  = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            BoundaryUnitMessage::class => [function (BoundaryUnitMessage $message) use ($application, $bundle, $repository, &$kept): void {
                $tools = $repository->findByProfileOrdered($this->tenantFor($message->index));
                $application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
                $kept[] = $tools !== [] && $bundle->contains($tools[0]);
            }],
        ]))]);

        $this->runWorker(new Worker(['async' => $this->receiver(4)], $bus, $dispatcher), $dispatcher);

        self::assertSame([true, true, true, true], $kept, 'A command run from the handler cleared its manager.');
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'The last WorkerMessageHandled clears the manager.');

        $repository->findByProfileOrdered('beta');
        $application->run(new ArrayInput(['command' => 'app:inner']), new NullOutput());
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'A standalone command clears once the worker stopped.');
    }

    /**
     * MT-M-L1: an unflushed change of a finished (or abandoned) unit is discarded at the boundary and never written by
     * a later, unrelated flush; a closed manager is recreated at the boundary.
     */
    public function testUnflushedChangesAreDiscardedAndAClosedManagerIsRecreated(): void
    {
        [$host, $bundle] = $this->bootTenants();
        $admin           = $this->admin();
        $repository      = $this->repository();
        $application     = $this->application();
        $edit            = (new Command('app:abandoned-edit'))->setCode(static function () use ($repository): int {
            $tools = $repository->findByProfileOrdered('alpha');
            self::assertNotSame([], $tools);
            $tools[0]->setSortOrder(42);

            return Command::SUCCESS;
        });
        $import = (new Command('app:import-beta'))->setCode(static function () use ($admin): int {
            $admin->importFromYaml('beta');

            return Command::SUCCESS;
        });
        $noop = (new Command('app:noop'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader([
            'app:abandoned-edit' => static fn (): Command => $edit,
            'app:import-beta'    => static fn (): Command => $import,
            'app:noop'           => static fn (): Command => $noop,
        ]));

        $application->run(new ArrayInput(['command' => 'app:abandoned-edit']), new NullOutput());
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'The boundary discards the unflushed edit.');
        $application->run(new ArrayInput(['command' => 'app:import-beta']), new NullOutput());
        self::assertSame(0, (int) $bundle->getConnection()->fetchOne("SELECT sortOrder FROM marketing_tool WHERE profile = 'alpha'"), 'A later flush must never write the abandoned edit.');

        $bundle->close();
        $application->run(new ArrayInput(['command' => 'app:noop']), new NullOutput());
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        self::assertTrue($this->manager($doctrine, 'bundle')->isOpen(), 'The boundary recreates a closed bundle manager.');
        self::assertFalse($host->isOpen(), 'The host manager is never reset.');
    }

    /**
     * MT-M-L2: a command run in-process from a controller does not clear the request's manager; the request's own
     * terminate does.
     */
    public function testCommandRunFromAControllerKeepsTheRequestsTools(): void
    {
        [, $bundle]  = $this->bootTenants();
        $repository  = $this->repository();
        $application = $this->application();
        $inner       = (new Command('app:inner'))->setCode(static fn (): int => Command::SUCCESS);
        $application->setCommandLoader(new FactoryCommandLoader(['app:inner' => static fn (): Command => $inner]));
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        $kept       = [];
        $controller = static function () use ($application, $bundle, $repository, &$kept): Response {
            $tools = $repository->findByProfileOrdered('alpha');
            $application->doRun(new ArrayInput(['command' => 'app:inner']), new NullOutput());
            $kept[] = $tools !== [] && $bundle->contains($tools[0]);

            return new Response('ok');
        };
        $replace = static function (ControllerEvent $event) use ($controller): void {
            $event->setController($controller);
        };
        $dispatcher = $this->dispatcher();
        $dispatcher->addListener(KernelEvents::CONTROLLER, $replace, -1024);

        try {
            $request  = Request::create($router->generate('nowo_marketing_kit_admin_index', ['profile' => 'alpha']));
            $response = $http->handle($request);
            self::assertSame('ok', $response->getContent());
            self::assertSame([true], $kept, 'A command run from a controller cleared the request\'s manager.');
            $http->terminate($request, $response);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $replace);
        }

        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'kernel.terminate clears the manager.');
    }

    /**
     * MT-T-I1: an in-process HttpKernel::handle() from a command does not clear the command's manager.
     */
    public function testRequestHandledInsideACommandKeepsTheCommandsTools(): void
    {
        [, $bundle] = $this->bootTenants();
        $repository = $this->repository();
        $http       = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $application = $this->application();
        $kept        = [];
        $outer       = (new Command('app:render'))->setCode(static function () use ($http, $bundle, $repository, &$kept): int {
            $tools = $repository->findByProfileOrdered('beta');
            // Not a bundle route: ClosedEntityManagerSubscriber (admin routes only) stays out of the way.
            $request  = Request::create('/not-a-bundle-page');
            $response = $http->handle($request);
            $http->terminate($request, $response);
            $kept[] = [$response->getStatusCode(), $tools !== [] && $bundle->contains($tools[0])];

            return Command::SUCCESS;
        });
        $application->setCommandLoader(new FactoryCommandLoader(['app:render' => static fn (): Command => $outer]));

        self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:render']), new NullOutput()));
        self::assertSame([[404, true]], $kept, 'A request handled inside a command cleared the command\'s manager.');
        self::assertSame([], $bundle->getUnitOfWork()->getIdentityMap(), 'The outermost console.terminate clears the manager.');
    }

    /**
     * @return array{EntityManagerInterface, EntityManagerInterface} the host's (closed) and the bundle's manager
     */
    private function bootTenants(): array
    {
        self::bootKernel(['debug' => false]);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host   = $this->manager($doctrine, 'default');
        $bundle = $this->manager($doctrine, 'bundle');
        (new SchemaTool($bundle))->createSchema([$bundle->getClassMetadata(MarketingTool::class)]);
        $admin = $this->admin();
        self::assertSame(1, $admin->importFromYaml('alpha'));
        self::assertSame(1, $admin->importFromYaml('beta'));
        $bundle->clear();

        // DoctrineBundle clears every manager after each message (DoctrineClearEntityManagerWorkerSubscriber); a host
        // without it relies on the bundle alone.
        $dispatcher = $this->dispatcher();
        foreach ([WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            foreach ($dispatcher->getListeners($event) as $listener) {
                if (is_array($listener) && $listener[0] instanceof DoctrineClearEntityManagerWorkerSubscriber && is_callable($listener)) {
                    $dispatcher->removeListener($event, $listener);
                }
            }
        }

        // The bundle must never reset or clear the host's manager: it stays closed the whole time.
        $host->close();
        $this->leaks          = [];
        $this->memoryAtWarmup = 0;
        $this->memoryAtEnd    = 0;

        return [$host, $bundle];
    }

    /**
     * Another worker rewrites the tenant's tool (plain SQL, so this worker's identity map is not told).
     */
    private function tamper(EntityManagerInterface $bundle, string $tenant, string $measurementId): void
    {
        $bundle->getConnection()->executeStatement(
            'UPDATE marketing_tool SET enabled = 0, options = ? WHERE profile = ?',
            [json_encode(['measurement_id' => $measurementId]), $tenant],
        );
    }

    /**
     * One console / Messenger unit: another worker tampered with the tenant's tool; the unit re-imports the YAML
     * profile, which must restore the YAML values in the database.
     */
    private function importUnit(MarketingToolAdminService $admin, EntityManagerInterface $bundle, int $index): void
    {
        $tenant = $this->tenantFor($index);
        $this->tamper($bundle, $tenant, 'G-TAMPERED' . $index);
        self::assertSame(1, $admin->importFromYaml($tenant));
        /** @var array{enabled: bool|int|string, options: string}|false $row */
        $row      = $bundle->getConnection()->fetchAssociative('SELECT enabled, options FROM marketing_tool WHERE profile = ?', [$tenant]);
        $expected = ['enabled' => true, 'options' => ['measurement_id' => 'G-' . strtoupper($tenant)]];
        $actual   = $row === false ? null : ['enabled' => (bool) $row['enabled'], 'options' => json_decode($row['options'], true)];
        if ($actual !== $expected) {
            $this->leaks[] = sprintf('unit %d (tenant %s) left %s', $index, $tenant, json_encode($actual));
        }
    }

    private function checkEmpty(EntityManagerInterface $bundle, int $index): void
    {
        if ($bundle->getUnitOfWork()->getIdentityMap() !== []) {
            $this->leaks[] = sprintf('after unit %d the bundle manager still holds tools', $index);
        }
    }

    private function tenantFor(int $index): string
    {
        return $index % 2 === 0 ? 'alpha' : 'beta';
    }

    private function other(string $tenant): string
    {
        return $tenant === 'alpha' ? 'beta' : 'alpha';
    }

    private function memoryMark(int $index): void
    {
        if ($index === self::WARMUP) {
            gc_collect_cycles();
            $this->memoryAtWarmup = memory_get_usage();
        }
        if ($index === self::ITERATIONS - 1) {
            gc_collect_cycles();
            $this->memoryAtEnd = memory_get_usage();
        }
    }

    private function assertNoLeakAndFlatMemory(string $path): void
    {
        self::assertSame([], $this->leaks, sprintf('Stale, foreign or kept tools between %s.', $path));
        self::assertGreaterThan(0, $this->memoryAtWarmup);
        self::assertLessThan(self::MEMORY_MARGIN, $this->memoryAtEnd - $this->memoryAtWarmup, sprintf('Memory grew across %s.', $path));
    }

    private function receiver(int $count): ReceiverInterface
    {
        return new class($count) implements ReceiverInterface {
            private int $next = 0;

            public function __construct(
                private readonly int $count,
            ) {
            }

            public function get(): iterable
            {
                if ($this->next >= $this->count) {
                    return [];
                }

                return [new Envelope(new BoundaryUnitMessage($this->next++))];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };
    }

    private function runWorker(Worker $worker, EventDispatcherInterface $dispatcher): void
    {
        $stopOnIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopOnIdle);

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdle);
        }
    }

    private function application(): Application
    {
        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($this->dispatcher());

        return $application;
    }

    private function admin(): MarketingToolAdminService
    {
        $admin = self::getContainer()->get('test.ddd.admin_service');
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);

        return $admin;
    }

    private function repository(): MarketingToolRepository
    {
        $repository = self::getContainer()->get(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);

        return $repository;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
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
