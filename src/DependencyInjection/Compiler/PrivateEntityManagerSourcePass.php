<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\DependencyInjection\Compiler;

use Nowo\MarketingKitBundle\DependencyInjection\NowoMarketingKitExtension;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;

/**
 * REQ-ARCH-001 rule 6 (2.0), once the application's Doctrine managers are known. The bundle never flushes the
 * application's manager:
 *
 * - `doctrine.entity_manager` unset, or naming the application's default entity manager
 *   (`doctrine.default_entity_manager`, so a default manager not named "default" works without configuration): the
 *   private entity manager is created on that manager, and transaction-doctrine's {@see PrivateEntityManagerPass}
 *   checks the manager exists and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its
 *   connection only;
 * - `doctrine.entity_manager` naming a dedicated manager (the DDD option, unchanged): the bundle uses that manager
 *   directly ({@see NowoMarketingKitExtension::useDedicatedEntityManager()}) — unless the deprecated
 *   `private_entity_manager: true` is set, which keeps the 1.14 behaviour (the private manager on the dedicated one).
 *
 * The 1.x mode (the bundle flushing the application's manager), its deprecation notices and its host-flush probe are
 * gone; the configuration refuses `private_entity_manager: false`.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0) and before {@see EntityManagerOptionPass}.
 */
final class PrivateEntityManagerSourcePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoMarketingKitExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default = is_string($default) && $default !== '' ? $default : 'default';
        $named   = $container->hasParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            ? $container->getParameter(NowoMarketingKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER)
            : null;
        $named   = is_string($named) && $named !== '' ? $named : null;
        $setting = $container->hasParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER)
            && $container->getParameter(NowoMarketingKitExtension::PRIVATE_ENTITY_MANAGER_SETTING_PARAMETER) === true;

        if ($named !== null && $named !== $default && !$setting) {
            NowoMarketingKitExtension::useDedicatedEntityManager($container, $named);

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
