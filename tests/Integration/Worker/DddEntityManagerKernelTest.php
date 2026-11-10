<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit LD-MK-M1, kept-alive kernel (FRANKENPHP_RESET_KERNEL=false, no kernel.reset): with
 * `doctrine.entity_manager: bundle` and `auto_mapping: true` on the host's default manager, MarketingTool is mapped on
 * "bundle" only, the admin writes there, a closed bundle manager is reset before the next admin request, and the host's
 * closed default manager is never touched.
 */
final class DddEntityManagerKernelTest extends KernelTestCase
{
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
