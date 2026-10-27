<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Enum\ToolCategory;
use Nowo\MarketingKitBundle\Enum\ToolPosition;
use Nowo\MarketingKitBundle\Enum\ToolType;
use Nowo\MarketingKitBundle\Provider\ToolIdValidator;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_combine;
use function in_array;
use function is_scalar;
use function trim;

/**
 * Admin form for marketing services with typed provider option fields.
 *
 * @extends AbstractType<MarketingTool>
 */
#[FormKitConfig('marketing_kit')]
final class MarketingToolType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id of the tool form (SEC-004 I6). */
    public const CSRF_TOKEN_ID = 'nowo_marketing_kit_tool';

    /**
     * @param bool $allowCustomHtml security.allow_custom_html: custom snippets can only be saved when true (SEC-004 I7)
     * @param bool $allowRequiredTrackers security.allow_required_trackers: built-in trackers in the `required` category (SEC-004 L2)
     */
    public function __construct(
        private readonly MarketingToolCatalog $catalog,
        private readonly bool $allowCustomHtml = false,
        private readonly bool $allowRequiredTrackers = false,
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
            'choices' => array_combine(ToolCategory::values(), ToolCategory::values()),
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

            $form = $event->getForm();
            if (!in_array($tool->getProfile(), $this->profileChoices($form), true)) {
                $form->get('profile')->addError(new FormError('Unknown profile.'));
            }
            if ($tool->getCategory() === ToolCategory::Required->value && !ToolCategory::allowsRequired($tool->getType(), $this->allowRequiredTrackers)) {
                $form->get('category')->addError(new FormError('Built-in trackers cannot use the "required" category (it bypasses consent); enable security.allow_required_trackers to allow it.'));
            }

            $optionsMap = [];
            foreach ($this->catalog->optionKeysForType($tool->getType()) as $key) {
                $field = 'option_' . $key;
                if ($form->has($field)) {
                    $value            = $form->get($field)->getData();
                    $optionsMap[$key] = is_scalar($value) || $value === null ? (string) $value : '';
                    if (ToolIdValidator::optionKeyFor($tool->getType()) === $key) {
                        if (ToolIdValidator::isValidOrEmpty($tool->getType(), $optionsMap[$key])) {
                            $optionsMap[$key] = trim($optionsMap[$key]);
                        } else {
                            $form->get($field)->addError(new FormError('This ID does not match the provider format.'));
                        }
                    }
                }
            }
            if (
                $tool->getType() === ToolType::Custom->value
                && !$this->allowCustomHtml
                && trim($optionsMap['html'] ?? '') !== ''
                && $form->has('option_html')
            ) {
                $form->get('option_html')->addError(new FormError('Custom HTML is disabled (security.allow_custom_html: false); it would never be rendered.'));
            }
            $tool->setOptions($optionsMap);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => MarketingTool::class,
            'profile_choices' => ['default'],
            'csrf_protection' => true,
            'csrf_token_id'   => self::CSRF_TOKEN_ID,
        ]);
        $resolver->setAllowedTypes('profile_choices', 'array');
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private function profileChoices(FormInterface $form): array
    {
        /** @var list<string> $choices */
        $choices = $form->getConfig()->getOption('profile_choices');

        return $choices;
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
