<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function count;
use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.13 (REQ-RUNTIME-003): two connections on real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 servers, on the bundle's
 * write paths: admin create, edit and toggle of a marketing tool, the catalog seed and the YAML import.
 *
 * Every test session sets a 2 s `innodb_lock_wait_timeout` / `lock_timeout`, so a lock wait fails fast instead of
 * hanging. Set MK_MYSQL_URL, MK_MARIADB_URL and MK_POSTGRES_URL; CI runs the `database` group with `--fail-on-skipped`.
 */
#[Group('database')]
final class MarketingToolConcurrencyDatabaseTest extends TestCase
{
    private const ADMIN_CONTROLLER = 'Nowo\\MarketingKitBundle\\Controller\\MarketingToolAdminController::edit';

    private const IMPORT_CONTROLLER = 'Nowo\\MarketingKitBundle\\Controller\\MarketingToolAdminController::importYaml';

    private const YAML = [
        'default' => [
            'enabled' => true,
            'tools'   => [
                'gtm' => ['type' => 'gtm', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sort_order' => 10, 'options' => ['container_id' => 'GTM-AAAA']],
                'ga4' => ['type' => 'ga4', 'enabled' => true, 'category' => 'analytics', 'position' => 'head', 'sort_order' => 20, 'options' => ['measurement_id' => 'G-AAAA']],
            ],
        ],
    ];

    /** @var list<EntityManagerInterface> */
    private array $managers = [];

    /** @var array<string, mixed> */
    private array $params = [];

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->getConnection()->close();
        }

        $this->managers = [];
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function databases(): iterable
    {
        foreach (['mysql' => 'MK_MYSQL_URL', 'mariadb' => 'MK_MARIADB_URL', 'postgres' => 'MK_POSTGRES_URL'] as $name => $variable) {
            $url = getenv($variable);
            yield $name => [is_string($url) && $url !== '' ? $url : null];
        }
    }

    /**
     * Two admins create the same code in one profile. While A's insert is not committed, B's insert waits on the unique
     * key and meets the 2 s lock timeout: 409 + Retry-After + CONFLICT_001, nothing of B stored, B's manager replaced.
     * Once A committed, B's retry is refused by the unique key: the domain 409 (`X-Error-Code`), never a CONFLICT_*
     * code. Exactly one row.
     */
    #[DataProvider('databases')]
    public function testTwoAdminsCreatingOneCodeKeepOneRowAndAnswerConflict001ThenTheDomain409(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $holder = new ManagerHolder($this);

        $a->getConnection()->beginTransaction();
        $a->persist($this->tool('gtm', 10));
        $a->flush();

        $failure = $this->failureOf(fn () => $this->unit($holder)->run(fn (EntityManagerInterface $em) => $em->persist($this->tool('gtm', 99))));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $answer = $this->answer($failure, new MarketingToolConflictListener(), self::ADMIN_CONTROLLER);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(503, $this->answer($failure, new MarketingToolConflictListener(new ConflictResponsePolicy(503)), self::ADMIN_CONTROLLER)->getStatusCode(), 'host option');
        self::assertSame(1, $holder->resets, 'the closed manager was replaced');

        $a->getConnection()->commit();

        $duplicate = $this->failureOf(fn () => $this->unit($holder)->run(fn (EntityManagerInterface $em) => $em->persist($this->tool('gtm', 99))));
        self::assertInstanceOf(UniqueConstraintViolationException::class, $duplicate, 'not a lock conflict: never wrapped');
        $domain = $this->answer($duplicate, new MarketingToolConflictListener(), self::IMPORT_CONTROLLER);
        self::assertInstanceOf(ConflictHttpException::class, $domain);
        self::assertSame([MarketingToolConflictListener::ERROR_CODE_HEADER => MarketingToolConflictListener::DUPLICATE_CODE], $domain->getHeaders());

        self::assertSame([['gtm', 10]], $this->rows($a), 'exactly one row, A\'s');
        self::assertSame([['gtm', 10]], $this->rows($holder->manager), 'B\'s connection usable');
    }

