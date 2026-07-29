<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MarketingKitBundle\Controller\MarketingToolAdminController;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Security\MarketingKitAccessCheckerInterface;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class MarketingToolAdminControllerTest extends TestCase
{
    public function testIndexFallsBackForUnknownProfile(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->with('default')->willReturn([]);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('OK');

        $controller = $this->controller($repo, $this->createMock(EntityManagerInterface::class), $this->adminService($repo), $twig);
        $response   = $controller->index(Request::create('/admin/marketing', 'GET', ['profile' => 'unknown']));

        self::assertSame('OK', $response->getContent());
    }

    public function testSeedInvalidCsrf(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/marketing/seed', 'POST', [
            'profile' => 'default',
            '_token'  => 'bad',
        ]);
        $request->setSession($session);

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $this->adminService($repo),
            $this->createMock(Environment::class),
            csrfValid: false,
            request: $request,
        );

        self::assertSame(302, $controller->seed($request)->getStatusCode());
        self::assertNotEmpty($session->getFlashBag()->peek('error'));
    }

    public function testIndexRendersToolsForProfile(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('gtm');
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->with('default')->willReturn([$tool]);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('OK');

        $controller = $this->controller($repo, $this->createMock(EntityManagerInterface::class), $this->adminService($repo), $twig);
        $response   = $controller->index(Request::create('/admin/marketing'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());
    }

    public function testSeedCreatesCatalogAndRedirects(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::once())->method('flush');

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/marketing/seed', 'POST', [
            'profile' => 'default',
            '_token'  => 'token',
        ]);
        $request->setSession($session);

        $controller = $this->controller($repo, $em, $this->adminService($repo, $em), $this->createMock(Environment::class), csrfValid: true, request: $request);
        $response   = $controller->seed($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertNotEmpty($session->getFlashBag()->peek('success'));
    }

    public function testToggleFlipsEnabled(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('gtm')->setEnabled(false);
        $this->setId($tool, 7);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $request = Request::create('/admin/marketing/7/toggle', 'POST', ['_token' => 't']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller($repo, $em, $this->adminService($repo), $this->createMock(Environment::class), csrfValid: true);

        self::assertSame(302, $controller->toggle($request, $tool)->getStatusCode());
        self::assertTrue($tool->isEnabled());
    }

    public function testDeleteRemovesTool(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('gtm');
        $this->setId($tool, 3);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($tool);
        $em->expects(self::once())->method('flush');

        $request = Request::create('/admin/marketing/3/delete', 'POST', ['_token' => 't']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller($repo, $em, $this->adminService($repo), $this->createMock(Environment::class), csrfValid: true);

        self::assertSame(302, $controller->delete($request, $tool)->getStatusCode());
    }

    public function testNewPersistsOnValidSubmit(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('createView')->willReturn(new FormView());

        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->with(MarketingToolType::class, self::anything(), self::anything())->willReturn($form);

        $request = Request::create('/admin/marketing/new', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller(
            $repo,
            $em,
            $this->adminService($repo),
            $this->createMock(Environment::class),
            formFactory: $factory,
        );

        self::assertSame(302, $controller->new($request)->getStatusCode());
    }

    public function testImportYamlInvalidCsrf(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/marketing/import-yaml', 'POST', [
            'profile' => 'default',
            '_token'  => 'bad',
        ]);
        $request->setSession($session);

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $this->adminService($repo),
            $this->createMock(Environment::class),
            csrfValid: false,
            request: $request,
        );

        self::assertSame(302, $controller->importYaml($request)->getStatusCode());
        self::assertNotEmpty($session->getFlashBag()->peek('error'));
    }

    public function testImportYamlSuccess(): void
    {
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::once())->method('flush');

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/admin/marketing/import-yaml', 'POST', [
            'profile' => 'default',
            '_token'  => 'ok',
        ]);
        $request->setSession($session);

        $admin = new MarketingToolAdminService(
            $repo,
            new MarketingToolCatalog(),
            $em,
            [
                'default' => [
                    'tools' => [
                        'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-1']],
                    ],
                ],
            ],
            'default',
        );

        $controller = $this->controller($repo, $em, $admin, $this->createMock(Environment::class), csrfValid: true, request: $request);
        self::assertSame(302, $controller->importYaml($request)->getStatusCode());
        self::assertNotEmpty($session->getFlashBag()->peek('success'));
    }

    public function testEditRendersFormWhenNotSubmitted(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('ga4')->setType('ga4');
        $this->setId($tool, 1);

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(false);
        $form->method('createView')->willReturn(new FormView());

        $factory = $this->createMock(FormFactoryInterface::class);
        $factory->method('create')->willReturn($form);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('FORM');

        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller(
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $this->adminService($repo),
            $twig,
            formFactory: $factory,
        );

        self::assertSame('FORM', $controller->edit(Request::create('/admin/marketing/1/edit'), $tool)->getContent());
    }

    public function testIndexThrowsAccessDeniedWhenCheckerRejectsAccess(): void
    {
        $controller = $this->controller(
            $this->createMock(MarketingToolRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->adminService($this->createMock(MarketingToolRepository::class)),
            $this->createMock(Environment::class),
            accessAllowed: false,
        );

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/admin/marketing'));
    }

    private function adminService(
        MarketingToolRepository $repo,
        ?EntityManagerInterface $em = null,
    ): MarketingToolAdminService {
        return new MarketingToolAdminService(
            $repo,
            new MarketingToolCatalog(),
            $em ?? $this->createMock(EntityManagerInterface::class),
            ['default' => ['enabled' => true, 'tools' => []]],
            'default',
        );
    }

    private function controller(
        MarketingToolRepository $repo,
        EntityManagerInterface $em,
        MarketingToolAdminService $admin,
        Environment $twig,
        bool $csrfValid = true,
        ?FormFactoryInterface $formFactory = null,
        ?Request $request = null,
        bool $accessAllowed = true,
    ): MarketingToolAdminController {
        $accessChecker = new class($accessAllowed) implements MarketingKitAccessCheckerInterface {
            public function __construct(
                private readonly bool $accessAllowed,
            ) {
            }

            public function canAccess(): bool
            {
                return $this->accessAllowed;
            }
        };

        $controller = new class($repo, $em, $admin, new MarketingToolCatalog(), $accessChecker, true, $csrfValid, $twig, $formFactory) extends MarketingToolAdminController {
            public function __construct(
                MarketingToolRepository $repository,
                EntityManagerInterface $entityManager,
                MarketingToolAdminService $adminService,
                MarketingToolCatalog $catalog,
                MarketingKitAccessCheckerInterface $accessChecker,
                bool $useDatabaseConfig,
                private readonly bool $csrfValidFlag,
                private readonly Environment $twigEngine,
                private readonly ?FormFactoryInterface $forms,
            ) {
                parent::__construct($repository, $entityManager, $adminService, $catalog, $accessChecker, $useDatabaseConfig);
            }

            /**
             * @param array<string, mixed> $parameters
             */
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                return new Response($this->twigEngine->render($view, $parameters));
            }

            protected function isCsrfTokenValid(string $id, ?string $token): bool
            {
                return $this->csrfValidFlag;
            }

            protected function createForm(string $type, mixed $data = null, array $options = []): FormInterface
            {
                if (!$this->forms instanceof FormFactoryInterface) {
                    throw new RuntimeException('Form factory required');
                }

                return $this->forms->create($type, $data, $options);
            }

            protected function addFlash(string $type, mixed $message): void
            {
                $request = $this->container->get('request_stack')->getCurrentRequest();
                if ($request !== null && $request->hasSession()) {
                    $request->getSession()->getFlashBag()->add($type, (string) $message);
                }
            }

            /**
             * @param array<string, mixed> $parameters
             */
            protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/' . $route, $status);
            }
        };

        $stack = new RequestStack();
        if ($request instanceof Request) {
            $stack->push($request);
        }
        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        if ($formFactory instanceof FormFactoryInterface) {
            $container->set('form.factory', $formFactory);
        }
        $controller->setContainer($container);

        return $controller;
    }

    private function setId(MarketingTool $tool, int $id): void
    {
        (new ReflectionProperty(MarketingTool::class, 'id'))->setValue($tool, $id);
    }
}
