<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Entity;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validation;

final class MarketingToolTest extends TestCase
{
    public function testGettersSetters(): void
    {
        $tool = (new MarketingTool())
            ->setProfile('default')
            ->setCode('gtm')
            ->setType('gtm')
            ->setEnabled(true)
            ->setCategory('analytics')
            ->setPosition('head')
            ->setSortOrder(2)
            ->setOptions(['container_id' => 'GTM-1']);

        self::assertNull($tool->getId());
        self::assertSame('default', $tool->getProfile());
        self::assertSame('gtm', $tool->getCode());
        self::assertSame('gtm', $tool->getType());
        self::assertTrue($tool->isEnabled());
        self::assertSame('analytics', $tool->getCategory());
        self::assertSame('head', $tool->getPosition());
        self::assertSame(2, $tool->getSortOrder());
        self::assertSame(['container_id' => 'GTM-1'], $tool->getOptions());
    }

    public function testDeclaresUniqueEntityOnProfileAndCode(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $metadata  = $validator->getMetadataFor(MarketingTool::class);
        self::assertInstanceOf(ClassMetadata::class, $metadata);

        /** @var list<UniqueEntity> $unique */
        $unique = array_values(array_filter($metadata->getConstraints(), static fn (Constraint $c): bool => $c instanceof UniqueEntity));
        self::assertCount(1, $unique);
        self::assertSame(['profile', 'code'], $unique[0]->fields);
        self::assertSame('code', $unique[0]->errorPath);
    }

    public function testBlankOrTooLongProfileAndCodeAreInvalid(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $tool      = (new MarketingTool())->setProfile('')->setCode(str_repeat('x', 65));

        self::assertCount(1, $validator->validateProperty($tool, 'profile'));
        self::assertCount(1, $validator->validateProperty($tool, 'code'));

        $tool->setProfile('default')->setCode('gtm');
        self::assertCount(0, $validator->validateProperty($tool, 'profile'));
        self::assertCount(0, $validator->validateProperty($tool, 'code'));
    }
}
