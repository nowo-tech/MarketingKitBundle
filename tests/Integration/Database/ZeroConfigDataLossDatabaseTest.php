<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostTag;
use Nowo\MarketingKitBundle\Tests\Support\LinkedEntity\HostCampaign;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function count;
use function getenv;
use function is_file;
use function is_string;
use function sprintf;
use function unlink;

/**
 * THE data-loss test of the 2.0 rule-6 flip (REQ-ARCH-001 rule 6), on SQLite and on real MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16 servers, through a booted Symfony kernel with ZERO MarketingKit Doctrine configuration
 * ({@see ZeroConfigDatabaseKernel}: DoctrineBundle's real registry, the bundle's real wiring, no mock).
 *
 * In 1.x that configuration made the bundle write through — and flush — the application's default manager. 2.0 writes
 * through the bundle's private manager on the same connection. What the application left pending in its own manager
 * must survive a bundle write exactly as it was: not written, not cleared, not detached, not reverted, and then written
 * by the application's own flush. Every row check reads through a SECOND, independent connection, so it sees committed
 * data only.
 *
 * Set MK_MYSQL_URL, MK_MARIADB_URL and MK_POSTGRES_URL; CI runs the `database` group with `--fail-on-skipped`.
 */
#[Group('database')]
final class ZeroConfigDataLossDatabaseTest extends TestCase
{
    use CollectsDeprecations;

    private ?ZeroConfigDatabaseKernel $kernel = null;

    private ?Connection $observer = null;

    private ?string $sqliteFile = null;

