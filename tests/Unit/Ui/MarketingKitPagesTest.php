<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Ui;

use Nowo\MarketingKitBundle\Tests\Support\AdminPageFixtures;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function array_map;

final class MarketingKitPagesTest extends TestCase
{
    public function testDefaults(): void
    {
        $pages = new MarketingKitPages();

        self::assertTrue($pages->isEnabled());
        self::assertNull($pages->layout());
        self::assertNull($pages->legacyLayout());
        self::assertSame('@NowoMarketingKitBundle/admin/index.html.twig', $pages->template('index'));
        self::assertSame('@NowoMarketingKitBundle/admin/form.html.twig', $pages->template('form'));
        self::assertSame('unknown.html.twig', $pages->template('unknown.html.twig'));
        self::assertSame([MarketingKitPages::DEFAULT_FORM_THEME], $pages->formThemes());
    }

    public function testConfiguredTemplatesAndFormThemes(): void
    {
        $pages = new MarketingKitPages(false, null, ['index' => 'admin/marketing_index.html.twig', 'form' => ''], []);

        self::assertFalse($pages->isEnabled());
        self::assertSame('admin/marketing_index.html.twig', $pages->template('index'));
        self::assertSame('@NowoMarketingKitBundle/admin/form.html.twig', $pages->template('form'));
        self::assertSame([], $pages->formThemes());
    }

    public function testFormerDefaultLayoutMeansUiKitLayout(): void
    {
        self::assertNull((new MarketingKitPages(true, MarketingKitPages::FORMER_DEFAULT_LAYOUT, [], [], $this->twig()))->layout());
        self::assertNull((new MarketingKitPages(true, '', [], [], $this->twig()))->layout());
    }

    public function testLayoutWithCanonicalBlocksIsUsedAsIs(): void
    {
        self::assertSame('layouts/admin.html.twig', (new MarketingKitPages(true, 'layouts/admin.html.twig', [], [], $this->twig()))->layout());
        self::assertSame('layouts/bridge.html.twig', (new MarketingKitPages(true, 'layouts/bridge.html.twig', [], [], $this->twig()))->layout());
    }

    public function testLayoutWithoutTwigOrUnknownTemplateIsUsedAsIs(): void
    {
        self::assertSame('base.html.twig', (new MarketingKitPages(true, 'base.html.twig'))->layout());
        self::assertSame('missing.html.twig', (new MarketingKitPages(true, 'missing.html.twig', [], [], $this->twig()))->layout());
    }

    #[IgnoreDeprecations]
    public function testOneDotXLayoutIsWrappedInTheLegacyBridge(): void
    {
        $pages = new MarketingKitPages(true, 'base.html.twig', [], [], $this->twig());

        $this->expectUserDeprecationMessageMatches('/The layout "base\.html\.twig" does not render the UiKit canonical blocks/');
        self::assertSame(MarketingKitPages::LEGACY_LAYOUT_BRIDGE, $pages->layout());
    }

    public function testListPage(): void
    {
        $context = AdminPageFixtures::index();
        $page    = $context['page'];
        self::assertInstanceOf(ListPage::class, $page);

        self::assertSame(MarketingKitPages::BUNDLE, $page->bundle);
        self::assertNull($page->layout);
        self::assertSame(['create', 'custom', 'import'], array_map(static fn (Action $a): string => $a->kind, $page->header->actions));
        self::assertSame('/admin/marketing/new?profile=default', $page->header->actions[0]->href);
        self::assertSame('POST', $page->header->actions[1]->method);
        self::assertSame($context['seed_form'], $page->header->actions[1]->form);
        self::assertSame($context['import_form'], $page->header->actions[2]->form);
        self::assertSame(['name', 'type', 'category', 'position', 'enabled', 'options'], array_map(static fn (Column $c): string => $c->key, $page->columns));
        self::assertSame($context['profile_form'], $page->filters);

        self::assertSame(['id' => 1, 'name' => 'Google Tag Manager', 'code' => 'gtm', 'type' => 'gtm', 'category' => 'analytics', 'position' => 'head', 'enabled' => true, 'options' => ['container_id' => 'GTM-DEMO123']], $page->rows[1]);
        self::assertSame('<script>window.demo = "a long custom sni…', $page->rows[3]['options']['html']);

        $actions = $page->rowActions($page->rows[1], 1);
        self::assertSame(['edit', 'custom', 'delete'], array_map(static fn (Action $a): string => $a->kind, $actions));
        self::assertSame('/admin/marketing/1/edit', $actions[0]->href);
        self::assertInstanceOf(Label::class, $actions[1]->label);
        self::assertSame('admin.disable', $actions[1]->label->key);
        self::assertSame($context['toggle_forms'][1], $actions[1]->form);
        self::assertSame('/admin/marketing/1/delete', $actions[2]->href);
        self::assertNotNull($actions[2]->confirm);
        self::assertSame($context['delete_forms'][1], $actions[2]->form);

        $disabled = $page->rowActions($page->rows[2], 2);
        self::assertInstanceOf(Label::class, $disabled[1]->label);
        self::assertSame('admin.enable', $disabled[1]->label->key);
        self::assertSame([], $page->rowActions(['id' => 99], 99));
    }

    public function testListPageCarriesTheResolvedLayout(): void
    {
        $page = AdminPageFixtures::index([], true, new MarketingKitPages(true, 'layouts/admin.html.twig', [], [], $this->twig()))['page'];
        self::assertInstanceOf(ListPage::class, $page);

        self::assertSame('layouts/admin.html.twig', $page->layout);
        self::assertSame([], $page->rows);
    }

    public function testFormPage(): void
    {
        $edit = AdminPageFixtures::form()['page'];
        self::assertInstanceOf(FormPage::class, $edit);
        self::assertInstanceOf(Label::class, $edit->header->title);
        self::assertSame('admin.edit', $edit->header->title->key);
        self::assertInstanceOf(Label::class, $edit->header->intro);
        self::assertSame(['%name%' => 'Google Tag Manager', '%type%' => 'gtm'], $edit->header->intro->params);
        self::assertSame('/admin/marketing?profile=default', $edit->cancelHref);
        self::assertSame('/admin/marketing?profile=default', $edit->header->back?->href);
        self::assertSame([MarketingKitPages::DEFAULT_FORM_THEME], $edit->formThemes);

        $new = AdminPageFixtures::form(true)['page'];
        self::assertInstanceOf(FormPage::class, $new);
        self::assertInstanceOf(Label::class, $new->header->title);
        self::assertSame('admin.new', $new->header->title->key);
    }

    public function testLabel(): void
    {
        $label = MarketingKitPages::label('admin.title', ['%x%' => 1]);

        self::assertSame('admin.title', $label->key);
        self::assertSame('NowoMarketingKitBundle', $label->domain);
        self::assertSame(['%x%' => 1], $label->params);
    }

    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            'base.html.twig'           => '<html><body>{% block body %}{% endblock %}</body></html>',
            'layouts/admin.html.twig'  => '{% extends "@NowoUiKit/layout/admin.html.twig" %}',
            'layouts/bridge.html.twig' => '{% extends "base.html.twig" %}{% block body %}{% block nowo_ui_content %}{% endblock %}{% endblock %}',
        ]));
    }
}
