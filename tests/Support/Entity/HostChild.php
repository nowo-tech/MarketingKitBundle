<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A child of {@see HostParent} (the owning side of its orphan-removal collection, audit XD-5).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_child')]
class HostChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HostParent::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true)]
    public ?HostParent $parent = null;
}