    protected function tearDown(): void
    {
        $this->observer?->close();
        if ($this->kernel instanceof ZeroConfigDatabaseKernel) {
            // Leave the shared test database as the other database tests expect it (host_campaign has a foreign key to
            // marketing_tool, which their own schema reset would not drop).
            $doctrine = $this->kernel->getContainer()->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $host = $doctrine->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $host);
            if (!$host->isOpen()) {
                $host = $doctrine->resetManager();
                self::assertInstanceOf(EntityManagerInterface::class, $host);
            }
            (new SchemaTool($host))->dropSchema($host->getMetadataFactory()->getAllMetadata());
        }
        $this->kernel?->shutdown();
        $this->observer = null;
        $this->kernel   = null;
        if ($this->sqliteFile !== null && is_file($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        foreach (['mysql' => 'MK_MYSQL_URL', 'mariadb' => 'MK_MARIADB_URL', 'postgres' => 'MK_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    /**
     * The application holds a pending update, a pending insert and a pending delete in its manager while the bundle
     * seeds its catalog and imports its YAML tools (two bundle write units, zero config). In 1.14 the same calls
     * refused to start (the write unit's LogicException on pending application work) or, for work that entered during
     * the unit, flushed it; in 2.0 they run and leave every pending change of the application exactly as it was.
     */
    #[DataProvider('databases')]
    public function testAZeroConfigBundleWriteLeavesTheApplicationsPendingChangesUntouched(?string $url): void
    {
        $host = $this->boot($url);

        $kept        = new HostTag();
        $doomed      = new HostNote();
        $kept->label = 'committed';
        $host->persist($kept);
        $host->persist($doomed);
        $host->flush();
        $doomedId = $doomed->id;

        // The application's pending work: an update, an insert and a delete it has not flushed yet.
        $kept->label = 'pending edit';
        $fresh       = new HostNote();
        $host->persist($fresh);
        $host->remove($doomed);

        $admin    = $this->admin();
        $messages = $this->deprecations(static function () use ($admin, &$seeded, &$imported): void {
            $seeded   = $admin->seedCatalog('default');
            $imported = $admin->importFromYaml('default');
        });

        self::assertSame([], $messages, 'no notice, no probe: 2.0 has no host mode left to report');
        self::assertGreaterThan(0, $seeded);
        self::assertSame(1, $imported);
        // Committed (seen by another connection): the bundle's rows, and nothing of the application's pending work.
        self::assertSame($seeded, $this->rows('marketing_tool'), 'the catalog row of gtm was updated by the import');
        self::assertSame(['committed'], $this->column('SELECT label FROM host_tag'), 'the pending update was not flushed');
        self::assertSame([$doomedId], $this->ids('host_note'), 'neither the pending insert nor the pending delete was flushed');

        // The application's unit of work is exactly as the application left it.
        self::assertTrue($host->isOpen());
        $unitOfWork = $host->getUnitOfWork();
        self::assertTrue($host->contains($kept), 'not cleared or detached');
        self::assertSame('pending edit', $kept->label, 'not reverted');
        $unitOfWork->computeChangeSets();
        self::assertSame(['label' => ['committed', 'pending edit']], $unitOfWork->getEntityChangeSet($kept));
        self::assertTrue($unitOfWork->isScheduledForInsert($fresh));
        self::assertTrue($unitOfWork->isScheduledForDelete($doomed));
        self::assertSame([], $unitOfWork->getIdentityMap()[MarketingTool::class] ?? [], 'the tools live on the private manager only');

        $private = $this->service(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertInstanceOf(PrivateEntityManager::class, $private);
        self::assertTrue($private->isCreated(), 'the bundle wrote through its private manager');

        // The application flushes its own work when it decides to: all of it is written.
        $host->flush();
        self::assertSame(['pending edit'], $this->column('SELECT label FROM host_tag'));
        self::assertSame([(int) $fresh->id], $this->ids('host_note'));
        self::assertSame($seeded, $this->rows('marketing_tool'));
    }

    /**
     * The 1.14 probe's case: an application listener puts its own row in ITS manager during a bundle write and relied on
     * the bundle's flush to store it. 2.0 no longer flushes it — the documented break — but nothing is lost while the
     * application holds its manager: the rows are pending there, and the application's flush stores them.
     */
    #[DataProvider('databases')]
    public function testAListenerChangeInTheApplicationsManagerIsNoLongerFlushedByTheBundleButStaysPending(?string $url): void
    {
        $host     = $this->boot($url);
        $listener = $this->service(HostApplicationListener::class);
        self::assertInstanceOf(HostApplicationListener::class, $listener);
        $listener->mode = 'own-manager';

        $seeded = $this->admin()->seedCatalog('default');

        self::assertSame($seeded, $listener->notes);
        self::assertSame($seeded, $this->rows('marketing_tool'));
        self::assertSame(0, $this->rows('host_note'), '2.0: the bundle does not flush the application\'s manager');
        self::assertCount($seeded, $host->getUnitOfWork()->getScheduledEntityInsertions(), 'pending, not lost');

        $host->flush();
        self::assertSame($seeded, $this->rows('host_note'));
    }

    /**
     * An application listener that writes through the manager of the event — the bundle's private manager since 2.0 —
     * fails the bundle write before anything is written (wave-1 lesson W1-2): the private manager writes only marketing
     * tool rows. The application's own pending work is untouched.
     */
    #[DataProvider('databases')]
    public function testAnApplicationEntityInThePrivateManagerFailsTheWriteBeforeAnythingIsWritten(?string $url): void
    {
        $host    = $this->boot($url);
        $pending = new HostTag();
        $host->persist($pending);
        $listener = $this->service(HostApplicationListener::class);
        self::assertInstanceOf(HostApplicationListener::class, $listener);
        $listener->mode = 'event-manager';

        $failure = $this->failureOf(fn (): int => $this->admin()->seedCatalog('default'));

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame(sprintf(MarketingToolWriteUnit::FOREIGN_ENTITY_MESSAGE, HostNote::class, HostNote::class), $failure->getMessage());
        self::assertSame(0, $this->rows('marketing_tool'), 'rolled back: nothing of the write was committed');
        self::assertSame(0, $this->rows('host_note'));
        self::assertTrue($host->isOpen());
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'the application\'s pending work is intact');

        $listener->mode = null;
        self::assertGreaterThan(0, $this->admin()->seedCatalog('default'), 'the next write works');
    }

    /**
     * An application entity that points at a marketing tool gets it with `getReference()` on its own manager (UPGRADING
     * "Private manager only"): the foreign key is written. A tool taken from the bundle's repository (private manager) is
     * unknown to the application's manager, so its flush refuses it instead of writing a duplicate tool.
     */
    #[DataProvider('databases')]
    public function testAnApplicationLinkToAToolUsesGetReference(?string $url): void
    {
        $host = $this->boot($url);
        $this->admin()->seedCatalog('default');
        $repository = $this->service(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);
        $tool = $repository->findByProfileOrdered('default')[0];
        $id   = (int) $tool->getId();

        $campaign       = new HostCampaign();
        $campaign->tool = $host->getReference(MarketingTool::class, $id);
        $host->persist($campaign);
        $host->flush();
        self::assertSame([$id], $this->ids('host_campaign', 'tool_id'));

        $wrong       = new HostCampaign();
        $wrong->tool = $tool;
        $host->persist($wrong);
        $failure = $this->failureOf(static fn () => $host->flush());
        self::assertInstanceOf(ORMInvalidArgumentException::class, $failure);
        self::assertSame(1, $this->rows('host_campaign'));
        self::assertSame(count($repository->findByProfileOrdered('default')), $this->rows('marketing_tool'), 'no duplicate tool');
    }

    /**
     * The reverse of the probe's case, which no probe could see: a tool loaded through the bundle's repository belongs to
     * the private manager, so changing it and flushing the application's manager stores nothing (UPGRADING "Private
     * manager only": change tools through the bundle's services).
     */
    #[DataProvider('databases')]
    public function testAToolChangedOutsideTheBundleIsNotStoredByTheApplicationsFlush(?string $url): void
    {
        $host = $this->boot($url);
        $this->admin()->seedCatalog('default');
        $repository = $this->service(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);
        $tool = $repository->findByProfileOrdered('default')[0];
        $id   = (int) $tool->getId();

        $tool->setSortOrder(4242);
        $host->flush();

        self::assertFalse($host->contains($tool));
        self::assertNotSame(4242, (int) $this->observer()->fetchOne('SELECT sortOrder FROM marketing_tool WHERE id = ?', [$id]));
    }

    private function boot(?string $url): EntityManagerInterface
    {
        if ($url === null) {
            self::markTestSkipped('Set MK_MYSQL_URL, MK_MARIADB_URL and MK_POSTGRES_URL to run the real-database tests.');
        }
        if ($url === 'sqlite') {
            $this->sqliteFile = __DIR__ . '/../../../var/zero-config-data-loss.sqlite';
            if (is_file($this->sqliteFile)) {
                unlink($this->sqliteFile);
            }
            $url = 'sqlite:///%kernel.project_dir%/var/zero-config-data-loss.sqlite';
        }

        $this->kernel = new ZeroConfigDatabaseKernel($url);
        $this->kernel->boot();
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertNotInstanceOf(PrivateEntityManager::class, $host);
        $schemaTool = new SchemaTool($host);
        $metadata   = $host->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $host;
    }

    private function service(string $id): object
    {
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container->get($id);
    }

    private function admin(): MarketingToolAdminService
    {
        $admin = $this->service(MarketingToolAdminService::class);
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);

        return $admin;
    }

    /** A second connection to the same database: it sees committed rows only. */
    private function observer(): Connection
    {
        if (!$this->observer instanceof Connection) {
            $doctrine = $this->service('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $connection = $doctrine->getConnection();
            self::assertInstanceOf(Connection::class, $connection);
            $this->observer = DriverManager::getConnection($connection->getParams());
        }

        return $this->observer;
    }

    private function rows(string $table): int
    {
        return (int) $this->observer()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        /** @var list<string> $values */
        $values = $this->observer()->fetchFirstColumn($sql);

        return $values;
    }

    /**
     * @return list<int>
     */
    private function ids(string $table, string $column = 'id'): array
    {
        $ids = [];
        foreach ($this->observer()->fetchFirstColumn(sprintf('SELECT %s FROM %s ORDER BY %1$s', $column, $table)) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param callable(): mixed $write
     */
    private function failureOf(callable $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The call must fail.');
    }
}
