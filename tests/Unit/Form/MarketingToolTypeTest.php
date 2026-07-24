<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Form;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
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
        return [new MarketingToolType(new MarketingToolCatalog())];
    }
}
