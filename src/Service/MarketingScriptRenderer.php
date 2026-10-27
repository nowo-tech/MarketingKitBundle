<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Service;

use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Consent\ConsentGateInterface;
use Nowo\MarketingKitBundle\Enum\ToolCategory;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_replace;

/**
 * Builds HTML for marketing tools at a document position, gated by consent.
 *
 * - A built-in tracker stored in the `required` category (consent bypass) is gated as `marketing` unless
 *   security.allow_required_trackers is on (SEC-004 L2; e.g. a database row saved before 1.9).
 * - CSP: the `<script>` tags of built-in providers get `nonce="…"` when a nonce is passed to the Twig function or
 *   found in the request attribute security.csp_nonce_attribute (SEC-004 L4). Custom HTML is emitted as configured.
 */
final readonly class MarketingScriptRenderer
{
    /** Accepted nonce characters (base64 / base64url); anything else is ignored. */
    private const NONCE_PATTERN = '/^[A-Za-z0-9+\/_=-]{1,256}$/D';

    public function __construct(
        private MarketingConfigResolver $configResolver,
        private ToolRendererRegistry $rendererRegistry,
        private ConsentGateInterface $consentGate,
        private ?RequestStack $requestStack = null,
        private bool $allowRequiredTrackers = false,
        private ?string $cspNonceAttribute = null,
    ) {
    }

    public function renderPosition(string $position, ?string $profile = null, ?string $nonce = null): string
    {
        if (!in_array($position, ToolPosition::values(), true)) {
            return '';
        }

        $config = $this->configResolver->resolve($profile);
        if (!$config->enabled) {
            return '';
        }

        $nonce = $this->nonce($nonce);
        $parts = [];
        foreach ($config->toolsForPosition($position) as $tool) {
            if (!$this->shouldRender($tool)) {
                continue;
            }
            $html = $this->rendererRegistry->render($tool);
            if ($html === '') {
                continue;
            }
            if ($nonce !== null && $tool->type !== ToolType::Custom->value) {
                $html = (string) preg_replace('/<script(?=[\s>])/i', '<script nonce="' . $nonce . '"', $html);
            }
            $parts[] = $html;
        }

        return implode("\n", $parts);
    }

    public function renderHead(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition(ToolPosition::Head->value, $profile, $nonce);
    }

    public function renderBodyStart(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition(ToolPosition::BodyStart->value, $profile, $nonce);
    }

    public function renderBodyEnd(?string $profile = null, ?string $nonce = null): string
    {
        return $this->renderPosition(ToolPosition::BodyEnd->value, $profile, $nonce);
    }

    private function shouldRender(ResolvedTool $tool): bool
    {
        $category = $tool->category;
        if ($category === ToolCategory::Required->value && !ToolCategory::allowsRequired($tool->type, $this->allowRequiredTrackers)) {
            $category = ToolCategory::Marketing->value;
        }

        return $this->consentGate->isCategoryAllowed($category);
    }

    /**
     * The explicit nonce, else the request attribute; null when none or when it has unexpected characters.
     */
    private function nonce(?string $nonce): ?string
    {
        if ($nonce === null && $this->cspNonceAttribute !== null) {
            $request   = $this->requestStack?->getMainRequest();
            $attribute = $request instanceof Request ? $request->attributes->get($this->cspNonceAttribute) : null;
            $nonce     = is_string($attribute) ? $attribute : null;
        }

        return $nonce !== null && preg_match(self::NONCE_PATTERN, $nonce) === 1 ? $nonce : null;
    }
}
