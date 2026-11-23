<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostChild;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostParent;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostProfile;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostTag;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function count;
use function dirname;
use function getenv;
use function is_file;
use function is_string;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_VERSION_ID;

/**
 * REQ-ARCH-001 rule 6 (2.0): the bundle's private entity manager, the only way it writes through the application's
 * default manager's connection, on SQLite and on real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 servers.
 *
 * Each "stack" is one application manager on its own connection, with the bundle's PrivateEntityManager created on it
 * (transaction-doctrine's DBAL middleware wired), as the bundle wires them. Set MK_MYSQL_URL, MK_MARIADB_URL and
 * MK_POSTGRES_URL; CI runs the `database` group with `--fail-on-skipped`. SQLite runs everywhere (a temporary file).
 */
#[Group('database')]
final class PrivateEntityManagerDatabaseTest extends TestCase
{
    use CollectsDeprecations;

    private const ADMIN_CONTROLLER = 'Nowo\\MarketingKitBundle\\Controller\\MarketingToolAdminController::edit';

    /** @var list<PrivateStack> */
    private array $stacks = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $sqliteFile = null;

    protected function tearDown(): void
    {
        foreach ($this->stacks as $stack) {
            $stack->host->getConnection()->close();
        }
        $this->stacks = [];
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
        yield from self::servers();
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function servers(): iterable
    {
        foreach (['mysql' => 'MK_MYSQL_URL', 'mariadb' => 'MK_MARIADB_URL', 'postgres' => 'MK_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    /**
     * The application's pending insert stays pending (never flushed, never cleared); the tool is
     * committed; the application then flushes its own work.
     */
    #[DataProvider('databases')]
    public function testPrivateModeNeverFlushesTheApplicationsPendingChanges(?string $url): void
    {
        $stack = $this->fresh($url);
        $note  = new HostNote();
        $stack->host->persist($note);

        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));

        self::assertSame(['gtm'], $this->codes($stack));
        self::assertSame(0, $this->rowCount($stack, 'host_note'), 'the application\'s insert was not flushed by the bundle');
        self::assertTrue($stack->host->isOpen());
        self::assertTrue($stack->host->getUnitOfWork()->isScheduledForInsert($note), 'nor cleared');
        self::assertSame([], $stack->host->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? []);

        $stack->host->flush();
        self::assertSame(1, $this->rowCount($stack, 'host_note'));
    }

    /**
     * 2.0 (the 1.14 host-flush probe case): work the application puts in ITS manager during a bundle write (an operation
     * or a listener of the application that uses its own manager) is no longer flushed by the bundle. It is not lost
     * either: it stays pending in the application's manager, unchanged, until the application flushes it. Work put in
     * the PRIVATE manager instead fails the write before anything is written (wave-1 lesson W1-2).
     */
    #[DataProvider('databases')]
    public function testTheApplicationsWorkDuringABundleWriteStaysPendingInItsManager(?string $url): void
    {
        $stack = $this->fresh($url);
        $host  = $stack->host;
        $note  = new HostNote();
        $tag   = new HostTag();

        $messages = $this->deprecations(static fn () => $stack->privateUnit()->run(static function (EntityManagerInterface $em) use ($host, $note, $tag): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $host->persist($note);
            $tag->label = 'audit';
            $host->persist($tag);
        }));

        self::assertSame([], $messages, 'no probe since 2.0');
        self::assertSame(['gtm'], $this->codes($stack));
        self::assertSame(0, $this->rowCount($stack, 'host_note'), 'the bundle did not flush the application\'s work');
        self::assertSame(0, $this->rowCount($stack, 'host_tag'));
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'still pending, not lost');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($tag));
        self::assertSame('audit', $tag->label);

        $host->flush();
        self::assertSame(1, $this->rowCount($stack, 'host_note'));
        self::assertSame(1, $this->rowCount($stack, 'host_tag'));

