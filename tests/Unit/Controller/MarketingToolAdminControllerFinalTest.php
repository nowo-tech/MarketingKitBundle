<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function var_export;

use const PHP_BINARY;

/**
 * 2.0: the admin controller is final (`@final` since 1.7, runtime notice since 1.14). A host class that still extends it
 * fails as soon as PHP loads it; there is no runtime check left in the bundle.
 */
final class MarketingToolAdminControllerFinalTest extends TestCase
{
    public function testTheControllerIsFinalAndTheSubclassNoticeIsGone(): void
    {
        $class = new ReflectionClass(MarketingToolAdminController::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->hasConstant('SUBCLASS_DEPRECATION'));
        // failOnDeprecation: building the bundle's own controller triggers nothing.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(MarketingToolAdminController::class, new MarketingToolAdminController(...$this->arguments()));
    }

    /**
     * A host subclass is a PHP compile error when its class is loaded (a container build that autodiscovers it, or a
     * route that names it): run in a separate PHP process, since the error cannot be caught.
     */
    public function testAHostSubclassFailsWhenItIsLoaded(): void
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code     = 'require ' . var_export($autoload, true) . '; final class HostMarketingAdmin extends ' . MarketingToolAdminController::class . ' {}';

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('cannot extend final class ' . MarketingToolAdminController::class, implode("\n", $output));
    }

    /**
     * @return array{MarketingToolRepository, EntityManagerInterface, MarketingToolAdminService, MarketingToolCatalog, MarketingKitAccessCheckerInterface, bool}
     */
    private function arguments(): array
    {
        $manager = $this->createStub(EntityManagerInterface::class);

        return [
            $this->createStub(MarketingToolRepository::class),
            $manager,
            new MarketingToolAdminService($this->createStub(MarketingToolRepository::class), new MarketingToolCatalog(), $manager, [], 'default'),
            new MarketingToolCatalog(),
            $this->createStub(MarketingKitAccessCheckerInterface::class),
            true,
        ];
    }
}
