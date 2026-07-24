<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;

use function is_array;

/**
 * Seeds and imports marketing tools for the admin CRUD.
 */
final readonly class MarketingToolAdminService
{
    /**
     * @param array<string, array{enabled?: bool, tools?: array<string, array<string, mixed>>}> $profiles
     */
    public function __construct(
        private MarketingToolRepository $repository,
        private MarketingToolCatalog $catalog,
        private EntityManagerInterface $entityManager,
        private array $profiles,
        private string $defaultProfile,
    ) {
    }

    /**
     * @return list<string>
     */
    public function profileChoices(): array
    {
        $names = array_keys($this->profiles);
        if ($names === []) {
            return [$this->defaultProfile];
        }

        sort($names);

        return $names;
    }

    public function defaultProfile(): string
    {
        return $this->defaultProfile;
    }

    /**
     * Creates missing catalog tools for a profile (disabled by default).
     *
     * @return int Number of tools created
     */
    public function seedCatalog(string $profile): int
    {
        $existing = [];
        foreach ($this->repository->findByProfileOrdered($profile) as $tool) {
            $existing[$tool->getCode()] = true;
        }

        $created = 0;
        foreach ($this->catalog->all() as $entry) {
            if (isset($existing[$entry['code']])) {
                continue;
            }

            $options = [];
            foreach ($entry['option_keys'] as $key) {
                $options[$key] = '';
            }

            $tool = (new MarketingTool())
                ->setProfile($profile)
                ->setCode($entry['code'])
                ->setType($entry['type'])
                ->setEnabled(false)
                ->setCategory($entry['category'])
                ->setPosition($entry['position'])
                ->setSortOrder($entry['sort_order'])
                ->setOptions($options);

            $this->entityManager->persist($tool);
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * Imports tools from a YAML profile into the database (upsert by code).
     *
     * @return int Number of tools created or updated
     */
    public function importFromYaml(string $profile): int
    {
        $yamlProfile = $this->profiles[$profile] ?? null;
        if ($yamlProfile === null) {
            return 0;
        }

        /** @var array<string, array<string, mixed>> $tools */
        $tools = $yamlProfile['tools'] ?? [];
        if ($tools === []) {
            return 0;
        }

        $byCode = [];
        foreach ($this->repository->findByProfileOrdered($profile) as $tool) {
            $byCode[$tool->getCode()] = $tool;
        }

        $touched = 0;
        foreach ($tools as $code => $definition) {
            $tool = $byCode[$code] ?? (new MarketingTool())->setProfile($profile)->setCode((string) $code);
            $tool
                ->setType((string) ($definition['type'] ?? 'custom'))
                ->setEnabled((bool) ($definition['enabled'] ?? true))
                ->setCategory((string) ($definition['category'] ?? 'marketing'))
                ->setPosition((string) ($definition['position'] ?? 'head'))
                ->setSortOrder((int) ($definition['sort_order'] ?? 0))
                ->setOptions(is_array($definition['options'] ?? null) ? $definition['options'] : []);

            $this->entityManager->persist($tool);
            ++$touched;
        }

        $this->entityManager->flush();

        return $touched;
    }
}
