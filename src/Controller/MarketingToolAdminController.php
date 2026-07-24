<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;
use function sprintf;

/**
 * CRUD admin to configure marketing services (providers) stored in Doctrine.
 */
#[Route(path: '/admin/marketing', name: 'nowo_marketing_kit_admin_')]
class MarketingToolAdminController extends AbstractController
{
    public function __construct(
        private readonly MarketingToolRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketingToolAdminService $adminService,
        private readonly MarketingToolCatalog $catalog,
        private readonly bool $useDatabaseConfig,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $profile  = (string) $request->query->get('profile', $this->adminService->defaultProfile());
        $profiles = $this->adminService->profileChoices();
        if (!in_array($profile, $profiles, true)) {
            $profile = $this->adminService->defaultProfile();
        }

        return $this->render('@NowoMarketingKitBundle/admin/index.html.twig', [
            'tools'               => $this->repository->findByProfileOrdered($profile),
            'profile'             => $profile,
            'profiles'            => $profiles,
            'catalog'             => $this->catalog,
            'use_database_config' => $this->useDatabaseConfig,
        ]);
    }

    #[Route(path: '/seed', name: 'seed', methods: ['POST'])]
    public function seed(Request $request): Response
    {
        $profile = (string) $request->request->get('profile', $this->adminService->defaultProfile());
        if (!$this->isCsrfTokenValid('seed_marketing_tools_' . $profile, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $profile]);
        }

        $created = $this->adminService->seedCatalog($profile);
        $this->addFlash(
            'success',
            $created > 0
                ? sprintf('Seeded %d marketing service(s) for profile "%s".', $created, $profile)
                : sprintf('All catalog services already exist for profile "%s".', $profile),
        );

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $profile]);
    }

    #[Route(path: '/import-yaml', name: 'import_yaml', methods: ['POST'])]
    public function importYaml(Request $request): Response
    {
        $profile = (string) $request->request->get('profile', $this->adminService->defaultProfile());
        if (!$this->isCsrfTokenValid('import_marketing_yaml_' . $profile, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $profile]);
        }

        $touched = $this->adminService->importFromYaml($profile);
        $this->addFlash(
            'success',
            $touched > 0
                ? sprintf('Imported/updated %d service(s) from YAML profile "%s".', $touched, $profile)
                : sprintf('No YAML tools found for profile "%s".', $profile),
        );

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $profile]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $profile = (string) $request->query->get('profile', $this->adminService->defaultProfile());
        $tool    = (new MarketingTool())
            ->setProfile($profile)
            ->setCode('custom')
            ->setType('custom')
            ->setEnabled(true)
            ->setCategory('marketing')
            ->setPosition('body_end')
            ->setSortOrder(100)
            ->setOptions(['html' => '']);

        return $this->handleForm($request, $tool, true);
    }

    #[Route(path: '/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, MarketingTool $tool): Response
    {
        return $this->handleForm($request, $tool, false);
    }

    #[Route(path: '/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, MarketingTool $tool): Response
    {
        if ($this->isCsrfTokenValid('toggle_marketing_tool_' . $tool->getId(), (string) $request->request->get('_token'))) {
            $tool->setEnabled(!$tool->isEnabled());
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Service "%s" is now %s.', $tool->getCode(), $tool->isEnabled() ? 'enabled' : 'disabled'));
        }

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, MarketingTool $tool): Response
    {
        $profile = $tool->getProfile();
        if ($this->isCsrfTokenValid('delete_marketing_tool_' . $tool->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($tool);
            $this->entityManager->flush();
            $this->addFlash('success', 'Service deleted.');
        }

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $profile]);
    }

    private function handleForm(Request $request, MarketingTool $tool, bool $isNew): Response
    {
        $form = $this->createForm(MarketingToolType::class, $tool, [
            'profile_choices' => $this->adminService->profileChoices(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($tool);
            $this->entityManager->flush();
            $this->addFlash('success', $isNew ? 'Service created.' : 'Service updated.');

            return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);
        }

        return $this->render('@NowoMarketingKitBundle/admin/form.html.twig', [
            'form'                => $form,
            'tool'                => $tool,
            'is_new'              => $isNew,
            'catalog'             => $this->catalog,
            'use_database_config' => $this->useDatabaseConfig,
        ]);
    }
}
