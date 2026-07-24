<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Provider;

use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Enum\ToolType;

use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * LinkedIn Insight Tag. Options: partner_id.
 */
final class LinkedInRenderer implements ToolRendererInterface
{
    public function supports(string $type): bool
    {
        return $type === ToolType::LinkedIn->value;
    }

    public function render(ResolvedTool $tool): string
    {
        $id = trim((string) ($tool->options['partner_id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $safe = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            <<<'HTML'
<script>_linkedin_partner_id="%1$s";window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";s.parentNode.insertBefore(b,s);})(window.lintrk);</script>
<noscript><img height="1" width="1" style="display:none;" alt="" src="https://px.ads.linkedin.com/collect/?pid=%1$s&fmt=gif"/></noscript>
HTML,
            $safe,
        );
    }
}
