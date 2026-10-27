<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MarketingKitBundle\DependencyInjection\Configuration;
use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * SEC-004 L2 (category allowlist, no `required` trackers without opt-in), L4 (csp_nonce_attribute) and I1 (%env()%
 * provider IDs accepted at compile time).
 */
final class ConfigurationHardeningTest extends TestCase
{
    public function testSecurityDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['security']['allow_required_trackers']);
        self::assertNull($config['security']['csp_nonce_attribute']);
    }

    public function testRequiredTrackerIsRejectedWithoutOptIn(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Built-in trackers cannot use the "required" consent category (it bypasses the consent gate): default.gtm.');

        $this->process(['profiles' => ['default' => ['tools' => ['gtm' => ['type' => 'gtm', 'category' => 'required']]]]]);
    }

    public function testRequiredTrackerIsAcceptedWithOptInAndRequiredCustomAlways(): void
    {
        $config = $this->process([
            'security' => ['allow_required_trackers' => true],
            'profiles' => ['default' => ['tools' => ['gtm' => ['type' => 'gtm', 'category' => 'required']]]],
        ]);
        self::assertSame('required', $config['profiles']['default']['tools']['gtm']['category']);

        $config = $this->process(['profiles' => ['default' => ['tools' => ['html' => ['type' => 'custom', 'category' => 'required']]]]]);
        self::assertSame('required', $config['profiles']['default']['tools']['html']['category']);
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['profiles' => ['default' => ['tools' => ['gtm' => ['type' => 'gtm', 'category' => 'necessary']]]]]);
    }

    public function testCspNonceAttribute(): void
    {
        self::assertSame('csp_nonce', $this->process(['security' => ['csp_nonce_attribute' => 'csp_nonce']])['security']['csp_nonce_attribute']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidNonceAttributes(): iterable
    {
        yield 'empty' => [''];
        yield 'array' => [['x']];
    }

    #[DataProvider('invalidNonceAttributes')]
    public function testInvalidCspNonceAttributeIsRejected(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['security' => ['csp_nonce_attribute' => $value]]);
    }

    public function testEnvProviderIdsAreCheckedAtRenderTimeOnly(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        (new NowoMarketingKitExtension())->load([[
            'profiles' => ['default' => ['tools' => [
                'gtm' => ['type' => 'gtm', 'options' => ['container_id' => '%env(default::GTM_ID)%']],
                'ga4' => ['type' => 'ga4', 'options' => ['measurement_id' => 'G-%env(GA4_SUFFIX)%']],
            ]]],
        ]], $container);

        self::assertTrue($container->hasParameter('nowo_marketing_kit.profiles'));
        self::assertFalse($container->getParameter('nowo_marketing_kit.security.allow_required_trackers'));
        self::assertNull($container->getParameter('nowo_marketing_kit.security.csp_nonce_attribute'));
    }

    public function testIsEnvPlaceholder(): void
    {
        self::assertTrue(Configuration::isEnvPlaceholder('%env(default::GTM_ID)%'));
        self::assertTrue(Configuration::isEnvPlaceholder('env_0123456789abcdef_default__GTM_ID_0123456789abcdef0123456789abcdef'));
        self::assertFalse(Configuration::isEnvPlaceholder('GTM-ABC'));
        self::assertFalse(Configuration::isEnvPlaceholder("G-1');alert(1)//"));
        self::assertFalse(Configuration::isEnvPlaceholder(null));
    }

    public function testInjectedLiteralIdIsStillRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['profiles' => ['default' => ['tools' => ['gtm' => ['type' => 'gtm', 'options' => ['container_id' => "GTM-X');alert(1);//"]]]]]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
