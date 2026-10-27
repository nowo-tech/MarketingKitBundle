<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the admin UI is enabled without form CSRF protection (REQ-SEC-005, SEC-004 L1).
 *
 * Every mutation of the admin UI (tool form, seed, import YAML, toggle, delete) relies on Symfony's form CSRF
 * extension: without it a CSRF-only form is valid with no token at all. A host that disables
 * `framework.form.csrf_protection` (or has no CSRF token manager) would silently turn those checks off, so the
 * container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_marketing_kit.web_ui.enabled') || $container->getParameter('nowo_marketing_kit.web_ui.enabled') !== true) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoMarketingKitBundle: the admin UI (web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf), or set nowo_marketing_kit.web_ui.enabled: false.');
        }
    }
}
