<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Google Tag Manager (container snippet). Options: container_id.
 */
final class GtmRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::Gtm->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = trim((string) ($tool->options['container_id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($tool->position === 'body_start') {
            return sprintf(
                '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
                $safe,
            );
        }

        return sprintf(
            <<<'HTML'
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');</script>
HTML,
            $safe,
        );
    }
}
