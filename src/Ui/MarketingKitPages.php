<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Ui;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Error\LoaderError;

use function in_array;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function str_contains;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003) for the marketing admin: resolves the layout the scaffolds
 * extend (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; a 1.x layout without the UiKit canonical
 * blocks is wrapped by {@see self::LEGACY_LAYOUT_BRIDGE}), the per-page templates (`web_ui.templates.<page>`) and the
 * form themes, and builds the view models of the list and form pages. Configuration only: no request state, safe in
 * worker mode.
 */
final readonly class MarketingKitPages
{
    /** Bundle name of the view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoMarketingKitBundle`). */
    public const BUNDLE = 'NowoMarketingKitBundle';

    /** Translation domain of the labels. */
    public const DOMAIN = 'NowoMarketingKitBundle';

    /** BC bridge around a 1.x layout that renders `{% block body %}` (deprecated since 1.9, removed in 2.0). */
    public const LEGACY_LAYOUT_BRIDGE = '@NowoMarketingKitBundle/admin/_legacy_layout.html.twig';

    /** Former default of `web_ui.layout_template` (removed in 1.9): treated as null. */
    public const FORMER_DEFAULT_LAYOUT = '@NowoMarketingKitBundle/admin/layout.html.twig';

    /** Default page templates (`web_ui.templates.<page>`). */
    public const TEMPLATES = [
        'index' => '@NowoMarketingKitBundle/admin/index.html.twig',
        'form'  => '@NowoMarketingKitBundle/admin/form.html.twig',
    ];

    /** Default form theme of the tool form (`web_ui.form_themes`). */
    public const DEFAULT_FORM_THEME = '@NowoUiKit/form/nowo_ui_layout.html.twig';

    /** Options longer than this are shortened in the list. */
    private const OPTION_PREVIEW_LENGTH = 40;

    /**
     * @param bool $enabled `web_ui.enabled`
     * @param string|null $layoutTemplate `web_ui.layout_template`
     * @param array<string, string> $templates `web_ui.templates`
     * @param list<string> $formThemes `web_ui.form_themes`
     * @param Environment|null $twig Twig (detects a 1.x layout)
     */
    public function __construct(
        private bool $enabled = true,
        private ?string $layoutTemplate = null,
        private array $templates = [],
        private array $formThemes = [self::DEFAULT_FORM_THEME],
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, float|int|string|null> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? '';

        return $override !== '' ? $override : (self::TEMPLATES[$page] ?? $page);
    }

    /**
     * @return list<string>
     */
    public function formThemes(): array
    {
        return $this->formThemes;
    }

    /**
     * Layout the scaffolds extend (`page.layout`): `web_ui.layout_template` when it renders the UiKit canonical
     * blocks, the legacy bridge for a 1.x layout, else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        return $this->legacyLayout() !== null ? self::LEGACY_LAYOUT_BRIDGE : $this->configuredLayout();
    }

    /**
     * The 1.x layout configured in `web_ui.layout_template` (one that renders `{% block body %}` and not the UiKit
     * `nowo_ui_content` block), wrapped by {@see self::LEGACY_LAYOUT_BRIDGE}; null when there is none.
     */
    public function legacyLayout(): ?string
    {
        $layout = $this->configuredLayout();
        if ($layout === null || !$this->twig instanceof Environment) {
            return null;
        }

        try {
            $source = $this->twig->getLoader()->getSourceContext($layout)->getCode();
        } catch (LoaderError) {
            return null;
        }

        if (str_contains($source, 'nowo_ui_content') || str_contains($source, '@NowoUiKit')) {
            return null;
        }

        trigger_deprecation('nowo-tech/marketing-kit-bundle', '1.9', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_content); it is wrapped in "%s". Render the canonical blocks in your layout (or point nowo_marketing_kit.web_ui.layout_template to a one-file bridge); see docs/UPGRADING.md.', $layout, self::LEGACY_LAYOUT_BRIDGE);

        return $layout;
    }

    /**
     * Admin list of one profile (UiKit list scaffold).
     *
     * @param list<MarketingTool> $tools Tools of the profile, in render order
     * @param FormView $filters GET profile switcher (ProfileFilterType)
     * @param FormView $seedForm CSRF-only form of the "Seed catalog" action
     * @param FormView $importForm CSRF-only form of the "Import from YAML" action
     * @param array<int, FormView> $toggleForms CSRF-only forms of the toggle row action, by tool id
     * @param array<int, FormView> $deleteForms CSRF-only forms of the delete row action, by tool id
     */
    public function listPage(
        array $tools,
        string $profile,
        MarketingToolCatalog $catalog,
        FormView $filters,
        FormView $seedForm,
        FormView $importForm,
        array $toggleForms,
        array $deleteForms,
        UrlGeneratorInterface $urls,
    ): ListPage {
        $rows       = [];
        $rowActions = [];
        foreach ($tools as $tool) {
            $id      = (int) $tool->getId();
            $name    = $catalog->labelForCode($tool->getCode());
            $options = [];
            foreach ($tool->getOptions() as $key => $value) {
                $text          = is_scalar($value) ? (string) $value : '';
                $options[$key] = mb_strlen($text) > self::OPTION_PREVIEW_LENGTH ? mb_substr($text, 0, self::OPTION_PREVIEW_LENGTH) . '…' : $text;
            }
            $rows[$id] = [
                'id'       => $id,
                'name'     => $name,
                'code'     => $tool->getCode(),
                'type'     => $tool->getType(),
                'category' => $tool->getCategory(),
                'position' => $tool->getPosition(),
                'enabled'  => $tool->isEnabled(),
                'options'  => $options,
            ];
            $rowActions[$id] = [
                new Action('edit', self::label('admin.edit'), $urls->generate('nowo_marketing_kit_admin_edit', ['id' => $id]), attributes: ['data-testid' => 'marketing-kit-edit-' . $tool->getCode()]),
                new Action(
                    'custom',
                    self::label($tool->isEnabled() ? 'admin.disable' : 'admin.enable'),
                    $urls->generate('nowo_marketing_kit_admin_toggle', ['id' => $id]),
                    method: 'POST',
                    icon: $tool->isEnabled() ? 'toggle-on' : 'toggle-off',
                    variant: 'secondary',
                    attributes: ['data-testid' => 'marketing-kit-toggle-' . $tool->getCode()],
                    form: $toggleForms[$id] ?? null,
                ),
                new Action(
                    'delete',
                    self::label('admin.delete'),
                    $urls->generate('nowo_marketing_kit_admin_delete', ['id' => $id]),
                    method: 'POST',
                    confirm: new Confirm(
                        self::label('admin.confirm_delete_title', ['%name%' => $name]),
                        self::label('admin.confirm_delete'),
                        self::label('admin.delete'),
                    ),
                    variant: 'danger',
                    attributes: ['data-testid' => 'marketing-kit-delete-' . $tool->getCode()],
                    form: $deleteForms[$id] ?? null,
                ),
            ];
        }

        $create = new Action('create', self::label('admin.new'), $urls->generate('nowo_marketing_kit_admin_new', ['profile' => $profile]), variant: 'primary', attributes: ['data-testid' => 'marketing-kit-new']);

        return new ListPage(
            new PageHeader(
                self::label('admin.title'),
                self::label('admin.lead'),
                [
                    $create,
                    new Action('custom', self::label('admin.seed'), $urls->generate('nowo_marketing_kit_admin_seed'), method: 'POST', icon: 'database-add', variant: 'secondary', attributes: ['data-testid' => 'marketing-kit-seed'], form: $seedForm),
                    new Action('import', self::label('admin.import_yaml'), $urls->generate('nowo_marketing_kit_admin_import_yaml'), method: 'POST', variant: 'secondary', attributes: ['data-testid' => 'marketing-kit-import'], form: $importForm),
                ],
            ),
            [
                new Column('name', self::label('admin.col.service'), required: true),
                new Column('type', self::label('admin.col.type')),
                new Column('category', self::label('admin.col.category'), priority: 2),
                new Column('position', self::label('admin.col.position'), priority: 2),
                new Column('enabled', self::label('admin.col.enabled')),
                new Column('options', self::label('admin.col.options'), priority: 3),
            ],
            $rows,
            filters: $filters,
            rowActions: static fn (array $row): array => $rowActions[$row['id']] ?? [],
            empty: new EmptyState(self::label('admin.empty_title'), self::label('admin.empty')),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'marketing-kit-admin-index'],
            actionsLabel: self::label('admin.col.actions'),
        );
    }

    /**
     * Create / edit form of one tool (UiKit form scaffold).
     */
    public function formPage(MarketingTool $tool, bool $isNew, FormView $form, MarketingToolCatalog $catalog, UrlGeneratorInterface $urls): FormPage
    {
        $list = $urls->generate('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);

        return new FormPage(
            new PageHeader(
                self::label($isNew ? 'admin.new' : 'admin.edit'),
                self::label('admin.form_intro', ['%name%' => $catalog->labelForCode($tool->getCode()), '%type%' => $tool->getType()]),
                back: new BackLink($list, self::label('admin.back')),
            ),
            $form,
            submitLabel: self::label('admin.save'),
            cancelHref: $list,
            cancelLabel: self::label('admin.cancel'),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'marketing-kit-admin-form'],
            formThemes: $this->formThemes,
        );
    }

    private function configuredLayout(): ?string
    {
        $layout = $this->layoutTemplate;
        if (in_array($layout, [null, '', self::FORMER_DEFAULT_LAYOUT], true)) {
            return null;
        }

        return $layout;
    }
}
