<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support\LinkedEntity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Tests\Integration\Database\ZeroConfigDataLossDatabaseTest;

/**
 * 2.0 (wave-1 lesson W1-2): an application entity on the application's manager that points at a marketing tool. Only
 * mapped by the kernel of {@see ZeroConfigDataLossDatabaseTest}
 * (its association needs MarketingTool on the same manager).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_campaign')]
class HostCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MarketingTool::class)]
    #[ORM\JoinColumn(name: 'tool_id', nullable: true, onDelete: 'SET NULL')]
    public ?MarketingTool $tool = null;
}
