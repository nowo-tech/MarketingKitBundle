<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

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
        $id = trim((string) ($tool->options['measurement_id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=%1$s"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%1$s');</script>
HTML,
            $safe,
        );
    }
}
