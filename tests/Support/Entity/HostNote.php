<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity on the host's manager: pending work of the host that a marketing tool write unit must never carry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