        $refused = $this->failureOf(static fn () => $stack->privateUnit()->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('ga4'));
            $em->persist(new HostNote());
        }));
        self::assertSame(sprintf(MarketingToolWriteUnit::FOREIGN_ENTITY_MESSAGE, HostNote::class, HostNote::class), $refused->getMessage());
        self::assertSame(['gtm'], $this->codes($stack), 'nothing of the refused write was written');
        self::assertSame(1, $this->rowCount($stack, 'host_note'));
    }

    /**
     * Audit XD-5 in 2.0: the application's orphan removals (an inverse collection cleared, an inverse one-to-one set to
     * null) are neither refused nor carried by a bundle write: the rows stay, the removals stay pending in the
     * application's manager, and its own flush deletes them.
     */
    #[DataProvider('databases')]
    public function testTheApplicationsOrphanRemovalsAreNeitherRefusedNorCarried(?string $url): void
    {
        $stack  = $this->fresh($url);
        $parent = new HostParent();
        $parent->addChild(new HostChild());
        $parent->addChild(new HostChild());
        $parent->setProfile(new HostProfile());
        $stack->host->persist($parent);
        $stack->host->flush();

        $parent->children->clear();
        $parent->profile = null;
        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));

        self::assertSame(['gtm'], $this->codes($stack));
        self::assertSame(2, $this->rowCount($stack, 'host_child'), 'the bundle never deletes the application\'s orphans');
        self::assertSame(1, $this->rowCount($stack, 'host_profile'));
        self::assertTrue($stack->host->isOpen());

        $stack->host->flush(); // the application's own flush
        self::assertSame(0, $this->rowCount($stack, 'host_child'));
        self::assertSame(0, $this->rowCount($stack, 'host_profile'));
        self::assertSame(1, $this->rowCount($stack, 'host_parent'));
    }

    /**
     * The application rolls back a transaction it opened around a bundle write: the tool row is gone, and the private
     * manager never serves the rolled-back tool (stale, replaced on the next use).
     */
    #[DataProvider('databases')]
    public function testAnApplicationRollbackAroundAPrivateWriteLeavesNoGhostTool(?string $url): void
    {
        $stack      = $this->fresh($url);
        $connection = $stack->host->getConnection();

        $connection->beginTransaction();
        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
        self::assertSame(['gtm'], $this->codes($stack), 'visible inside the application\'s transaction');
        $connection->rollBack();

        self::assertSame([], $this->codes($stack), 'rolled back with the application\'s transaction');
        self::assertSame([], $stack->repository()->findByProfileOrdered('default'), 'no ghost tool in the private manager');
        self::assertSame([], $stack->private->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? []);

        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
        self::assertSame(['gtm'], $this->codes($stack), 'the retry inserts again');
    }

    /**
     * A failed private write (a unique violation: not a conflict, rethrown unchanged) closes only the private manager:
     * the application's manager stays open with its pending work, and the next bundle write works.
     */
    #[DataProvider('databases')]
    public function testAFailedPrivateWriteClosesOnlyThePrivateManager(?string $url): void
    {
        $stack = $this->fresh($url);
        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
        $note = new HostNote();
        $stack->host->persist($note);

        $failure = $this->failureOf(static fn () => $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm'))));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
        self::assertTrue($stack->host->isOpen(), 'the application\'s manager is never closed by a bundle failure');
        self::assertTrue($stack->host->getUnitOfWork()->isScheduledForInsert($note));
        self::assertFalse($stack->host->getConnection()->isTransactionActive());

        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('ga4')));
        self::assertSame(['ga4', 'gtm'], $this->codes($stack), 'the private manager was replaced');
        self::assertSame(0, $this->rowCount($stack, 'host_note'));
    }

    /**
     * REQ-RUNTIME-003 rule 6 in private mode: an edit that meets a row locked by another connection answers 409 +
     * Retry-After + CONFLICT_001 (nothing committed), the row keeps the other connection's value, and the retry saves.
     */
    #[DataProvider('servers')]
    public function testALockConflictInPrivateModeAnswersConflict001AndTheRetrySaves(?string $url): void
    {
        $a  = $this->fresh($url);
        $b  = $this->stack();
        $id = $this->seed($a, 'gtm');

        $a->host->getConnection()->beginTransaction();
        $a->host->getConnection()->executeStatement('UPDATE marketing_tool SET sortOrder = 7 WHERE id = ?', [$id]);

        $edit = static function (EntityManagerInterface $em) use ($id): void {
            $tool = $em->find(MarketingTool::class, $id);
            self::assertInstanceOf(MarketingTool::class, $tool);
            $tool->setSortOrder(99);
        };
        $failure = $this->failureOf(static fn () => $b->privateUnit()->run($edit));

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $answer = $this->answer($failure);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertTrue($b->host->isOpen(), 'B\'s application manager untouched');

        $a->host->getConnection()->commit();
        self::assertSame(7, (int) $a->host->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'));

        $b->privateUnit()->run($edit);
        self::assertSame(99, (int) $a->host->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'));
    }

    /**
     * REQ-RUNTIME-003 on the 2.0 default path: two catalog seeds of one profile at once, each through its own private
     * manager. B waits on A's uncommitted unique key: 409 + CONFLICT_001, none of B's rows. After A committed, B's
     * retry finds every code and writes nothing twice.
     */
    #[DataProvider('servers')]
    public function testTwoCatalogSeedsInPrivateModeNeverDuplicateACode(?string $url): void
    {
        $a       = $this->fresh($url);
        $b       = $this->stack();
        $catalog = count((new MarketingToolCatalog())->all());

        $a->host->getConnection()->beginTransaction();
        self::assertSame($catalog, $a->admin()->seedCatalog('default'));

        $failure = $this->failureOf(static fn (): int => $b->admin()->seedCatalog('default'));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $this->answer($failure)->getHeaders());
        self::assertTrue($b->host->isOpen(), 'B\'s application manager untouched');

        $a->host->getConnection()->commit();
        self::assertSame(0, $b->admin()->seedCatalog('default'), 'the retry finds every code');
        self::assertSame($catalog, $this->rowCount($a, 'marketing_tool'), 'one row per code');
    }

    /**
     * REQ-RUNTIME-003 on the 2.0 default path: the YAML import is all or nothing. A row locked by another connection
     * answers 409 + CONFLICT_001 and creates none of the other tools; the retry upserts all of them once.
     */
    #[DataProvider('servers')]
    public function testAYamlImportInPrivateModeThatMeetsALockedRowWritesNothingAndTheRetryWritesAll(?string $url): void
    {
        $a  = $this->fresh($url);
        $b  = $this->stack();
        $id = $this->seed($a, 'gtm');

        $a->host->getConnection()->beginTransaction();
        $a->host->getConnection()->fetchOne('SELECT id FROM marketing_tool WHERE id = ? FOR UPDATE', [$id]);
        $failure = $this->failureOf(static fn (): int => $b->admin()->importFromYaml('default'));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $this->answer($failure)->getHeaders());
        $a->host->getConnection()->rollBack();
        self::assertSame(['gtm'], $this->codes($a), '"ga4" was not created');

        self::assertSame(2, $b->admin()->importFromYaml('default'));
        self::assertSame(['ga4', 'gtm'], $this->codes($a));
        self::assertSame(2, $b->admin()->importFromYaml('default'), 'run again: upserted, not duplicated');
        self::assertSame(2, $this->rowCount($a, 'marketing_tool'));
    }

    /**
     * REQ-RUNTIME-003 rule 6 on the 2.0 default path: a lock conflict after a commit earlier in the same request answers
     * CONFLICT_002 without Retry-After (the commit counted by the real commit-tracking middleware).
     */
    #[DataProvider('servers')]
    public function testALockConflictInPrivateModeAfterACommitAnswersConflict002(?string $url): void
    {
        $a        = $this->fresh($url);
        $id       = $this->seed($a, 'gtm');
        $ledger   = new CommitLedger();
        $b        = $this->stack($ledger);
        $listener = new MarketingToolConflictListener(null, $ledger);
        $request  = Request::create('/admin/marketing/import-yaml', 'POST');
        $request->attributes->set('_controller', self::ADMIN_CONTROLLER);
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $b->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('ga4')));

        $a->host->getConnection()->beginTransaction();
        $a->host->getConnection()->fetchOne('SELECT id FROM marketing_tool WHERE id = ? FOR UPDATE', [$id]);
        $failure = $this->failureOf(static fn () => $b->privateUnit()->run(static function (EntityManagerInterface $em) use ($id): void {
            $tool = $em->find(MarketingTool::class, $id);
            self::assertInstanceOf(MarketingTool::class, $tool);
            $tool->setSortOrder(99);
        }));
        $a->host->getConnection()->rollBack();

        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(['ga4', 'gtm'], $this->codes($a), 'the first write is committed');
        self::assertSame(0, (int) $a->host->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool WHERE id = ?', [$id]), 'the second is not');
    }

    private function fresh(?string $url): PrivateStack
    {
        if ($url === null) {
            self::markTestSkipped('Set MK_MYSQL_URL, MK_MARIADB_URL and MK_POSTGRES_URL to run the real-database tests.');
        }

        if ($url === 'sqlite') {
            $this->sqliteFile = sys_get_temp_dir() . '/nowo-marketing-kit-private-' . uniqid('', true) . '.sqlite';
            $this->params     = ['driver' => 'pdo_sqlite', 'path' => $this->sqliteFile];
        } else {
            $this->params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        }

        $stack      = $this->stack();
        $schemaTool = new SchemaTool($stack->host);
        $metadata   = $stack->host->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $stack;
    }

    private function stack(?CommitLedger $ledger = null): PrivateStack
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity', dirname(__DIR__, 2) . '/Support/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-marketing-kit-database-tests/proxies');
            $config->setProxyNamespace('NowoMarketingKitPrivateDatabaseTestProxies');
        }

        $stack    = new PrivateStack();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static fn (): EntityManagerInterface => $stack->host);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (): EntityManagerInterface => $stack->host);
        $registry->method('getDefaultManagerName')->willReturn('default');
        $stack->registry        = $registry;
        $stack->private         = new PrivateEntityManager($registry, 'default', MarketingTool::class, NowoMarketingKitExtension::BUNDLE_NAME);
        $stack->privateRegistry = new PrivateManagerRegistry($registry, $stack->private, NowoMarketingKitExtension::OWN_NAMESPACES, 'default');

        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares($ledger instanceof CommitLedger
            ? [new PrivateEntityManagerMiddleware($stack->private, 'nowo_marketing_kit_'), new CommitTrackingMiddleware($ledger)]
            : [new PrivateEntityManagerMiddleware($stack->private, 'nowo_marketing_kit_')]);
        $connection = DriverManager::getConnection($this->params, $dbal);
        $platform   = $connection->getDatabasePlatform();
        if (!$platform instanceof SQLitePlatform) {
            $connection->executeStatement($platform instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 2' : "SET lock_timeout = '2s'");
        }
        $stack->host    = new EntityManager($connection, $config);
        $this->stacks[] = $stack;

        return $stack;
    }

    private function seed(PrivateStack $stack, string $code): int
    {
        $tool = (new MarketingTool())->setCode($code);
        $stack->privateUnit()->run(static fn (EntityManagerInterface $em) => $em->persist($tool));

        return (int) $tool->getId();
    }

    /**
     * @return list<string>
     */
    private function codes(PrivateStack $stack): array
    {
        /** @var list<string> $codes */
        $codes = $stack->host->getConnection()->fetchFirstColumn('SELECT code FROM marketing_tool ORDER BY code');

        return $codes;
    }

    private function rowCount(PrivateStack $stack, string $table): int
    {
        return (int) $stack->host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
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

        self::fail('The write must fail.');
    }

    private function answer(Throwable $failure): HttpException
    {
        $request = Request::create('/admin/marketing/1/edit', 'POST');
        $request->attributes->set('_controller', self::ADMIN_CONTROLLER);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new MarketingToolConflictListener())->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, 'mapped: ' . $failure::class . ': ' . $failure->getMessage());

        return $answer;
    }
}

/**
 * An application manager on its own connection and the bundle's private manager on it, wired as the bundle does.
 */
final class PrivateStack
{
    public EntityManagerInterface $host;

    public ManagerRegistry $registry;

    public PrivateEntityManager $private;

    public PrivateManagerRegistry $privateRegistry;

    /** The bundle's own manager path (2.0: the only one the container wires). */
    public function privateUnit(): MarketingToolWriteUnit
    {
        return new MarketingToolWriteUnit($this->private, $this->privateRegistry, 'default', true);
    }

    public function repository(): MarketingToolRepository
    {
        return new MarketingToolRepository($this->privateRegistry);
    }

    /** The admin service as the container wires it (2.0): the private manager and its write unit. */
    public function admin(): MarketingToolAdminService
    {
        return new MarketingToolAdminService($this->repository(), new MarketingToolCatalog(), $this->private, [
            'default' => ['enabled' => true, 'tools' => [
                'gtm' => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sort_order' => 10, 'options' => ['container_id' => 'GTM-AAAA']],
                'ga4' => ['type' => 'ga4', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sort_order' => 20, 'options' => ['measurement_id' => 'G-AAAA']],
            ]],
        ], 'default', $this->privateUnit());
    }
}
