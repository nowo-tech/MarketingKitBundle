<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

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
        $id = trim((string) ($tool->options['project_id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            <<<'HTML'
<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","%s");</script>
HTML,
            $safe,
        );
    }
}
