<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.14: collects the E_USER_DEPRECATED messages of a call, in order (the host-flush probe tests count them per class).
 */
trait CollectsDeprecations
{
    /**
     * @param callable(): mixed $run
     *
     * @return list<string>
     */
    private function deprecations(callable $run): array
    {
        $messages = [];
        // Test-only, restored in the finally below (never left installed).
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