    /**
     * Two admins edit one tool; B read it before A saved. B's save is refused by the optimistic version (the UPDATE
     * finds no row of the version B read): no lost update, A's row stays whole, B's manager is replaced. B's form
     * carried version 1; checked before the write (lock()) it is refused the same way without touching the row.
     */
    #[DataProvider('databases')]
    public function testAnEditOfAStaleToolIsRefusedAndNeverOverwritesTheNewerOne(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $id     = $this->seed($a, 'gtm', 10);
        $holder = new ManagerHolder($this);

        $stale = $holder->manager->find(MarketingTool::class, $id); // B opens the edit form
        self::assertInstanceOf(MarketingTool::class, $stale);
        self::assertSame(1, $stale->getVersion());

        $this->unit(new ManagerHolder($this, $a))->run(static function (EntityManagerInterface $em) use ($id): void {
            $row = $em->find(MarketingTool::class, $id);
            self::assertInstanceOf(MarketingTool::class, $row);
            $row->setPosition('body_end'); // A saves first
        });

        $stale->setSortOrder(99);
        $failure = $this->failureOf(fn () => $this->unit($holder)->run(static fn (EntityManagerInterface $em): null => null));
        self::assertInstanceOf(OptimisticLockException::class, $failure);
        self::assertSame(MarketingToolConflictListener::STALE_TOOL, MarketingToolConflictListener::domainCode($failure));
        self::assertSame([['gtm', 10]], $this->rows($a), 'B\'s sort order never reached the row');
        self::assertSame('body_end', $a->getConnection()->fetchOne('SELECT position FROM marketing_tool'), 'A\'s change kept');
        self::assertSame(2, (int) $a->getConnection()->fetchOne('SELECT version FROM marketing_tool'));

        $reread = $holder->manager->find(MarketingTool::class, $id); // B reloads the page, form at version 2
        self::assertInstanceOf(MarketingTool::class, $reread);
        $refused = $this->failureOf(fn () => $this->unit($holder)->run(static fn (EntityManagerInterface $em) => $em->lock($reread, LockMode::OPTIMISTIC, 1)));
        self::assertInstanceOf(OptimisticLockException::class, $refused, 'a form opened on version 1 is refused before writing');

        $current = $holder->manager->find(MarketingTool::class, $id); // the refusal forgot the row: read it again
        self::assertInstanceOf(MarketingTool::class, $current);
        $this->unit($holder)->run(static fn (EntityManagerInterface $em) => $em->lock($current->setSortOrder(99), LockMode::OPTIMISTIC, 2));
        self::assertSame([['gtm', 99]], $this->rows($a), 'the retry on the current version saves');
        self::assertSame('body_end', $a->getConnection()->fetchOne('SELECT position FROM marketing_tool'));
    }

    /**
     * Two admins toggle one tool from lists rendered at version 1. A's toggle commits (disabled, version 2). B's toggle
     * flips its own copy read before A's commit: the UPDATE checks that version, so B's flip never silently undoes A's
     * (the lost toggle of 1.12). B's toggle rendered for version 1 is refused before writing once B reads again.
     */
    #[DataProvider('databases')]
    public function testTwoConcurrentTogglesNeverLoseOne(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $id     = $this->seed($a, 'gtm', 10);
        $holder = new ManagerHolder($this);
        $toggle = (static fn (MarketingTool $tool, int $renderedVersion): callable => static function (EntityManagerInterface $em) use ($tool, $renderedVersion): void {
            $em->lock($tool, LockMode::OPTIMISTIC, $renderedVersion);
            $tool->setEnabled(!$tool->isEnabled());
        });

        $bCopy = $holder->manager->find(MarketingTool::class, $id); // B's request loaded the row
        self::assertInstanceOf(MarketingTool::class, $bCopy);

        $aCopy = $a->find(MarketingTool::class, $id);
        self::assertInstanceOf(MarketingTool::class, $aCopy);
        $this->unit(new ManagerHolder($this, $a))->run($toggle($aCopy, 1));
        self::assertFalse((bool) $a->getConnection()->fetchOne('SELECT enabled FROM marketing_tool'));

        $failure = $this->failureOf(fn () => $this->unit($holder)->run($toggle($bCopy, 1)));
        self::assertInstanceOf(OptimisticLockException::class, $failure, 'the UPDATE found no row of version 1');
        self::assertFalse((bool) $a->getConnection()->fetchOne('SELECT enabled FROM marketing_tool'), 'A\'s toggle kept');

        $reread = $holder->manager->find(MarketingTool::class, $id);
        self::assertInstanceOf(MarketingTool::class, $reread);
        $refused = $this->failureOf(fn () => $this->unit($holder)->run($toggle($reread, 1)));
        self::assertInstanceOf(OptimisticLockException::class, $refused, 'a toggle rendered for version 1 is refused');
        self::assertSame(2, (int) $a->getConnection()->fetchOne('SELECT version FROM marketing_tool'), 'nothing written');

        $current = $holder->manager->find(MarketingTool::class, $id);
        self::assertInstanceOf(MarketingTool::class, $current);
        $this->unit($holder)->run($toggle($current, 2));
        self::assertTrue((bool) $a->getConnection()->fetchOne('SELECT enabled FROM marketing_tool'), 'two toggles, two flips');
        self::assertSame(3, (int) $a->getConnection()->fetchOne('SELECT version FROM marketing_tool'));
    }

