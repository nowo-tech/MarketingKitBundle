<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Enum;

/**
 * Built-in marketing tool providers (v1).
 */
enum ToolType: string
{
    case Gtm       = 'gtm';
    case Ga4       = 'ga4';
    case MetaPixel = 'meta_pixel';
    case LinkedIn  = 'linkedin';
    case TikTok    = 'tiktok';
    case Hotjar    = 'hotjar';
    case Clarity   = 'clarity';
    case Custom    = 'custom';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
