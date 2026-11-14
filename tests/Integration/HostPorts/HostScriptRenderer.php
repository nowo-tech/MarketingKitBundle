<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\HostPorts;

use Nowo\MarketingKitBundle\Service\MarketingScriptRendererInterface;

/**
 * Host renderer replacing the bundle's one behind the Twig functions.
 */
final class HostScriptRenderer implements MarketingScriptRendererInterface
{
    public function renderPosition(string $position, ?string $profile = null, ?string $nonce = null): string
    {
        return '<!-- host:' . $position . ' -->';
    }

    public function renderHead(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition('head', $profile, $nonce);
    }

    public function renderBodyStart(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition('body_start', $profile, $nonce);
    }

    public function renderBodyEnd(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition('body_end', $profile, $nonce);
    }
}
