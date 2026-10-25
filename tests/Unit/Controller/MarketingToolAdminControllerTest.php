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
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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

    public function testIndexPassesToolbarAndRowForms(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('gtm');
        $this->setId($tool, 5);
        $repo = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->with('default')->willReturn([$tool]);

        $context = [];
        $twig    = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $name, array $ctx) use (&$context): string {
            $context = $ctx;

            return 'OK';
        });

        $controller = $this->controller($repo, $this->createMock(EntityManagerInterface::class), $this->adminService($repo), $twig);
        $controller->index(Request::create('/admin/marketing'));

        self::assertInstanceOf(FormView::class, $context['profile_form']);
        self::assertSame('profile', $context['profile_form']->children['profile']->vars['full_name']);
        self::assertSame('GET', $context['profile_form']->vars['method']);

        self::assertInstanceOf(FormView::class, $context['seed_form']);
        self::assertSame('/nowo_marketing_kit_admin_seed', $context['seed_form']->vars['action']);
        self::assertSame('profile', $context['seed_form']->children['profile']->vars['full_name']);
        self::assertSame('profile_seed', $context['seed_form']->children['profile']->vars['id']);
        self::assertSame('token-seed_marketing_tools_default', $context['seed_form']->children['_token']->vars['value']);
        self::assertInstanceOf(FormView::class, $context['import_form']);
        self::assertSame('profile_import', $context['import_form']->children['profile']->vars['id']);

        self::assertSame('token-toggle_marketing_tool_5', $context['toggle_forms'][5]->children['_token']->vars['value']);
        self::assertSame('_token', $context['delete_forms'][5]->children['_token']->vars['full_name']);
        self::assertSame('token-delete_marketing_tool_5', $context['delete_forms'][5]->children['_token']->vars['value']);
    }

    public function testToggleWithInvalidCsrfDoesNothing(): void
    {
        $tool = (new MarketingTool())->setProfile('default')->setCode('gtm')->setEnabled(false);
        $this->setId($tool, 7);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $request    = Request::create('/admin/marketing/7/toggle', 'POST', ['_token' => 'bad']);
        $repo       = $this->createMock(MarketingToolRepository::class);
        $controller = $this->controller($repo, $em, $this->adminService($repo), $this->createMock(Environment::class), csrfValid: false);

        self::assertSame(302, $controller->toggle($request, $tool)->getStatusCode());
        self::assertFalse($tool->isEnabled());
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

        $controller = new MarketingToolAdminController($repo, $em, $admin, new MarketingToolCatalog(), $accessChecker, true);

        // Real AbstractController helpers, wired to test doubles (no subclassing of the controller).
        $request ??= Request::create('/');
        if (!$request->hasSession()) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }
        $stack = new RequestStack();
        $stack->push($request);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token-' . $id));
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $container = new Container();
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('form.factory', $formFactory ?? Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->getFormFactory());
        $controller->setContainer($container);

        return $controller;
    }

    private function setId(MarketingTool $tool, int $id): void
    {
        (new ReflectionProperty(MarketingTool::class, 'id'))->setValue($tool, $id);
    }
}