    /**
     * An edit that meets a row lock (another write of the tool in flight) answers 409 + CONFLICT_001 with nothing
     * written, and the retry once the lock is gone writes exactly once.
     */
    #[DataProvider('databases')]
    public function testAnEditThatMeetsALockedRowAnswersConflict001AndTheRetrySaves(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $id     = $this->seed($a, 'gtm', 10);
        $holder = new ManagerHolder($this);

        $a->getConnection()->beginTransaction();
        $a->getConnection()->fetchOne('SELECT id FROM marketing_tool WHERE id = ? FOR UPDATE', [$id]);

        $edit = static function (EntityManagerInterface $em) use ($id): void {
            $row = $em->find(MarketingTool::class, $id);
            self::assertInstanceOf(MarketingTool::class, $row);
            $row->setSortOrder(99);
        };
        $failure = $this->failureOf(fn () => $this->unit($holder)->run($edit));
        $answer  = $this->answer($failure, new MarketingToolConflictListener(), self::ADMIN_CONTROLLER);
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $a->getConnection()->rollBack();
        self::assertSame([['gtm', 10]], $this->rows($a), 'nothing written');

        $this->unit($holder)->run($edit);
        self::assertSame([['gtm', 99]], $this->rows($a));
        self::assertSame(2, (int) $a->getConnection()->fetchOne('SELECT version FROM marketing_tool'), 'written exactly once');
    }

