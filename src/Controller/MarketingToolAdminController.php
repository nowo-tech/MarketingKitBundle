<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\EventListener\MarketingToolConflictListener;
use Nowo\MarketingKitBundle\Form\CsrfActionType;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Form\ProfileFilterType;
use Nowo\MarketingKitBundle\Persistence\MarketingToolWriteUnit;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\VarExporter\LazyObjectInterface;

use function ctype_digit;
use function in_array;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * CRUD admin to configure marketing services (providers) stored in Doctrine. Pages are UiKit page scaffolds
 * (REQ-UI-003): the view models come from {@see MarketingKitPages}; routes live under `web_ui.path_prefix`
 * (default `/admin/marketing`) and answer 404 when `web_ui.enabled` is false.
 *
 * Every write runs in one {@see MarketingToolWriteUnit} (1.13, REQ-RUNTIME-003). A save that loses a race answers the
 * form again with the domain error and 409 + `X-Error-Code` (never a `CONFLICT_*` code): a code another admin saved in
 * the same profile at the same time ({@see MarketingToolConflictListener::DUPLICATE_CODE}), or a tool changed since the
 * form was opened ({@see MarketingToolConflictListener::STALE_TOOL}, optimistic `version`). The toggle carries the
 * version it was rendered for as well. Lock conflicts reach {@see MarketingToolConflictListener} (409 + CONFLICT_001 /
 * CONFLICT_002).
 *
 * @final since 1.7, will be final in 2.0 (not an extension point; decorate or replace the service instead). Since 1.14
 *        a subclass outside the bundle triggers a deprecation notice when it is constructed.
 */
#[Route(path: '/%nowo_marketing_kit.web_ui.route_path%', name: 'nowo_marketing_kit_admin_')]
class MarketingToolAdminController extends AbstractController
{
    /** 1.14: the notice of a host class that extends this controller (final in 2.0). */
    public const SUBCLASS_DEPRECATION = '"%s" extends "%s", which is final in 2.0: extending the marketing tool admin controller is deprecated. Decorate or replace the controller service, or alias the bundle\'s ports (access checker, tool source, renderers) instead (see UPGRADING.md "To 1.14.0").';

    public function __construct(
        private readonly MarketingToolRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketingToolAdminService $adminService,
        private readonly MarketingToolCatalog $catalog,
        private readonly MarketingKitAccessCheckerInterface $accessChecker,
        private readonly bool $useDatabaseConfig,
        private readonly MarketingKitPages $pages = new MarketingKitPages(),
        private readonly ?MarketingToolWriteUnit $writeUnit = null,
    ) {
        // 1.14: a subclass of the host's (a class outside the bundle that extends this one) breaks in 2.0, where the
        // class is final. Lazy-object proxies of the container are not subclasses of the host's.
        // @phpstan-ignore notIdentical.alwaysFalse, booleanAnd.alwaysFalse (static is a host subclass until 2.0)
        if (static::class !== self::class && !$this instanceof LazyObjectInterface) {
            trigger_deprecation('nowo-tech/marketing-kit-bundle', '1.14', self::SUBCLASS_DEPRECATION, static::class, self::class);
        }
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
            $toggleForms[$id] = $this->csrfActionForm('toggle_marketing_tool_' . $id, $this->generateUrl('nowo_marketing_kit_admin_toggle', ['id' => $id]), version: $tool->getVersion())->createView();
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
        if (!$this->isKnownProfile($profile)) {
            $this->addFlash('error', 'Unknown profile.');

            return $this->redirectToRoute('nowo_marketing_kit_admin_index');
        }
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
        if (!$this->isKnownProfile($profile)) {
            $this->addFlash('error', 'Unknown profile.');

            return $this->redirectToRoute('nowo_marketing_kit_admin_index');
        }
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
        if (!$this->isKnownProfile($profile)) {
            $profile = $this->adminService->defaultProfile();
        }
        $tool = (new MarketingTool())
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

    #[Route(path: '/{id}/edit', name: 'edit', requirements: ['id' => '\d{1,18}'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $this->denyUnlessGranted();
        $tool = $this->findTool($id);

        return $this->handleForm($request, $tool, false);
    }

