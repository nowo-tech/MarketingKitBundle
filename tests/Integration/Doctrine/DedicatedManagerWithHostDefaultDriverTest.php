<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * DoctrineBundle 3.4 regression: `auto_discover_entities` (on by default for a manager without `mappings` and
 * `auto_mapping`) gives the host manager's chain a default attribute driver, which claims every `#[ORM\Entity]` class,
 * so `getManagerForClass()` answered the host manager and the repository queried its database. The explicit catch-all
 * default driver reproduces it on every DoctrineBundle version.
 */
final class DedicatedManagerWithHostDefaultDriverTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DefaultDriverHostTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new DefaultDriverHostTestKernel('test', false, (bool) ($options['host_default_driver'] ?? false));
    }

    #[DataProvider('hostManagersWithADefaultDriver')]
    public function testDedicatedManagerWinsOverADefaultDriverOfTheHostManager(bool $explicitDefaultDriver): void
    {
        self::bootKernel(['host_default_driver' => $explicitDefaultDriver]);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $bundle = $this->manager($doctrine, 'bundle');

        // The host manager is not initialized yet: DoctrineBundle 3.4 asks its metadata driver.
        self::assertSame($bundle, $doctrine->getManagerForClass(MarketingTool::class));

        $host = $this->manager($doctrine, 'default');
        self::assertTrue($host->getMetadataFactory()->isTransient(MarketingTool::class), 'MarketingTool is not mapped on the host manager.');
        self::assertSame($bundle, $doctrine->getManagerForClass(MarketingTool::class));

        (new SchemaTool($bundle))->createSchema([$bundle->getClassMetadata(MarketingTool::class)]);
        $admin = self::getContainer()->get(MarketingToolAdminService::class);
        self::assertInstanceOf(MarketingToolAdminService::class, $admin);
        self::assertGreaterThan(0, $admin->seedCatalog('default'));
        $bundle->clear();

        $repository = self::getContainer()->get(MarketingToolRepository::class);
        self::assertInstanceOf(MarketingToolRepository::class, $repository);
        self::assertNotSame([], $repository->findByProfileOrdered('default'), 'The repository reads the bundle database.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hostManagersWithADefaultDriver(): iterable
    {
        yield 'host manager without mappings (DoctrineBundle 3.4 auto-discovery)' => [false];
        yield 'host manager with a catch-all default driver' => [true];
    }

    private function manager(ManagerRegistry $doctrine, string $name): EntityManagerInterface
    {
        $manager = $doctrine->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
