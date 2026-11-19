<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Form;

use Nowo\MarketingKitBundle\Form\CsrfActionType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class CsrfActionTypeTest extends TestCase
{
    public function testWithoutProfileHasNoFields(): void
    {
        $view = Forms::createFormFactory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'toggle_marketing_tool_1'])->createView();

        self::assertSame([], $view->children);
        self::assertSame('POST', $view->vars['method']);
        self::assertSame('', (new CsrfActionType())->getBlockPrefix());
    }

    public function testProfileFieldKeepsFlatNameAndSuffixedId(): void
    {
        $form = Forms::createFormFactory()->createNamed('', CsrfActionType::class, ['profile' => 'default'], [
            'csrf_token_id' => 'seed_marketing_tools_default',
            'with_profile'  => true,
            'id_suffix'     => 'seed',
        ]);
        $view = $form->createView();

        self::assertSame('profile', $view->children['profile']->vars['full_name']);
        self::assertSame('profile_seed', $view->children['profile']->vars['id']);
        self::assertSame('default', $view->children['profile']->vars['value']);
        self::assertSame('_token', $form->getConfig()->getOption('csrf_field_name'));
    }

    /**
     * 1.13 (REQ-RUNTIME-003 rule 4): the toggle carries the row version it was rendered for, flat-named and unmapped.
     */
    public function testVersionFieldCarriesTheRenderedVersionUnmapped(): void
    {
        $factory = Forms::createFormFactory();
        $view    = $factory->createNamed('', CsrfActionType::class, null, [
            'csrf_token_id' => 'toggle_marketing_tool_1',
            'with_version'  => true,
            'version'       => 4,
        ])->createView();

        self::assertSame('version', $view->children['version']->vars['full_name']);
        self::assertSame('4', $view->children['version']->vars['value']);

        $submitted = $factory->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'toggle_marketing_tool_1', 'with_version' => true]);
        $submitted->submit(['version' => '3']);
        self::assertSame('3', $submitted->get('version')->getData());
        self::assertSame([], $submitted->getData(), 'not mapped into the data');
    }
}
