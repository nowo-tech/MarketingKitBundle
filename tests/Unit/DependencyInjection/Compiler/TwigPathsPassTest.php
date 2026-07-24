<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MarketingKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleTwigPath(): void
    {
        $container = new ContainerBuilder();
        $loader    = new Definition();
        $container->setDefinition('twig.loader.native_filesystem', $loader);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new TwigPathsPass())->process($container);

        $calls = $loader->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[array_key_last($calls)][0]);
        self::assertSame('NowoMarketingKitBundle', $calls[array_key_last($calls)][1][1]);
    }
}
