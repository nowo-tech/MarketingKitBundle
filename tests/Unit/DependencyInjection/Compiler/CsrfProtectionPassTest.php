<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MarketingKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * SEC-004 L1: the admin UI never runs without form CSRF protection (CSRF-only forms would be valid without token).
 */
final class CsrfProtectionPassTest extends TestCase
{
    public function testPassesWithFormCsrfProtection(): void
    {
        $container = $this->container(true);
        (new CsrfProtectionPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testSkipsWhenTheAdminUiIsDisabledOrTheBundleIsNotLoaded(): void
    {
        (new CsrfProtectionPass())->process($this->container(false, tokenManager: false, extension: false));
        (new CsrfProtectionPass())->process(new ContainerBuilder());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function missingProtection(): iterable
    {
        yield 'no token manager' => [false, true, true];
        yield 'no form CSRF extension' => [true, false, true];
        yield 'form CSRF disabled' => [true, true, false];
    }

    #[DataProvider('missingProtection')]
    public function testFailsWithoutFormCsrfProtection(bool $tokenManager, bool $extension, bool $enabled): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires CSRF protection');

        (new CsrfProtectionPass())->process($this->container(true, $tokenManager, $extension, $enabled));
    }

    private function container(bool $webUi, bool $tokenManager = true, bool $extension = true, bool $enabled = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_marketing_kit.web_ui.enabled', $webUi);
        $container->setParameter('form.type_extension.csrf.enabled', $enabled);
        if ($tokenManager) {
            $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
        }
        if ($extension) {
            $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));
        }

        return $container;
    }
}
