<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit;

use Nowo\MarketingKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\MarketingKitBundle\NowoMarketingKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoMarketingKitBundleBuildTest extends TestCase
{
    public function testBuildRegistersCompilerPasses(): void
    {
        $container = new ContainerBuilder();
        $bundle    = new NowoMarketingKitBundle();
        $bundle->build($container);

        $passes    = $container->getCompilerPassConfig()->getPasses();
        $foundTwig = false;
        foreach ($passes as $pass) {
            if ($pass instanceof TwigPathsPass) {
                $foundTwig = true;
            }
        }

        self::assertTrue($foundTwig);
        self::assertSame('nowo_marketing_kit', $bundle->getContainerExtension()?->getAlias());
    }
}
