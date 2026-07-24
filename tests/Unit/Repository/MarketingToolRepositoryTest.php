<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use PHPUnit\Framework\TestCase;

final class MarketingToolRepositoryTest extends TestCase
{
    public function testFindByProfileOrderedBuildsQuery(): void
    {
        $tool = (new MarketingTool())->setCode('gtm');

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([$tool]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->with('t.profile = :profile')->willReturnSelf();
        $qb->method('setParameter')->with('profile', 'default')->willReturnSelf();
        $qb->method('orderBy')->with('t.sortOrder', 'ASC')->willReturnSelf();
        $qb->method('addOrderBy')->with('t.code', 'ASC')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(MarketingTool::class));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        $repo = new class($registry, $qb) extends MarketingToolRepository {
            public function __construct(ManagerRegistry $registry, private readonly QueryBuilder $qb)
            {
                parent::__construct($registry);
            }

            public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
            {
                return $this->qb;
            }
        };

        self::assertSame([$tool], $repo->findByProfileOrdered('default'));
    }
}
