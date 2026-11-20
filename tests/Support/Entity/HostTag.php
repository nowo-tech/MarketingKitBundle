<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A second host entity (1.14): the host-flush probe reports one deprecation per foreign class.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_tag')]
class HostTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64)]
    public string $label = '';
}
