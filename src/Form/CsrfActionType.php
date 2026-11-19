<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
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
 * `_token` and, with `with_profile`, a hidden `profile`; since 1.13, with `with_version`, a hidden unmapped `version`
 * (the row version the toggle was rendered for, filled from the `version` option). `id_suffix` keeps HTML ids unique when
 * several of these forms share one page. FormKit Type bound to the `marketing_kit` profile (REQ-TWIG-005 rule 11);
 * the hidden children are added without profile defaults.
 *
 * @extends AbstractType<array{profile?: string|null}|null>
 */
#[FormKitConfig('marketing_kit')]
final class CsrfActionType extends AbstractType
{
    use FormOptionsTrait;

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
        if ($options['with_version'] === true) {
            // 1.13 (REQ-RUNTIME-003 rule 4): the version of the row the action was rendered for (optimistic lock). Not
            // mapped: the controller compares it, the client never sets the stored version.
            $builder->add('version', HiddenType::class, [
                'mapped' => false,
                'data'   => $options['version'] === null ? null : (string) $options['version'],
            ]);
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
            'with_version'    => false,
            'version'         => null,
            'id_suffix'       => '',
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setAllowedTypes('with_profile', 'bool');
        $resolver->setAllowedTypes('with_version', 'bool');
        $resolver->setAllowedTypes('version', ['null', 'int']);
        $resolver->setAllowedTypes('id_suffix', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
