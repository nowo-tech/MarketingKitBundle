<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\CsrfActionType;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Form\ProfileFilterType;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function sprintf;

/**
 * CRUD admin to configure marketing services (providers) stored in Doctrine. Pages are UiKit page scaffolds
 * (REQ-UI-003): the view models come from {@see MarketingKitPages}; routes live under `web_ui.path_prefix`
 * (default `/admin/marketing`) and answer 404 when `web_ui.enabled` is false.
 *
 * @final since 1.7, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[Route(path: '/%nowo_marketing_kit.web_ui.route_path%', name: 'nowo_marketing_kit_admin_')]
class MarketingToolAdminController extends AbstractController
{
    public function __construct(
        private readonly MarketingToolRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketingToolAdminService $adminService,
        private readonly MarketingToolCatalog $catalog,
        private readonly MarketingKitAccessCheckerInterface $accessChecker,
        private readonly bool $useDatabaseConfig,
        private readonly MarketingKitPages $pages = new MarketingKitPages(),
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessGranted();

        $profile  = (string) $request->query->get('profile', $this->adminService->defaultProfile());
        $profiles = $this->adminService->profileChoices();
        if (!in_array($profile, $profiles, true)) {
            $profile = $this->adminService->defaultProfile();
        }

        $tools = $this->repository->findByProfileOrdered($profile);

        /** @var array<int, FormView> $toggleForms */
        $toggleForms = [];
        /** @var array<int, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($tools as $tool) {
            $id               = (int) $tool->getId();
            $toggleForms[$id] = $this->csrfActionForm('toggle_marketing_tool_' . $id, $this->generateUrl('nowo_marketing_kit_admin_toggle', ['id' => $id]))->createView();
            $deleteForms[$id] = $this->csrfActionForm('delete_marketing_tool_' . $id, $this->generateUrl('nowo_marketing_kit_admin_delete', ['id' => $id]))->createView();
        }

        $profileForm = $this->formFactory()->createNamed('', ProfileFilterType::class, ['profile' => $profile], [
            'action'   => $this->generateUrl('nowo_marketing_kit_admin_index'),
            'profiles' => $profiles,
        ])->createView();
        $seedForm   = $this->csrfActionForm('seed_marketing_tools_' . $profile, $this->generateUrl('nowo_marketing_kit_admin_seed'), $profile, 'seed')->createView();
        $importForm = $this->csrfActionForm('import_marketing_yaml_' . $profile, $this->generateUrl('nowo_marketing_kit_admin_import_yaml'), $profile, 'import')->createView();

        return $this->render($this->pages->template('index'), [
            'page'                => $this->pages->listPage($tools, $profile, $this->catalog, $profileForm, $seedForm, $importForm, $toggleForms, $deleteForms, $this->urls()),
            'tools'               => $tools,
            'profile'             => $profile,
            'profiles'            => $profiles,
            'catalog'             => $this->catalog,
            'use_database_config' => $this->useDatabaseConfig,
            'profile_form'        => $profileForm,
            'seed_form'           => $seedForm,
            'import_form'         => $importForm,
            'toggle_forms'        => $toggleForms,
            'delete_forms'        => $deleteForms,
        ]);
    }

    #[Route(path: '/seed', name: 'seed', methods: ['POST'])]
    public function seed(Request $request): Response
    {
        $this->denyUnlessGranted();

        $profile = (string) $request->request->get('profile', $this->adminService->defaultProfile());
        if (!$this->isCsrfActionValid($request, $this->csrfActionForm('seed_marketing_tools_' . $profile, '', $profile))) {
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
        $this->denyUnlessGranted();

        $profile = (string) $request->request->get('profile', $this->adminService->defaultProfile());
        if (!$this->isCsrfActionValid($request, $this->csrfActionForm('import_marketing_yaml_' . $profile, '', $profile))) {
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
        $this->denyUnlessGranted();

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
        $this->denyUnlessGranted();

        return $this->handleForm($request, $tool, false);
    }

    #[Route(path: '/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, MarketingTool $tool): Response
    {
        $this->denyUnlessGranted();

        if ($this->isCsrfActionValid($request, $this->csrfActionForm('toggle_marketing_tool_' . $tool->getId(), ''))) {
            $tool->setEnabled(!$tool->isEnabled());
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Service "%s" is now %s.', $tool->getCode(), $tool->isEnabled() ? 'enabled' : 'disabled'));
        }

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, MarketingTool $tool): Response
    {
        $this->denyUnlessGranted();

        $profile = $tool->getProfile();
        if ($this->isCsrfActionValid($request, $this->csrfActionForm('delete_marketing_tool_' . $tool->getId(), ''))) {
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

        $formView = $form->createView();

        return $this->render($this->pages->template('form'), [
            'page'                => $this->pages->formPage($tool, $isNew, $formView, $this->catalog, $this->urls()),
            'form'                => $formView,
            'tool'                => $tool,
            'is_new'              => $isNew,
            'catalog'             => $this->catalog,
            'use_database_config' => $this->useDatabaseConfig,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Flat-named CSRF-only form (`_token`, optional hidden `profile`) — REQ-TWIG-005.
     *
     * @return FormInterface<mixed>
     */
    private function csrfActionForm(string $csrfTokenId, string $action, ?string $profile = null, string $idSuffix = ''): FormInterface
    {
        return $this->formFactory()->createNamed('', CsrfActionType::class, $profile === null ? null : ['profile' => $profile], [
            'action'        => $action,
            'csrf_token_id' => $csrfTokenId,
            'with_profile'  => $profile !== null,
            'id_suffix'     => $idSuffix,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function isCsrfActionValid(Request $request, FormInterface $form): bool
    {
        // Root-less form: submit the POST body explicitly (handleRequest() skips a root-less form whose payload has no
        // child field, which is the case of the CSRF-only actions).
        $form->submit($request->request->all());

        return $form->isSubmitted() && $form->isValid();
    }

    private function formFactory(): FormFactoryInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->container->get('form.factory');

        return $factory;
    }

    private function urls(): UrlGeneratorInterface
    {
        /** @var UrlGeneratorInterface $router */
        $router = $this->container->get('router');

        return $router;
    }

    private function denyUnlessGranted(): void
    {
        if (!$this->pages->isEnabled()) {
            throw new NotFoundHttpException('MarketingKit admin is disabled (web_ui.enabled: false).');
        }
        if (!$this->accessChecker->canAccess()) {
            throw new AccessDeniedException('Access denied to MarketingKit admin.');
        }
    }
}
