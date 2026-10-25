<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Form;

use Nowo\MarketingKitBundle\Form\ProfileFilterType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class ProfileFilterTypeTest extends TestCase
{
    public function testRendersFlatGetSelect(): void
    {
        $form = Forms::createFormFactory()->createNamed('', ProfileFilterType::class, ['profile' => 'shop'], ['profiles' => ['default', 'shop']]);
        $view = $form->createView();

        self::assertSame('', (new ProfileFilterType())->getBlockPrefix());
        self::assertSame('GET', $view->vars['method']);
        self::assertSame('profile', $view->children['profile']->vars['full_name']);
        self::assertSame('profile', $view->children['profile']->vars['id']);
        self::assertSame('shop', $view->children['profile']->vars['value']);
        self::assertSame('this.form.submit()', $view->children['profile']->vars['attr']['onchange']);
        self::assertCount(2, $view->children['profile']->vars['choices']);
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
    }
}
