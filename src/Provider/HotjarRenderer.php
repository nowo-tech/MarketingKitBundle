<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

/**
 * Hotjar. Options: site_id.
 */
final class HotjarRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::Hotjar->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = trim((string) ($tool->options['site_id'] ?? ''));
        if ($id === '' || !ctype_digit($id)) {
            return '';
        }

        return sprintf(
            <<<'HTML'
<script>(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};h._hjSettings={hjid:%1$s,hjsv:6};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.async=1;r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;a.appendChild(r);})(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');</script>
HTML,
            $id,
        );
    }
}
