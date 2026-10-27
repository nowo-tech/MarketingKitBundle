<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET profile switcher of the admin list (`?profile=…`, no CSRF) — REQ-TWIG-005. Rendered in the filters region of
 * the UiKit list scaffold (apply button; no inline handler, REQ-UI-003).
 *
 * @extends AbstractType<array{profile?: string|null}>
 */
final class ProfileFilterType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $profiles */
        $profiles = $options['profiles'];

        $builder->add('profile', ChoiceType::class, [
            'label'                     => 'admin.profile',
            'choices'                   => array_combine($profiles, $profiles),
            'choice_translation_domain' => false,
        ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'NowoMarketingKitBundle',
            'profiles'           => [],
        ]);
        $resolver->setAllowedTypes('profiles', 'string[]');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
