<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Routing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function assert;
use function dirname;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the admin routes are opt-in. The host's default `routing.controllers` resource must not expose
 * them; the documented routing.yaml import does, and the controller (not autoconfigured) still renders its page.
 */
final class OptInRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        $this->removeKernelCaches();
    }

    protected function tearDown(): void
    {
        $this->removeKernelCaches();
    }

    public function testRoutingControllersResourceDoesNotRegisterBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $kernel = new OptInRoutesKernel(false);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            assert($router instanceof RouterInterface);
            $bundleRoutes = array_values(array_filter(
                array_keys($router->getRouteCollection()->all()),
                static fn (string $name): bool => str_starts_with($name, 'nowo_marketing_kit'),
            ));
            self::assertSame([], $bundleRoutes, 'Bundle routes must only exist when the host imports routing.yaml.');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testImportedRoutesServeTheAdminPage(): void
    {
        $kernel = new OptInRoutesKernel(true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $router    = $container->get('router');
            assert($router instanceof RouterInterface);
            self::assertNotNull($router->getRouteCollection()->get('nowo_marketing_kit_admin_index'));

            $entityManager = $container->get('doctrine.orm.entity_manager');
            assert($entityManager instanceof EntityManagerInterface);
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

            $response = $kernel->handle(Request::create('/admin/marketing'));

            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertStringContainsString('/admin/marketing', (string) $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    private function removeKernelCaches(): void
    {
        $cache = dirname(__DIR__, 3) . '/var/cache/';
        (new Filesystem())->remove([$cache . 'opt-in-routes-default', $cache . 'opt-in-routes-imported']);
    }
}
