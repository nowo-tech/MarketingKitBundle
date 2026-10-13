<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

/**
 * Microsoft Clarity. Options: project_id.
 */
final class ClarityRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::Clarity->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = ToolIdValidator::normalize(ToolType::Clarity->value, $tool->options['project_id'] ?? null);
        if ($id === null) {
            return '';
        }

        return sprintf(
            <<<'HTML'
<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script",%s);</script>
HTML,
            ToolIdValidator::jsString($id),
        );
    }
}
