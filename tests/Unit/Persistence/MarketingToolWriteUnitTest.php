<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Persistence;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function dirname;

#[CoversClass(MarketingToolWriteUnit::class)]
final class MarketingToolWriteUnitTest extends TestCase
{
    private const BUNDLE = ['Nowo\\MarketingKitBundle\\Entity' => __DIR__ . '/../../../src/Entity'];

    public function testTheHostManagerUnitFlushesTheToolAndCommits(): void
    {
        [$registry, $manager] = $this->hostManager();
        $unit                 = new MarketingToolWriteUnit($manager, $registry);

        $code = $unit->run(static function (EntityManagerInterface $em): string {
            $tool = (new MarketingTool())->setCode('gtm');
            $em->persist($tool);

            return $tool->getCode();
        });

        self::assertSame('gtm', $code);
        self::assertFalse($manager->getConnection()->isTransactionActive());
        self::assertSame(['gtm'], $manager->getConnection()->fetchFirstColumn('SELECT code FROM marketing_tool'));
    }

    public function testAToolFormBoundBeforeTheUnitIsTheBundlesOwnPendingWork(): void
    {
        [$registry, $manager] = $this->hostManager();
        $tool                 = (new MarketingTool())->setCode('gtm');
        $manager->persist($tool);
        $manager->flush();

        $tool->setSortOrder(512); // bound by the form before the unit starts
        (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);

        self::assertSame(512, (int) $manager->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'));
        self::assertSame(2, $tool->getVersion(), 'the optimistic version moved on');
    }

    public function testPendingHostWorkRefusesTheUnitOnTheHostManager(): void
    {
        [$registry, $manager] = $this->hostManager();
        $note                 = new HostNote();
        $manager->persist($note);

        try {
            (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);
            self::fail('The host work must not be carried.');
        } catch (LogicException $refused) {
            self::assertSame(MarketingToolWriteUnit::HOST_PENDING_MESSAGE, $refused->getMessage());
        }

        self::assertTrue($manager->contains($note), 'the host work is left alone');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    /**
     * Audit US-1: a refused unit also forgets the tool the form bound before it, so a later flush of the host's own
     * work never writes the refused values; the host's entity stays managed.
     */
    public function testARefusedUnitForgetsTheBoundToolButNotTheHostWork(): void
    {
        [$registry, $manager] = $this->hostManager();
        $tool                 = (new MarketingTool())->setCode('gtm');
        $manager->persist($tool);
        $manager->flush();

        $tool->setSortOrder(99); // bound by the form
        $note = new HostNote();
        $manager->persist($note); // the host's pending work

        try {
            (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): null => null);
            self::fail('The host work must not be carried.');
        } catch (LogicException) {
        }

        self::assertFalse($manager->contains($tool), 'the refused tool is forgotten');
        self::assertTrue($manager->contains($note), 'the host work is left alone');
        $manager->flush(); // the host flushes its own work later
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT sortOrder FROM marketing_tool'), 'the refused value never reached the row');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    public function testPendingHostCollectionChangesRefuseTheUnit(): void
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([new stdClass()]);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);

        $this->expectExceptionObject(new LogicException(MarketingToolWriteUnit::HOST_PENDING_MESSAGE));
        (new MarketingToolWriteUnit($manager))->run(static fn (EntityManagerInterface $em): null => null);
    }

    public function testADomainFailureOnTheHostManagerForgetsOnlyTheToolRows(): void
    {
        [$registry, $manager] = $this->hostManager();
        $note                 = new HostNote();
        $manager->persist($note);
        $manager->flush();

        $failure = new RuntimeException('refused');
        $caught  = $this->failureOf(static fn (): mixed => (new MarketingToolWriteUnit($manager, $registry))->run(static function (EntityManagerInterface $em) use ($failure): never {
            $em->persist((new MarketingTool())->setCode('gtm'));
            $em->flush();

            throw $failure;
        }));

        self::assertSame($failure, $caught);
        self::assertTrue($manager->isOpen(), 'an open host manager is never closed');
        self::assertTrue($manager->contains($note), 'nor cleared');
        self::assertSame([], $manager->getUnitOfWork()->getIdentityMap()[MarketingTool::class] ?? [], 'the rolled-back tool is forgotten');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    public function testAStorageFailureThatClosedTheHostManagerReplacesIt(): void
    {
        [$registry, $manager] = $this->hostManager();
        $manager->persist((new MarketingTool())->setCode('gtm'));
        $manager->flush();
        $manager->clear();

        $caught = $this->failureOf(static fn () => (new MarketingToolWriteUnit($manager, $registry))->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
        }));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught, 'a unique violation is not a conflict: rethrown unchanged');
        self::assertFalse($manager->isOpen());
        $replacement = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $replacement);
        self::assertNotSame($manager, $replacement, 'the closed manager was replaced');
        self::assertTrue($replacement->isOpen());
    }

    public function testAClosedHostManagerWithoutARegistryIsLeftToTheHost(): void
    {
        [, $manager] = $this->hostManager();
        $manager->persist((new MarketingTool())->setCode('gtm'));
        $manager->flush();
        $manager->clear();

        $caught = $this->failureOf(static fn () => (new MarketingToolWriteUnit($manager))->run(static function (EntityManagerInterface $em): void {
            $em->persist((new MarketingTool())->setCode('gtm'));
        }));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught);
        self::assertFalse($manager->isOpen());
    }

    public function testALockConflictLeavesAsATransientStorageFailureWithoutHint(): void
    {
        [$registry, $manager] = $this->hostManager();
        $deadlock             = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);

        $caught = $this->failureOf(static fn (): mixed => (new MarketingToolWriteUnit($manager, $registry))->run(static fn (EntityManagerInterface $em): never => throw $deadlock));

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame($deadlock, $caught->getPrevious());
        self::assertNull($caught->retryAfterSeconds(), 'the configured conflict_response decides');
    }

    public function testTheBundlesOwnManagerIsResetAfterAStorageFailure(): void
    {
        $registry = new SqliteManagerRegistry(['default' => ['Nowo\\MarketingKitBundle\\Tests\\Support\\Entity' => dirname(__DIR__, 2) . '/Support/Entity'], 'mk' => self::BUNDLE]);
        $manager  = $registry->getManager('mk');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $unit = new MarketingToolWriteUnit($manager, $registry, 'mk', true);

        $unit->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm')));
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));

        $caught = $this->failureOf(static fn () => $unit->run(static fn (EntityManagerInterface $em) => $em->persist((new MarketingTool())->setCode('gtm'))));

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught);
        self::assertNotSame($manager, $registry->getManager('mk'), 'the bundle\'s own manager was reset');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM marketing_tool'));
    }

    /**
     * @return array{0: SqliteManagerRegistry, 1: EntityManagerInterface}
     */
    private function hostManager(): array
    {
        $registry = new SqliteManagerRegistry(['default' => self::BUNDLE + ['Nowo\\MarketingKitBundle\\Tests\\Support\\Entity' => dirname(__DIR__, 2) . '/Support/Entity']]);
        $manager  = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

        return [$registry, $manager];
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

        self::fail('The unit must fail.');
    }
}
