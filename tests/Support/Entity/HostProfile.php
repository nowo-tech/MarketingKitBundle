<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The profile of a {@see HostParent} (the owning side of its orphan-removal one-to-one, audit XD-5).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_profile')]
class HostProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: HostParent::class, inversedBy: 'profile')]
    #[ORM\JoinColumn(nullable: true)]
    public ?HostParent $parent = null;
}
