<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_scalar;

/**
 * Admin form for marketing services with typed provider option fields.
 *
 * @extends AbstractType<MarketingTool>
 */
#[FormKitConfig('marketing_kit')]
final class MarketingToolType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(
        private readonly MarketingToolCatalog $catalog,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $profileChoices */
        $profileChoices = $options['profile_choices'];

        $typeChoices = [];
        foreach (ToolType::cases() as $case) {
            $typeChoices[$case->name . ' (' . $case->value . ')'] = $case->value;
        }

        $positionChoices = [];
        foreach (ToolPosition::cases() as $case) {
            $positionChoices[$case->value] = $case->value;
        }

        $this->addText($builder, 'profile', [
            'label' => 'Profile',
            'help'  => 'Usually one of: ' . implode(', ', $profileChoices),
        ]);
        $this->addText($builder, 'code', [
            'label' => 'Code',
            'help'  => 'Stable key within the profile (e.g. gtm, meta_pixel).',
        ]);
        $this->addChoice($builder, 'type', [
            'choices' => $typeChoices,
            'label'   => 'Provider',
        ]);
        $this->addCheckbox($builder, 'enabled', [
            'required' => false,
            'label'    => 'Enabled',
        ]);
        $this->addChoice($builder, 'category', [
            'label'   => 'Consent category',
            'choices' => [
                'analytics'   => 'analytics',
                'marketing'   => 'marketing',
                'preferences' => 'preferences',
                'required'    => 'required',
            ],
        ]);
        $this->addChoice($builder, 'position', [
            'choices' => $positionChoices,
            'label'   => 'Position',
        ]);
        $this->addInteger($builder, 'sortOrder', [
            'label' => 'Sort order',
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $tool        = $event->getData();
            $type        = $tool instanceof MarketingTool ? $tool->getType() : ToolType::Custom->value;
            $optionsData = $tool instanceof MarketingTool ? $tool->getOptions() : [];
            $this->addOptionFields($event->getForm(), $type, $optionsData);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            /** @var array<string, mixed> $data */
            $data = $event->getData() ?? [];
            $type = (string) ($data['type'] ?? ToolType::Custom->value);
            $this->addOptionFields($event->getForm(), $type, []);
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $tool = $event->getData();
            if (!$tool instanceof MarketingTool) {
                return;
            }

            $form       = $event->getForm();
            $optionsMap = [];
            foreach ($this->catalog->optionKeysForType($tool->getType()) as $key) {
                $field = 'option_' . $key;
                if ($form->has($field)) {
                    $value            = $form->get($field)->getData();
                    $optionsMap[$key] = is_scalar($value) || $value === null ? (string) $value : '';
                }
            }
            $tool->setOptions($optionsMap);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => MarketingTool::class,
            'profile_choices' => ['default'],
        ]);
        $resolver->setAllowedTypes('profile_choices', 'array');
    }

    /**
     * @param FormInterface<MarketingTool|null> $form
     * @param array<string, mixed> $optionsData
     */
    private function addOptionFields(FormInterface $form, string $type, array $optionsData): void
    {
        foreach (array_keys($form->all()) as $name) {
            if (str_starts_with((string) $name, 'option_')) {
                $form->remove((string) $name);
            }
        }

        $labels = $this->catalog->optionLabelsForType($type);
        foreach ($this->catalog->optionKeysForType($type) as $key) {
            $fieldType = $key === 'html' ? TextareaType::class : TextType::class;
            $value     = $optionsData[$key] ?? null;
            $form->add('option_' . $key, $fieldType, [
                'mapped'   => false,
                'required' => false,
                'label'    => $labels[$key] ?? $key,
                'data'     => is_scalar($value) ? (string) $value : '',
                'attr'     => $key === 'html' ? ['rows' => 8] : [],
            ]);
        }
    }
}
