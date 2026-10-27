<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\EventSubscriber;

use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\EventSubscriber\ConsentVaryCookieSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SEC-004 I3: a page whose tags depend on the consent cookies varies on Cookie.
 */
final class ConsentVaryCookieSubscriberTest extends TestCase
{
    public function testSubscribesToResponse(): void
    {
        self::assertSame([KernelEvents::RESPONSE => 'onKernelResponse'], ConsentVaryCookieSubscriber::getSubscribedEvents());
    }

    public function testAddsVaryCookieWhenTheGateReadTheConsentCookies(): void
    {
        $request = Request::create('/');
        $stack   = new RequestStack();
        $stack->push($request);
        (new CookieConsentGate($stack, true))->isCategoryAllowed('analytics');

        $response = new Response();
        $response->setVary('Accept-Language');
        (new ConsentVaryCookieSubscriber())->onKernelResponse($this->event($request, $response));

        self::assertSame(['Accept-Language', 'Cookie'], $response->getVary());
    }

    public function testLeavesOtherResponsesAlone(): void
    {
        $request = Request::create('/');
        $stack   = new RequestStack();
        $stack->push($request);
        (new CookieConsentGate($stack, true))->isCategoryAllowed('required');
        (new CookieConsentGate($stack, false))->isCategoryAllowed('marketing');

        $response = new Response();
        (new ConsentVaryCookieSubscriber())->onKernelResponse($this->event($request, $response));
        self::assertSame([], $response->getVary());

        $request->attributes->set(CookieConsentGate::VARY_COOKIE_ATTRIBUTE, true);
        (new ConsentVaryCookieSubscriber())->onKernelResponse($this->event($request, $response, HttpKernelInterface::SUB_REQUEST));
        self::assertSame([], $response->getVary());
    }

    private function event(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
    }
}
