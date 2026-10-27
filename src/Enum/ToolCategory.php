<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Enum;

use function array_map;

/**
 * Consent category of a marketing tool (CookieConsent `Cookie_Category_<category>` names).
 *
 * `required` bypasses the consent gate; built-in trackers may only use it with
 * security.allow_required_trackers (SEC-004 L2).
 */
enum ToolCategory: string
{
    case Required    = 'required';
    case Preferences = 'preferences';
    case Analytics   = 'analytics';
    case Marketing   = 'marketing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether a tool of this type may use the `required` category (consent bypass): custom snippets always (they are
     * already behind security.allow_custom_html), built-in trackers only with security.allow_required_trackers.
     */
    public static function allowsRequired(string $type, bool $allowRequiredTrackers): bool
    {
        return $allowRequiredTrackers || $type === ToolType::Custom->value;
    }
}
