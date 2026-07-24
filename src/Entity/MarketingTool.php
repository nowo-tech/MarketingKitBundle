<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;

/**
 * Database-backed marketing tool definition (overrides YAML when use_database_config is true).
 */
#[ORM\Entity(repositoryClass: MarketingToolRepository::class)]
#[ORM\Table(name: 'marketing_tool')]
#[ORM\UniqueConstraint(name: 'uniq_marketing_tool_profile_code', columns: ['profile', 'code'])]
class MarketingTool
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /* @phpstan-ignore property.unusedType (Doctrine assigns id via reflection) */
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $profile = 'default';

    #[ORM\Column(length: 64)]
    private string $code = '';

    #[ORM\Column(length: 32)]
    private string $type = 'custom';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(length: 32)]
    private string $category = 'marketing';

    #[ORM\Column(length: 32)]
    private string $position = 'head';

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function setProfile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }
}
