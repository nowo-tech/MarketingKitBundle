<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;

/**
 * @extends ServiceEntityRepository<MarketingTool>
 */
class MarketingToolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketingTool::class);
    }

    /**
     * @return list<MarketingTool>
     */
    public function findByProfileOrdered(string $profile): array
    {
        /** @var list<MarketingTool> $tools */
        $tools = $this->createQueryBuilder('t')
            ->andWhere('t.profile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $tools;
    }
}
