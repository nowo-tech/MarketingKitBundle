<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only admin action (seed, import YAML, toggle, delete) — REQ-TWIG-005.
 *
 * Created with an empty name so the posted fields keep their historical flat names:
 * `_token` and, with `with_profile`, a hidden `profile`. `id_suffix` keeps HTML ids unique when
 * several of these forms share one page.
 *
 * @extends AbstractType<array{profile?: string|null}|null>
 */
final class CsrfActionType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_profile'] === true) {
            $builder->add('profile', HiddenType::class);
        }
    }

    /**
     * @param FormView<mixed> $view
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $suffix = (string) $options['id_suffix'];
        if ($suffix === '') {
            return;
        }

        foreach ($view->children as $child) {
            $child->vars['id'] .= '_' . $suffix;
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'with_profile'    => false,
            'id_suffix'       => '',
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setAllowedTypes('with_profile', 'bool');
        $resolver->setAllowedTypes('id_suffix', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
