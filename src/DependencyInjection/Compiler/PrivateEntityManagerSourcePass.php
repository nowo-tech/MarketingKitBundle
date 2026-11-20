<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection\Compiler;

use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_bool;
use function is_string;
use function trigger_deprecation;

/**
 * 1.14 (REQ-ARCH-001 rule 6, `doctrine.private_entity_manager`).
 *
 * - Private mode (`true`): resolves the application's manager the private one is created on — `doctrine.entity_manager`,
 *   else the application's default entity manager (`doctrine.default_entity_manager`, so a default manager not named
 *   "default" works without configuration) — then runs transaction-doctrine's {@see PrivateEntityManagerPass}, which
 *   checks that manager exists and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its
 *   connection only.
 * - Unset (`null`) or `false` with `doctrine.entity_manager` naming the application's default manager (audit XD-4): the
 *   bundle flushes that manager, as with `entity_manager` unset, so the same deprecation notice is triggered (the
 *   extension triggers it for an unset `entity_manager`; it cannot know the default manager's name). The host-flush
 *   probe runs in that configuration too (the write unit takes the application's-manager path).
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class PrivateEntityManagerSourcePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default = is_string($default) && $default !== '' ? $default : 'default';
        $named   = $container->hasParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            ? $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            : null;
        $named   = is_string($named) && $named !== '' ? $named : null;
        $setting = $container->hasParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER)
            ? $container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER)
            : true;
        $notice = NowoMarketingKitExtension::privateEntityManagerNotice(is_bool($setting) ? $setting : null);

        if ($notice !== null && $named === $default) {
            trigger_deprecation('nowo-tech/marketing-kit-bundle', '1.14', $notice);
        }

        if (!$container->hasDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $container->setParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $named ?? $default);

        (new PrivateEntityManagerPass(
            NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE,
            NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER,
            'nowo_marketing_kit.doctrine.entity_manager',
        ))->process($container);
    }
}
