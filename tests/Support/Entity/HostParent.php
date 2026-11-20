<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A host aggregate with orphan removal on its inverse sides (audit XD-5): clearing the children or unsetting the
 * profile schedules orphan removals only (no entity deletion, no collection change), which a marketing tool write unit
 * must still refuse to carry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_parent')]
class HostParent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, HostChild> */
    #[ORM\OneToMany(targetEntity: HostChild::class, mappedBy: 'parent', cascade: ['persist'], orphanRemoval: true)]
    public Collection $children;

    #[ORM\OneToOne(targetEntity: HostProfile::class, mappedBy: 'parent', cascade: ['persist'], orphanRemoval: true)]
    public ?HostProfile $profile = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function addChild(HostChild $child): void
    {
        $child->parent = $this;
        $this->children->add($child);
    }

    public function setProfile(HostProfile $profile): void
    {
        $profile->parent = $this;
        $this->profile   = $profile;
    }
}
