<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FrankenPHP worker mode with the kernel kept alive and never reset (FRANKENPHP_RESET_KERNEL=false, no services
 * resetter): one booted kernel serves every request through the http_kernel service, so kernel.reset never runs.
 * Zero configuration (2.0): the bundle's manager is its private entity manager on the default connection. A closed
 * private manager is replaced before the next admin request, which then renders from the database; the application's
 * own managers ("default", the source of the private manager, and "reporting"), closed by the application, are never
 * reset by the bundle.
 */
final class WorkerClosedManagerRecoveryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkerTestKernel::class;
    }

    public function testBundleManagerRecoversOnTheNextRequestAndHostManagerIsUntouched(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $default = $this->manager($doctrine, 'default');
        $host    = $this->manager($doctrine, 'reporting');
        (new SchemaTool($default))->createSchema([$default->getClassMetadata(MarketingTool::class)]);
        $bundle = $container->get(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertInstanceOf(PrivateEntityManager::class, $bundle);
        $http = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $router = $container->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $path = $router->generate('nowo_marketing_kit_admin_index');

        $host->close();
        $default->close();
        for ($i = 0; $i < 20; ++$i) {
            // A failed admin flush of the previous request left the bundle manager closed.
            $bundle->close();
            $this->serve($http, $path);

            self::assertTrue($bundle->isOpen(), 'The bundle manager is replaced before the next admin request.');
            self::assertFalse($host->isOpen(), 'The bundle must never reset the host manager.');
            self::assertFalse($default->isOpen(), 'Nor the application\'s default manager the private one is created on.');
        }
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
