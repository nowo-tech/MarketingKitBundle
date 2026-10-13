<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function trim;

use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Per-provider format rules for the tracking ID option of each built-in tool.
 *
 * Used at configuration time (YAML tree), at save time (admin form) and at render time
 * (renderers emit nothing for an ID that does not match), so a crafted ID can never break
 * out of the JS string / URL / HTML attribute contexts of the provider snippets.
 */
final class ToolIdValidator
{
    /**
     * Tool type => [option key holding the ID, anchored regex].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const RULES = [
        'gtm'        => ['container_id', '/^GTM-[A-Z0-9]+$/D'],
        'ga4'        => ['measurement_id', '/^G-[A-Z0-9]+$/D'],
        'meta_pixel' => ['pixel_id', '/^\d+$/D'],
        'linkedin'   => ['partner_id', '/^\d+$/D'],
        'tiktok'     => ['pixel_id', '/^[A-Za-z0-9]+$/D'],
        'hotjar'     => ['site_id', '/^\d+$/D'],
        'clarity'    => ['project_id', '/^[A-Za-z0-9]+$/D'],
    ];

    /**
     * Option key that holds the tracking ID for a tool type, or null when the type has none (custom).
     */
    public static function optionKeyFor(string $type): ?string
    {
        return self::RULES[$type][0] ?? null;
    }

    /**
     * Returns the trimmed ID when it matches the provider format, null otherwise.
     */
    public static function normalize(string $type, mixed $value): ?string
    {
        $rule = self::RULES[$type] ?? null;
        if ($rule === null || (!is_string($value) && !is_int($value))) {
            return null;
        }

        $id = trim((string) $value);

        return preg_match($rule[1], $id) === 1 ? $id : null;
    }

    /**
     * True when the value is empty (tool not configured yet) or matches the provider format.
     */
    public static function isValidOrEmpty(string $type, mixed $value): bool
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return true;
        }

        return self::optionKeyFor($type) === null || self::normalize($type, $value) !== null;
    }

    /**
     * Encodes a validated ID as a JS string literal that is also safe inside an HTML script element.
     */
    public static function jsString(string $id): string
    {
        return json_encode($id, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }
}
