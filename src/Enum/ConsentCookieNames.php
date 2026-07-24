<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Enum;

/**
 * Cookie names compatible with nowo-tech/cookie-consent-bundle (CookieNameEnum).
 *
 * Duplicated intentionally so MarketingKit stays a soft dependency (suggest only).
 */
final class ConsentCookieNames
{
    public const CONSENT_SAVED = 'Cookie_Consent';

    public const CATEGORY_PREFIX = 'Cookie_Category_';

    public static function category(string $category): string
    {
        return self::CATEGORY_PREFIX . $category;
    }
}
