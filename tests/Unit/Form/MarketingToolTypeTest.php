<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Form;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Tests\Support\FormKitTestSupport;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Test\TypeTestCase;

final class MarketingToolTypeTest extends TypeTestCase
{
    public function testSubmitIgnoresNonToolData(): void
    {
        $form = $this->factory->create(MarketingToolType::class, null, [
            'profile_choices' => ['default'],
            'data_class'      => null,
        ]);
        $form->submit([
            'profile'     => 'default',
            'code'        => 'x',
            'type'        => 'custom',
            'enabled'     => '1',
            'category'    => 'marketing',
            'position'    => 'head',
            'sortOrder'   => '1',
            'option_html' => '<b></b>',
        ]);
        self::assertTrue($form->isSynchronized());
        /** @var mixed $data */
        $data = $form->getData();
        self::assertIsArray($data);
    }

    public function testSubmitMapsProviderOptions(): void
    {
        $tool = (new MarketingTool())
            ->setProfile('default')
            ->setCode('gtm')
            ->setType('gtm')
            ->setCategory('analytics')
            ->setPosition('head')
            ->setSortOrder(1)
            ->setOptions(['container_id' => '']);

        $form = $this->factory->create(MarketingToolType::class, $tool, [
            'profile_choices' => ['default'],
        ]);

        $form->submit([
            'profile'             => 'default',
            'code'                => 'gtm',
            'type'                => 'gtm',
            'enabled'             => '1',
            'category'            => 'analytics',
            'position'            => 'head',
            'sortOrder'           => '10',
            'option_container_id' => 'GTM-XYZ',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($tool->isEnabled());
        self::assertSame(10, $tool->getSortOrder());
        self::assertSame(['container_id' => 'GTM-XYZ'], $tool->getOptions());
    }

    public function testSubmitRejectsInjectedProviderId(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('ga4')->setType('ga4')->setOptions(['measurement_id' => '']);
        $form = $this->factory->create(MarketingToolType::class, $tool, [
            'profile_choices' => ['default'],
        ]);

        $form->submit([
            'profile'               => 'default',
            'code'                  => 'ga4',
            'type'                  => 'ga4',
            'category'              => 'analytics',
            'position'              => 'head',
            'sortOrder'             => '1',
            'option_measurement_id' => "G-1');alert(document.cookie);//",
        ]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('option_measurement_id')->getErrors());
    }

    public function testSubmitTrimsValidProviderId(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('meta')->setType('meta_pixel');
        $form = $this->factory->create(MarketingToolType::class, $tool, [
            'profile_choices' => ['default'],
        ]);

        $form->submit([
            'profile'         => 'default',
            'code'            => 'meta',
            'type'            => 'meta_pixel',
            'category'        => 'marketing',
            'position'        => 'head',
            'sortOrder'       => '1',
            'option_pixel_id' => ' 123456 ',
        ]);

        self::assertTrue($form->isValid());
        self::assertSame(['pixel_id' => '123456'], $tool->getOptions());
    }

    public function testCustomTypeShowsHtmlField(): void
    {
        $tool = (new MarketingTool())->setType('custom')->setOptions(['html' => '<script></script>']);
        $form = $this->factory->create(MarketingToolType::class, $tool, [
            'profile_choices' => ['default'],
        ]);

        self::assertTrue($form->has('option_html'));
        self::assertFalse($form->has('option_container_id'));
    }

    /**
     * @return list<FormTypeInterface<mixed>>
     */
    protected function getTypes(): array
    {
        return [
            FormKitTestSupport::withMerger(new MarketingToolType(new MarketingToolCatalog())),
        ];
    }
}
