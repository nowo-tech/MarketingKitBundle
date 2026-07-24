<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\MarketingKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Twig\MarketingKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoMarketingKitExtensionTest extends TestCase
{
    public function testLoadPublishesParametersAndServices(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoMarketingKitExtension();
        $extension->load([[
            'use_database_config'    => true,
            'respect_cookie_consent' => false,
            'doctrine'               => ['table_prefix' => 'app_', 'connection' => 'default'],
            'default_profile'        => 'default',
            'profiles'               => [
                'default' => [
                    'enabled' => true,
                    'tools'   => [],
                ],
            ],
        ]], $container);

        self::assertSame('nowo_marketing_kit', $extension->getAlias());
        self::assertTrue($container->getParameter('nowo_marketing_kit.use_database_config'));
        self::assertFalse($container->getParameter('nowo_marketing_kit.respect_cookie_consent'));
        self::assertSame('app_', $container->getParameter('nowo_marketing_kit.doctrine.table_prefix'));
        self::assertTrue($container->hasDefinition(TablePrefixListener::class));
        self::assertTrue($container->hasDefinition(MarketingScriptRenderer::class));
        self::assertTrue($container->hasDefinition(MarketingKitExtension::class));
    }

    public function testLoadWithoutTablePrefixSkipsListener(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoMarketingKitExtension();
        $extension->load([[]], $container);

        self::assertFalse($container->hasDefinition(TablePrefixListener::class));
        self::assertSame('', $container->getParameter('nowo_marketing_kit.doctrine.table_prefix'));
    }
}
