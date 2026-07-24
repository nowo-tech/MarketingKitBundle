<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Service;

use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;

/**
 * Built-in catalog of marketing services exposed in the admin CRUD.
 *
 * @phpstan-type CatalogEntry array{
 *     code: string,
 *     type: string,
 *     label: string,
 *     category: string,
 *     position: string,
 *     sort_order: int,
 *     option_keys: list<string>,
 *     option_labels: array<string, string>
 * }
 */
final class MarketingToolCatalog
{
    /**
     * @return list<CatalogEntry>
     */
    public function all(): array
    {
        return [
            [
                'code'          => 'gtm',
                'type'          => ToolType::Gtm->value,
                'label'         => 'Google Tag Manager',
                'category'      => 'analytics',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 10,
                'option_keys'   => ['container_id'],
                'option_labels' => ['container_id' => 'Container ID (GTM-…)'],
            ],
            [
                'code'          => 'gtm_noscript',
                'type'          => ToolType::Gtm->value,
                'label'         => 'Google Tag Manager (noscript)',
                'category'      => 'analytics',
                'position'      => ToolPosition::BodyStart->value,
                'sort_order'    => 11,
                'option_keys'   => ['container_id'],
                'option_labels' => ['container_id' => 'Container ID (GTM-…)'],
            ],
            [
                'code'          => 'ga4',
                'type'          => ToolType::Ga4->value,
                'label'         => 'Google Analytics 4',
                'category'      => 'analytics',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 20,
                'option_keys'   => ['measurement_id'],
                'option_labels' => ['measurement_id' => 'Measurement ID (G-…)'],
            ],
            [
                'code'          => 'meta_pixel',
                'type'          => ToolType::MetaPixel->value,
                'label'         => 'Meta Pixel',
                'category'      => 'marketing',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 30,
                'option_keys'   => ['pixel_id'],
                'option_labels' => ['pixel_id' => 'Pixel ID'],
            ],
            [
                'code'          => 'linkedin',
                'type'          => ToolType::LinkedIn->value,
                'label'         => 'LinkedIn Insight Tag',
                'category'      => 'marketing',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 40,
                'option_keys'   => ['partner_id'],
                'option_labels' => ['partner_id' => 'Partner ID'],
            ],
            [
                'code'          => 'tiktok',
                'type'          => ToolType::TikTok->value,
                'label'         => 'TikTok Pixel',
                'category'      => 'marketing',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 50,
                'option_keys'   => ['pixel_id'],
                'option_labels' => ['pixel_id' => 'Pixel ID'],
            ],
            [
                'code'          => 'hotjar',
                'type'          => ToolType::Hotjar->value,
                'label'         => 'Hotjar',
                'category'      => 'analytics',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 60,
                'option_keys'   => ['site_id'],
                'option_labels' => ['site_id' => 'Site ID'],
            ],
            [
                'code'          => 'clarity',
                'type'          => ToolType::Clarity->value,
                'label'         => 'Microsoft Clarity',
                'category'      => 'analytics',
                'position'      => ToolPosition::Head->value,
                'sort_order'    => 70,
                'option_keys'   => ['project_id'],
                'option_labels' => ['project_id' => 'Project ID'],
            ],
            [
                'code'          => 'custom',
                'type'          => ToolType::Custom->value,
                'label'         => 'Custom script',
                'category'      => 'marketing',
                'position'      => ToolPosition::BodyEnd->value,
                'sort_order'    => 100,
                'option_keys'   => ['html'],
                'option_labels' => ['html' => 'HTML / JS snippet'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function optionKeysForType(string $type): array
    {
        foreach ($this->all() as $entry) {
            if ($entry['type'] === $type) {
                return $entry['option_keys'];
            }
        }

        return ['html'];
    }

    /**
     * @return array<string, string>
     */
    public function optionLabelsForType(string $type): array
    {
        foreach ($this->all() as $entry) {
            if ($entry['type'] === $type) {
                return $entry['option_labels'];
            }
        }

        return ['html' => 'HTML / JS snippet'];
    }

    public function labelForCode(string $code): string
    {
        foreach ($this->all() as $entry) {
            if ($entry['code'] === $code) {
                return $entry['label'];
            }
        }

        return $code;
    }
}
