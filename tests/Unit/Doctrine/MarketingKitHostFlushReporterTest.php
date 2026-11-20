<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Doctrine\MarketingKitHostFlushReporter;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Tests\Support\CollectsDeprecations;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostNote;
use Nowo\MarketingKitBundle\Tests\Support\Entity\HostTag;
use Nowo\MarketingKitBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function dirname;

#[CoversClass(MarketingKitHostFlushReporter::class)]
final class MarketingKitHostFlushReporterTest extends TestCase
{
    use CollectsDeprecations;

    public function testEachForeignClassIsReportedOncePerUnitOfWork(): void
    {
        $manager = $this->hostManager();
        $manager->persist(new HostNote());
        $manager->persist(new HostNote());
        $manager->persist(new HostTag());
        $manager->persist((new MarketingTool())->setCode('gtm'));
        $reporter = new MarketingKitHostFlushReporter(true);

        $messages = $this->deprecations(static function () use ($reporter, $manager): void {
            $reporter->beforeFlush($manager);
            $reporter->beforeFlush($manager); // same unit of work: nothing new
        });

        self::assertSame([
            'Your pending changes to ' . HostNote::class . ' were flushed by NowoMarketingKitBundle; 2.0 no longer does.',
            'Your pending changes to ' . HostTag::class . ' were flushed by NowoMarketingKitBundle; 2.0 no longer does.',
        ], $messages, 'one per foreign class, never the bundle\'s own MarketingTool');
        self::assertTrue($manager->isOpen());
        self::assertCount(4, $manager->getUnitOfWork()->getScheduledEntityInsertions(), 'read-only: nothing flushed or cleared');

        $reporter->reset(); // the next request, message or command
        self::assertCount(2, $this->deprecations(static fn () => $reporter->beforeFlush($manager)));
    }

    public function testTheSharedProbeIsUsedWhenGiven(): void
    {
        $manager = $this->hostManager();
        $manager->persist(new HostNote());
        $probe = new HostFlushProbe();
        self::assertCount(1, $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, MarketingKitHostFlushReporter::BUNDLE_NAME, MarketingKitHostFlushReporter::OWN_NAMESPACES)));

        // Already reported by the shared probe in this unit of work.
        self::assertSame([], $this->deprecations(static fn () => (new MarketingKitHostFlushReporter(true, $probe))->beforeFlush($manager)));
    }

    public function testADisabledReporterOrAPrivateManagerReportsNothing(): void
    {
        $manager = $this->hostManager();
        $manager->persist(new HostNote());

        self::assertSame([], $this->deprecations(static fn () => (new MarketingKitHostFlushReporter(false))->beforeFlush($manager)));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $private = new PrivateEntityManager($registry, 'default', MarketingTool::class);
        self::assertSame([], $this->deprecations(static fn () => (new MarketingKitHostFlushReporter(true))->beforeFlush($private)));
        self::assertFalse($private->isCreated(), 'a private manager is never inspected');
    }

    /**
     * Audit XD-7: whatever the probe call throws never breaks the write it observes. transaction-doctrine >= 1.9.1
     * catches its own inspection failures, so the call is made to fail here (a reporter whose probe is not set).
     */
    public function testAFailingProbeNeverBreaksTheFlush(): void
    {
        $manager = $this->hostManager();
        $manager->persist(new HostNote());
        $class    = new ReflectionClass(MarketingKitHostFlushReporter::class);
        $reporter = $class->newInstanceWithoutConstructor();
        $class->getProperty('enabled')->setValue($reporter, true); // $probe stays unset: calling it throws an Error

        $reporter->beforeFlush($manager);

        $manager->flush();
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    private function hostManager(): EntityManagerInterface
    {
        $registry = new SqliteManagerRegistry(['default' => [
            'Nowo\\MarketingKitBundle\\Entity'                 => dirname(__DIR__, 3) . '/src/Entity',
            'Nowo\\MarketingKitBundle\\Tests\\Support\\Entity' => dirname(__DIR__, 2) . '/Support/Entity',
        ]]);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

        return $manager;
    }
}
