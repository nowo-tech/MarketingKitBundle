<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultProfileShape(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['use_database_config']);
        self::assertTrue($config['respect_cookie_consent']);
        self::assertSame('default', $config['default_profile']);
        self::assertArrayHasKey('default', $config['profiles']);
    }

    public function testInvalidDefaultProfileFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'default_profile' => 'missing',
            'profiles'        => [
                'default' => ['enabled' => true, 'tools' => []],
            ],
        ]]);
    }

    public function testToolNodeAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'tools' => [
                        'gtm' => [
                            'type'     => 'gtm',
                            'category' => 'analytics',
                            'options'  => ['container_id' => 'GTM-X'],
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertSame('gtm', $config['profiles']['default']['tools']['gtm']['type']);
        self::assertSame('analytics', $config['profiles']['default']['tools']['gtm']['category']);
    }
}