    #[Route(path: '/{id}/toggle', name: 'toggle', requirements: ['id' => '\d{1,18}'], methods: ['POST'])]
    public function toggle(Request $request, int $id): Response
    {
        $this->denyUnlessGranted();
        $tool = $this->findTool($id);

        $form = $this->csrfActionForm('toggle_marketing_tool_' . $tool->getId(), '', version: $tool->getVersion());
        if ($this->isCsrfActionValid($request, $form)) {
            $expectedVersion = $this->submittedVersion($form);
            $profile         = $tool->getProfile();

            try {
                // A toggle rendered for an older version is refused, and the UPDATE checks the version read here, so
                // two toggles never lose one.
                $this->writeUnit()->run(static function (EntityManagerInterface $manager) use ($tool, $expectedVersion): void {
                    if ($expectedVersion !== null) {
                        $manager->lock($tool, LockMode::OPTIMISTIC, $expectedVersion);
                    }
                    $tool->setEnabled(!$tool->isEnabled());
                });
            } catch (OptimisticLockException) {
                // The domain answer (never a CONFLICT_* code): back to the freshly read list, which is the "reload" the
                // message asks for (audit US-4), with the error flashed and X-Error-Code on the redirect.
                $this->addFlash('error', MarketingToolConflictListener::STALE_TOOL_MESSAGE);

                return new RedirectResponse(
                    $this->generateUrl('nowo_marketing_kit_admin_index', ['profile' => $profile]),
                    Response::HTTP_FOUND,
                    [MarketingToolConflictListener::ERROR_CODE_HEADER => MarketingToolConflictListener::STALE_TOOL],
                );
            }
            $this->addFlash('success', sprintf('Service "%s" is now %s.', $tool->getCode(), $tool->isEnabled() ? 'enabled' : 'disabled'));
        }

        return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);
    }

    #[Route(path: '/{id}/delete', name: 'delete', requirements: ['id' => '\d{1,18}'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnlessGranted();
        $tool = $this->findTool($id);

        $profile = $tool->getProfile();
        if ($this->isCsrfActionValid($request, $this->csrfActionForm('delete_marketing_tool_' . $tool->getId(), ''))) {
            $this->writeUnit()->run(static function (EntityManagerInterface $manager) use ($tool): void {
                $manager->remove($tool);
            });
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

        $status    = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $headers   = [];
        $reloadUrl = null;
        if ($form->isSubmitted() && !$form->isValid()) {
            // Audit US-5: the form bound unvalidated values into the managed tool; forget it, so a later flush of the
            // host's own work never writes them. Only the bundle's entity is detached, never the host's.
            $this->forget($tool);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $expectedVersion = $isNew ? null : $this->submittedVersion($form);

            try {
                $this->writeUnit()->run(static function (EntityManagerInterface $manager) use ($tool, $expectedVersion): void {
                    if ($expectedVersion !== null) {
                        // Opened on an older version: refuse before writing (OptimisticLockException).
                        $manager->lock($tool, LockMode::OPTIMISTIC, $expectedVersion);
                    }
                    $manager->persist($tool);
                });
                $this->addFlash('success', $isNew ? 'Service created.' : 'Service updated.');

                return $this->redirectToRoute('nowo_marketing_kit_admin_index', ['profile' => $tool->getProfile()]);
            } catch (UniqueConstraintViolationException|OptimisticLockException $race) {
                // An expected race (REQ-RUNTIME-003 rule 4), not a lock conflict: the domain answer on the form.
                $code = (string) MarketingToolConflictListener::domainCode($race);
                $form->addError(new FormError($code === MarketingToolConflictListener::DUPLICATE_CODE ? MarketingToolConflictListener::DUPLICATE_CODE_MESSAGE : MarketingToolConflictListener::STALE_TOOL_MESSAGE));
                $status  = Response::HTTP_CONFLICT;
                $headers = [MarketingToolConflictListener::ERROR_CODE_HEADER => $code];
                // Audit US-4: a link to the current row (the edit form read again) or to a fresh form, so the admin is
                // not stuck resubmitting the old version.
                $reloadUrl = $isNew || $tool->getId() === null
                    ? $this->generateUrl('nowo_marketing_kit_admin_new', ['profile' => $tool->getProfile()])
                    : $this->generateUrl('nowo_marketing_kit_admin_edit', ['id' => $tool->getId()]);
            }
        }

        $formView = $form->createView();

        return $this->render($this->pages->template('form'), [
            'page'                => $this->pages->formPage($tool, $isNew, $formView, $this->catalog, $this->urls()),
            'form'                => $formView,
            'tool'                => $tool,
            'is_new'              => $isNew,
            'catalog'             => $this->catalog,
            'use_database_config' => $this->useDatabaseConfig,
            'reload_url'          => $reloadUrl,
        ], new Response(null, $status, $headers));
    }

    /**
     * Detaches a bundle tool from its manager when it is managed there (a bundle entity only; nothing else is touched).
     */
    private function forget(MarketingTool $tool): void
    {
        if ($this->entityManager->contains($tool)) {
            $this->entityManager->detach($tool);
        }
    }

    /**
     * The version the form was rendered with (hidden `version` field); null when the form does not carry one.
     *
     * @param FormInterface<mixed> $form
     */
    private function submittedVersion(FormInterface $form): ?int
    {
        $version = $form->has('version') ? $form->get('version')->getData() : null;

        return is_string($version) && ctype_digit($version) ? (int) $version : null;
    }

    /**
     * The injected write unit, else one on the injected manager (the host's: never cleared or reset while open).
     */
    private function writeUnit(): MarketingToolWriteUnit
    {
        return $this->writeUnit ?? new MarketingToolWriteUnit($this->entityManager);
    }

    /**
     * Flat-named CSRF-only form (`_token`, optional hidden `profile` and, since 1.13, `version`) — REQ-TWIG-005.
     *
     * @return FormInterface<mixed>
     */
    private function csrfActionForm(string $csrfTokenId, string $action, ?string $profile = null, string $idSuffix = '', ?int $version = null): FormInterface
    {
        return $this->formFactory()->createNamed('', CsrfActionType::class, $profile === null ? null : ['profile' => $profile], [
            'action'        => $action,
            'csrf_token_id' => $csrfTokenId,
            'with_profile'  => $profile !== null,
            'with_version'  => $version !== null,
            'version'       => $version,
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

    /**
     * Loads a tool after the access check, so an unauthorised caller cannot probe ids (403 before 404; SEC-004 I2).
     */
    private function findTool(int $id): MarketingTool
    {
        $tool = $this->repository->find($id);
        if (!$tool instanceof MarketingTool) {
            throw new NotFoundHttpException('Marketing tool not found.');
        }

        return $tool;
    }

    private function isKnownProfile(string $profile): bool
    {
        return in_array($profile, $this->adminService->profileChoices(), true);
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
