<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Meta (Facebook) Pixel. Options: pixel_id.
 */
final class MetaPixelRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::MetaPixel->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = trim((string) ($tool->options['pixel_id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            <<<'HTML'
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','%1$s');fbq('track','PageView');</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=%1$s&ev=PageView&noscript=1" alt=""/></noscript>
HTML,
            $safe,
        );
    }
}