    /**
     * Two catalog seeds of one profile at once. While A's seed is not committed, B's seed waits on the unique key of
     * the first code: 409 + CONFLICT_001 and none of B's rows. Run again after A committed, B's seed finds every code
     * and writes nothing twice (idempotent).
     */
    #[DataProvider('databases')]
    public function testTwoCatalogSeedsNeverDuplicateACode(?string $url): void
    {
        $a       = $this->freshDatabase($url);
        $holder  = new ManagerHolder($this);
        $catalog = count((new MarketingToolCatalog())->all());

        $a->getConnection()->beginTransaction();
        self::assertSame($catalog, $this->admin(new ManagerHolder($this, $a))->seedCatalog('default'));

        $failure = $this->failureOf(fn (): int => $this->admin($holder)->seedCatalog('default'));
        $answer  = $this->answer($failure, new MarketingToolConflictListener(), self::IMPORT_CONTROLLER);
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $a->getConnection()->commit();
        self::assertSame(0, $this->admin($holder)->seedCatalog('default'), 'the retry finds every code');
        self::assertSame($catalog, (int) $a->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'), 'one row per code');
    }

    /**
     * The YAML import is all or nothing: when one of its rows is locked by an admin save in flight it answers
     * 409 + CONFLICT_001 and creates none of the other tools. Run again, it upserts all of them once.
     */
    #[DataProvider('databases')]
    public function testAYamlImportThatMeetsALockedRowWritesNothingAndTheRetryWritesAll(?string $url): void
    {
        $a      = $this->freshDatabase($url);
        $id     = $this->seed($a, 'gtm', 50);
        $holder = new ManagerHolder($this);

        $a->getConnection()->beginTransaction();
        $a->getConnection()->fetchOne('SELECT id FROM marketing_tool WHERE id = ? FOR UPDATE', [$id]);

        $failure = $this->failureOf(fn (): int => $this->admin($holder)->importFromYaml('default'));
        $answer  = $this->answer($failure, new MarketingToolConflictListener(), self::IMPORT_CONTROLLER);
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());

        $a->getConnection()->rollBack();
        self::assertSame([['gtm', 50]], $this->rows($a), '"ga4" was not created');

        self::assertSame(2, $this->admin($holder)->importFromYaml('default'));
        self::assertSame([['ga4', 20], ['gtm', 10]], $this->rows($a));
        self::assertSame(2, $this->admin($holder)->importFromYaml('default'), 'run again: upserted, not duplicated');
        self::assertSame(2, (int) $a->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    /**
     * A lock conflict after a commit earlier in the same request answers CONFLICT_002 without Retry-After: the commit
     * is counted by the real commit-tracking middleware on the bundle's connection.
     */
    #[DataProvider('databases')]
    public function testALockConflictAfterACommitEarlierInTheRequestAnswersConflict002(?string $url): void
    {
        $a        = $this->freshDatabase($url);
        $id       = $this->seed($a, 'gtm', 10);
        $ledger   = new CommitLedger();
        $holder   = new ManagerHolder($this, null, $ledger);
        $listener = new MarketingToolConflictListener(null, $ledger);
        $request  = $this->request(self::IMPORT_CONTROLLER);
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        $this->unit($holder)->run(fn (EntityManagerInterface $em) => $em->persist($this->tool('ga4', 20)));

        $a->getConnection()->beginTransaction();
        $a->getConnection()->fetchOne('SELECT id FROM marketing_tool WHERE id = ? FOR UPDATE', [$id]);
        $failure = $this->failureOf(fn () => $this->unit($holder)->run(static function (EntityManagerInterface $em) use ($id): void {
            $row = $em->find(MarketingTool::class, $id);
            self::assertInstanceOf(MarketingTool::class, $row);
            $row->setSortOrder(99);
        }));
        $a->getConnection()->rollBack();

        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame([['ga4', 20], ['gtm', 10]], $this->rows($a), 'the first write is committed, the second is not');
    }

    private function freshDatabase(?string $url): EntityManagerInterface
    {
        if ($url === null) {
            self::markTestSkipped('Set MK_MYSQL_URL, MK_MARIADB_URL and MK_POSTGRES_URL to run the real-database tests.');
        }

        $this->params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $manager      = $this->manager();
        $schemaTool   = new SchemaTool($manager);
        $metadata     = $manager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        return $manager;
    }

    public function manager(?CommitLedger $ledger = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-marketing-kit-database-tests/proxies');
            $config->setProxyNamespace('NowoMarketingKitDatabaseTestProxies');
        }

        $dbal = new DbalConfiguration();
        if ($ledger instanceof CommitLedger) {
            $dbal->setMiddlewares([new CommitTrackingMiddleware($ledger)]);
        }

        $connection = DriverManager::getConnection($this->params, $dbal);
        $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 2' : "SET lock_timeout = '2s'");

        $manager          = new EntityManager($connection, $config);
        $this->managers[] = $manager;

        return $manager;
    }

    public function registryStub(): ManagerRegistry&Stub
    {
        return $this->createStub(ManagerRegistry::class);
    }

    private function unit(ManagerHolder $holder): MarketingToolWriteUnit
    {
        return new MarketingToolWriteUnit($holder->manager, $holder->registry());
    }

    private function admin(ManagerHolder $holder): MarketingToolAdminService
    {
        return new MarketingToolAdminService(new MarketingToolRepository($holder->registry()), new MarketingToolCatalog(), $holder->manager, self::YAML, 'default', $this->unit($holder));
    }

    private function tool(string $code, int $sortOrder): MarketingTool
    {
        return (new MarketingTool())->setCode($code)->setType($code)->setSortOrder($sortOrder);
    }

    private function seed(EntityManagerInterface $manager, string $code, int $sortOrder): int
    {
        $tool = $this->tool($code, $sortOrder);
        $manager->persist($tool);
        $manager->flush();
        $manager->clear();

        return (int) $tool->getId();
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function rows(EntityManagerInterface $manager): array
    {
        $rows = [];
        foreach ($manager->getConnection()->fetchAllAssociative('SELECT code, sortOrder AS sort_order FROM marketing_tool ORDER BY code') as $row) {
            $rows[] = [(string) $row['code'], (int) $row['sort_order']];
        }

        return $rows;
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

    private function request(string $controller): Request
    {
        $request = Request::create('/admin/marketing/1/edit', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function answer(Throwable $failure, MarketingToolConflictListener $listener, string $controller): HttpException
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->request($controller), HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, 'mapped: ' . $failure::class . ': ' . $failure->getMessage());

        return $answer;
    }
}

/**
 * A manager behind a registry: resetManager() replaces it by a new manager on a new connection, as DoctrineBundle does
 * for a closed manager.
 */
final class ManagerHolder
{
    public EntityManagerInterface $manager;

    public int $resets = 0;

    public function __construct(
        private readonly MarketingToolConcurrencyDatabaseTest $test,
        ?EntityManagerInterface $manager = null,
        private readonly ?CommitLedger $ledger = null,
    ) {
        $this->manager = $manager ?? $test->manager($ledger);
    }

    public function registry(): ManagerRegistry
    {
        $registry = $this->test->registryStub();
        $registry->method('getManagerForClass')->willReturnCallback(fn (): EntityManagerInterface => $this->manager);
        $registry->method('getManager')->willReturnCallback(fn (): EntityManagerInterface => $this->manager);
        $registry->method('resetManager')->willReturnCallback(function (): EntityManagerInterface {
            ++$this->resets;
            $this->manager->getConnection()->close();
            $this->manager = $this->test->manager($this->ledger);

            return $this->manager;
        });

        return $registry;
    }
}
