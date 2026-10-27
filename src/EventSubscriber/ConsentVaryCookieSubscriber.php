<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\EventSubscriber;

use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds `Vary: Cookie` to a main response whose marketing tags were gated by the consent cookies (SEC-004 I3), so an
 * HTTP cache keys it on the cookies instead of serving the same tags to every visitor. Stateless (reads a request
 * attribute set by CookieConsentGate).
 */
final class ConsentVaryCookieSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->attributes->get(CookieConsentGate::VARY_COOKIE_ATTRIBUTE) !== true) {
            return;
        }

        $event->getResponse()->setVary('Cookie', false);
    }
}
