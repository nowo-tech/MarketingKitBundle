<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

use function call_user_func;
use function dirname;
use function method_exists;

/**
 * Injects a minimal FormOptionsMerger (profile {@code marketing_kit}) into Form Kit form types under test.
 */
final class FormKitTestSupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = [
            'translation_domain'              => 'NowoMarketingKitBundle',
            'convention_requires_translation' => true,
            'defaults'                        => [
                'attr'     => [],
                'row_attr' => [],
            ],
            'field_types' => [],
        ];

        return new FormOptionsMerger(
            [
                'marketing_kit' => $profile,
                'default'       => $profile,
            ],
            'marketing_kit',
            new ConstraintDefinitionFactory(),
            null,
            null,
            self::translator(),
        );
    }

    /**
     * English bundle catalogue: the convention help / placeholder keys apply only when translated, as in the seeded
     * profile (convention_requires_translation).
     */
    public static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/src/Resources/translations/NowoMarketingKitBundle.en.yaml', 'en', 'NowoMarketingKitBundle');

        return $translator;
    }

    /**
     * @template T of object
     *
     * @param T $formType
     *
     * @return T
     */
    public static function withMerger(object $formType): object
    {
        if (!method_exists($formType, 'setFormOptionsMerger')) {
            return $formType;
        }

        call_user_func([$formType, 'setFormOptionsMerger'], self::merger());

        return $formType;
    }
}
