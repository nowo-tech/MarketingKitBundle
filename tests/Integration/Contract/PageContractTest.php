<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Integration\Contract;

use Nowo\MarketingKitBundle\Tests\Support\AdminPageFixtures;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

use function dirname;

/**
 * REQ-UI-003: every admin page extends a UiKit scaffold, fills the canonical blocks, has no <html>, CDN asset,
 * inline handler or confirm(), prints framework classes only through theme slots, reflects the stub theme, has no
 * raw translation key and keeps the CSRF field of every action form (UiKit PageContractTestCase).
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'index' => new PageFixture(MarketingKitPages::TEMPLATES['index'], AdminPageFixtures::index());
        yield 'index (empty, YAML config)' => new PageFixture(MarketingKitPages::TEMPLATES['index'], AdminPageFixtures::index([], false));
        yield 'form (edit)' => new PageFixture(MarketingKitPages::TEMPLATES['form'], AdminPageFixtures::form());
        yield 'form (new, YAML config)' => new PageFixture(MarketingKitPages::TEMPLATES['form'], AdminPageFixtures::form(true, false));
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 3) . '/src/Resources/views';

        return ['NowoMarketingKitBundle' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function formThemes(): array
    {
        return [MarketingKitPages::DEFAULT_FORM_THEME];
    }
}
