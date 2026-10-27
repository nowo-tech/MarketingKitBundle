<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Form;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

/**
 * SEC-004 hardening of the tool form: explicit CSRF id (I6), custom HTML refused while disabled (I7), no consent bypass
 * for built-in trackers (L2), profile restricted to the configured ones.
 */
final class MarketingToolTypeHardeningTest extends TestCase
{
    public function testCsrfIsOnWithAnExplicitTokenId(): void
    {
        $config = $this->factory()->create(MarketingToolType::class, new MarketingTool(), ['profile_choices' => ['default']])->getConfig();

        self::assertTrue($config->getOption('csrf_protection'));
        self::assertSame(MarketingToolType::CSRF_TOKEN_ID, $config->getOption('csrf_token_id'));
    }

    public function testCustomHtmlIsRefusedWhileDisabled(): void
    {
        $form = $this->submit(new MarketingToolType(new MarketingToolCatalog()), 'custom', 'marketing', ['option_html' => '<script>x()</script>']);

        self::assertFalse($form['valid']);
        self::assertSame(['option_html'], $form['errors']);
    }

    public function testCustomHtmlIsAcceptedWhenEnabledOrEmpty(): void
    {
        self::assertTrue($this->submit(new MarketingToolType(new MarketingToolCatalog(), true), 'custom', 'marketing', ['option_html' => '<script>x()</script>'])['valid']);
        self::assertTrue($this->submit(new MarketingToolType(new MarketingToolCatalog()), 'custom', 'marketing', ['option_html' => '  '])['valid']);
    }

    public function testRequiredCategoryIsRefusedForBuiltInTrackers(): void
    {
        $form = $this->submit(new MarketingToolType(new MarketingToolCatalog()), 'gtm', 'required', ['option_container_id' => 'GTM-A1']);

        self::assertFalse($form['valid']);
        self::assertSame(['category'], $form['errors']);
    }

    public function testRequiredCategoryIsAllowedForCustomOrWithTheOptIn(): void
    {
        self::assertTrue($this->submit(new MarketingToolType(new MarketingToolCatalog(), true), 'custom', 'required', ['option_html' => '<b></b>'])['valid']);
        self::assertTrue($this->submit(new MarketingToolType(new MarketingToolCatalog(), false, true), 'gtm', 'required', ['option_container_id' => 'GTM-A1'])['valid']);
    }

    public function testUnknownProfileIsRefused(): void
    {
        $form = $this->submit(new MarketingToolType(new MarketingToolCatalog()), 'gtm', 'analytics', ['option_container_id' => 'GTM-A1'], 'evil');

        self::assertFalse($form['valid']);
        self::assertSame(['profile'], $form['errors']);
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{valid: bool, errors: list<string>}
     */
    private function submit(MarketingToolType $type, string $toolType, string $category, array $options, string $profile = 'default'): array
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('x')->setType($toolType);
        $form = $this->factory($type)->create(MarketingToolType::class, $tool, ['profile_choices' => ['default'], 'csrf_protection' => false]);
        $form->submit(['profile' => $profile, 'code' => 'x', 'type' => $toolType, 'enabled' => '1', 'category' => $category, 'position' => 'head', 'sortOrder' => '1'] + $options);

        $errors = [];
        foreach ($form->all() as $name => $child) {
            if ($child->getErrors()->count() > 0) {
                $errors[] = (string) $name;
            }
        }

        return ['valid' => $form->isValid(), 'errors' => $errors];
    }

    private function factory(?MarketingToolType $type = null): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addType(FormKitTestSupport::withMerger($type ?? new MarketingToolType(new MarketingToolCatalog())))
            ->getFormFactory();
    }
}
