<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Enum;

/**
 * Where a marketing snippet is injected in the HTML document.
 */
enum ToolPosition: string
{
    case Head      = 'head';
    case BodyStart = 'body_start';
    case BodyEnd   = 'body_end';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
