<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Consent;

use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\Enum\ConsentCookieNames;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CookieConsentGateTest extends TestCase
{
    public function testAllowsWhenRespectDisabled(): void
    {
        $gate = new CookieConsentGate(new RequestStack(), false);

        self::assertTrue($gate->isCategoryAllowed('marketing'));
    }

    public function testDeniesWithoutRequest(): void
    {
        $gate = new CookieConsentGate(new RequestStack(), true);

        self::assertFalse($gate->isCategoryAllowed('marketing'));
    }

    public function testAllowsWhenCategoryCookieTrue(): void
    {
        $request = Request::create('/');
        $request->cookies->set(ConsentCookieNames::category('analytics'), 'true');
        $stack = new RequestStack();
        $stack->push($request);

        $gate = new CookieConsentGate($stack, true);

        self::assertTrue($gate->isCategoryAllowed('analytics'));
        self::assertFalse($gate->isCategoryAllowed('marketing'));
    }

    public function testRequiredAlwaysAllowed(): void
    {
        $gate = new CookieConsentGate(new RequestStack(), true);

        self::assertTrue($gate->isCategoryAllowed('required'));
    }
}
