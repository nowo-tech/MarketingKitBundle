<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use SortDirection;

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
            ->orderBy('t.sortOrder', SortDirection::Ascending)
            ->addOrderBy('t.code', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $tools;
    }

    /**
     * Scalar rows for a profile, ordered like {@see findByProfileOrdered()}.
     *
     * Array hydration bypasses the identity map, so rows reflect the database even when a long-running
     * worker still holds managed `MarketingTool` instances loaded in an earlier request.
     *
     * @return list<array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>}>
     */
    public function findToolRowsByProfile(string $profile): array
    {
        /** @var list<array{code: string, type: string, enabled: bool, category: string, position: string, sortOrder: int, options: array<string, mixed>}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.code', 't.type', 't.enabled', 't.category', 't.position', 't.sortOrder', 't.options')
            ->andWhere('t.profile = :profile')
            ->setParameter('profile', $profile)
            ->orderBy('t.sortOrder', SortDirection::Ascending)
            ->addOrderBy('t.code', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
