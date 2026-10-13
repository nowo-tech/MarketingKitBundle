<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

/**
 * Google Analytics 4 (gtag). Options: measurement_id.
 */
final class Ga4Renderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::Ga4->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = ToolIdValidator::normalize(ToolType::Ga4->value, $tool->options['measurement_id'] ?? null);
        if ($id === null) {
            return '';
        }

        return sprintf(
            <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=%1$s"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',%2$s);</script>
HTML,
            $id,
            ToolIdValidator::jsString($id),
        );
    }
}
