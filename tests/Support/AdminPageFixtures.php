<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Support;

use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Form\CsrfActionType;
use Nowo\MarketingKitBundle\Form\MarketingToolType;
use Nowo\MarketingKitBundle\Form\ProfileFilterType;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use Nowo\MarketingKitBundle\Ui\MarketingKitPages;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function http_build_query;
use function sprintf;
use function str_replace;

/**
 * Template contexts of the admin pages, built like MarketingToolAdminController builds them (REQ-UI-003 contract
 * test and page tests).
 */
final class AdminPageFixtures
{
    /**
     * @return list<MarketingTool>
     */
    public static function tools(): array
    {
        $gtm = (new MarketingTool())->setProfile('default')->setCode('gtm')->setType('gtm')->setCategory('analytics')
            ->setPosition('head')->setEnabled(true)->setOptions(['container_id' => 'GTM-DEMO123']);
        $clarity = (new MarketingTool())->setProfile('default')->setCode('clarity')->setType('clarity')->setCategory('analytics')
            ->setPosition('head')->setEnabled(false)->setOptions(['project_id' => '']);
        $custom = (new MarketingTool())->setProfile('default')->setCode('custom')->setType('custom')->setCategory('marketing')
            ->setPosition('body_end')->setEnabled(true)->setOptions(['html' => '<script>window.demo = "a long custom snippet value";</script>']);
        $bare = (new MarketingTool())->setProfile('default')->setCode('linkedin')->setType('linkedin')->setOptions([]);
        foreach ([$gtm, $clarity, $custom, $bare] as $i => $tool) {
            (new ReflectionProperty(MarketingTool::class, 'id'))->setValue($tool, $i + 1);
        }

        return [$gtm, $clarity, $custom, $bare];
    }

    /**
     * @param list<MarketingTool>|null $tools
     *
     * @return array<string, mixed>
     */
    public static function index(?array $tools = null, bool $useDatabaseConfig = true, ?MarketingKitPages $pages = null): array
    {
        $tools ??= self::tools();
        $forms  = self::forms();
        $toggle = [];
        $delete = [];
        foreach ($tools as $tool) {
            $id          = (int) $tool->getId();
            $toggle[$id] = self::csrfForm($forms, 'toggle_marketing_tool_' . $id, sprintf('/admin/marketing/%d/toggle', $id));
            $delete[$id] = self::csrfForm($forms, 'delete_marketing_tool_' . $id, sprintf('/admin/marketing/%d/delete', $id));
        }
        $filters = $forms->createNamed('', ProfileFilterType::class, ['profile' => 'default'], [
            'action'   => '/admin/marketing',
            'profiles' => ['default', 'shop'],
        ])->createView();
        $seed   = self::csrfForm($forms, 'seed_marketing_tools_default', '/admin/marketing/seed', 'default', 'seed');
        $import = self::csrfForm($forms, 'import_marketing_yaml_default', '/admin/marketing/import-yaml', 'default', 'import');

        $page = ($pages ?? new MarketingKitPages())->listPage($tools, 'default', new MarketingToolCatalog(), $filters, $seed, $import, $toggle, $delete, self::urls());

        return [
            'page'                => $page,
            'tools'               => $tools,
            'profile'             => 'default',
            'profiles'            => ['default', 'shop'],
            'catalog'             => new MarketingToolCatalog(),
            'use_database_config' => $useDatabaseConfig,
            'profile_form'        => $filters,
            'seed_form'           => $seed,
            'import_form'         => $import,
            'toggle_forms'        => $toggle,
            'delete_forms'        => $delete,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function form(bool $isNew = false, bool $useDatabaseConfig = true): array
    {
        $tool = $isNew
            ? (new MarketingTool())->setProfile('default')->setCode('custom')->setType('custom')->setOptions(['html' => ''])
            : self::tools()[0];
        $catalog = new MarketingToolCatalog();
        $form    = self::forms()->create(MarketingToolType::class, $tool, ['profile_choices' => ['default', 'shop']])->createView();

        return [
            'page'                => (new MarketingKitPages())->formPage($tool, $isNew, $form, $catalog, self::urls()),
            'form'                => $form,
            'tool'                => $tool,
            'is_new'              => $isNew,
            'catalog'             => $catalog,
            'use_database_config' => $useDatabaseConfig,
        ];
    }

    /**
     * Router stand-in: `/admin/marketing` + the route suffix, `{id}` and query parameters.
     */
    public static function urls(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $path = match ($name) {
                    'nowo_marketing_kit_admin_index'       => '/admin/marketing',
                    'nowo_marketing_kit_admin_new'         => '/admin/marketing/new',
                    'nowo_marketing_kit_admin_seed'        => '/admin/marketing/seed',
                    'nowo_marketing_kit_admin_import_yaml' => '/admin/marketing/import-yaml',
                    default                                => '/admin/marketing/{id}/' . str_replace('nowo_marketing_kit_admin_', '', $name),
                };
                if (isset($parameters['id'])) {
                    $path = str_replace('{id}', (string) $parameters['id'], $path);
                    unset($parameters['id']);
                }

                return $parameters === [] ? $path : $path . '?' . http_build_query($parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }

    public static function forms(): FormFactoryInterface
    {
        $csrf = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrf))
            ->addType(FormKitTestSupport::withMerger(new MarketingToolType(new MarketingToolCatalog())))
            ->getFormFactory();
    }

    private static function csrfForm(FormFactoryInterface $forms, string $tokenId, string $action, ?string $profile = null, string $idSuffix = ''): FormView
    {
        return $forms->createNamed('', CsrfActionType::class, $profile === null ? null : ['profile' => $profile], [
            'action'        => $action,
            'csrf_token_id' => $tokenId,
            'with_profile'  => $profile !== null,
            'id_suffix'     => $idSuffix,
        ])->createView();
    }
}
