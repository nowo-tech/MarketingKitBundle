<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * 1.14: the admin controller becomes final in 2.0; a host class that extends it gets a notice when it is built.
 */
final class MarketingToolAdminControllerSubclassTest extends TestCase
{
    public function testTheBundlesOwnControllerTriggersNoNotice(): void
    {
        // failOnDeprecation: a notice here fails the test.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(MarketingToolAdminController::class, new MarketingToolAdminController(...$this->arguments()));
    }

    #[IgnoreDeprecations]
    public function testAHostSubclassTriggersTheFinalNotice(): void
    {
        // @phpstan-ignore class.extendsFinalByPhpDoc (the deprecated host subclass under test)
        $subclass = new class(...$this->arguments()) extends MarketingToolAdminController {};

        $this->expectUserDeprecationMessage('Since nowo-tech/marketing-kit-bundle 1.14: ' . sprintf(MarketingToolAdminController::SUBCLASS_DEPRECATION, $subclass::class, MarketingToolAdminController::class));

        new ($subclass::class)(...$this->arguments());
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
