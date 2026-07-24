<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\Tests\Unit\Coverage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\MarketingKitBundle\Config\MarketingConfigResolver;
use Nowo\MarketingKitBundle\Config\ResolvedTool;
use Nowo\MarketingKitBundle\Consent\CookieConsentGate;
use Nowo\MarketingKitBundle\DependencyInjection\TablePrefixListener;
use Nowo\MarketingKitBundle\Entity\MarketingTool;
use Nowo\MarketingKitBundle\Provider\ClarityRenderer;
use Nowo\MarketingKitBundle\Provider\CustomScriptRenderer;
use Nowo\MarketingKitBundle\Provider\Ga4Renderer;
use Nowo\MarketingKitBundle\Provider\HotjarRenderer;
use Nowo\MarketingKitBundle\Provider\LinkedInRenderer;
use Nowo\MarketingKitBundle\Provider\MetaPixelRenderer;
use Nowo\MarketingKitBundle\Provider\TikTokRenderer;
use Nowo\MarketingKitBundle\Provider\ToolRendererRegistry;
use Nowo\MarketingKitBundle\Repository\MarketingToolRepository;
use Nowo\MarketingKitBundle\Service\MarketingScriptRenderer;
use Nowo\MarketingKitBundle\Service\MarketingToolAdminService;
use Nowo\MarketingKitBundle\Service\MarketingToolCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class RemainingCoverageTest extends TestCase
{
    public function testProviderEmptyOptionsAndSupports(): void
    {
        $empty = new ResolvedTool('x', 'ga4', true, 'analytics', 'head', 0, [], 'yaml');
        self::assertSame('', (new Ga4Renderer())->render($empty));
        self::assertTrue((new Ga4Renderer())->supports('ga4'));
        self::assertSame('', (new MetaPixelRenderer())->render($empty));
        self::assertTrue((new MetaPixelRenderer())->supports('meta_pixel'));
        self::assertSame('', (new LinkedInRenderer())->render($empty));
        self::assertTrue((new LinkedInRenderer())->supports('linkedin'));
        self::assertSame('', (new TikTokRenderer())->render($empty));
        self::assertTrue((new TikTokRenderer())->supports('tiktok'));
        self::assertSame('', (new ClarityRenderer())->render($empty));
        self::assertTrue((new ClarityRenderer())->supports('clarity'));
        self::assertSame('', (new CustomScriptRenderer())->render($empty));
        self::assertTrue((new CustomScriptRenderer())->supports('custom'));
        self::assertTrue((new HotjarRenderer())->supports('hotjar'));

        $registry = new ToolRendererRegistry([]);
        self::assertSame('', $registry->render($empty));
        self::assertFalse($registry->supports('gtm'));
    }

    public function testCatalogFallbacksAndAdminEdges(): void
    {
        $catalog = new MarketingToolCatalog();
        self::assertSame(['html'], $catalog->optionKeysForType('unknown'));
        self::assertSame(['html' => 'HTML / JS snippet'], $catalog->optionLabelsForType('unknown'));
        self::assertSame('missing', $catalog->labelForCode('missing'));
        self::assertSame(['container_id'], $catalog->optionKeysForType('gtm'));

        $repo     = $this->createMock(MarketingToolRepository::class);
        $existing = (new MarketingTool())->setCode('gtm');
        $repo->method('findByProfileOrdered')->willReturn([$existing]);
        $em = $this->createMock(EntityManagerInterface::class);

        $service = new MarketingToolAdminService($repo, $catalog, $em, [], 'default');
        self::assertSame(['default'], $service->profileChoices());
        self::assertSame(8, $service->seedCatalog('default')); // 9-1 existing
        self::assertSame(0, $service->importFromYaml('default'));
        self::assertSame(0, $service->importFromYaml('missing'));
    }

    public function testTablePrefixIgnoresForeignEntities(): void
    {
        $listener = new TablePrefixListener('app_');
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('App\\Entity\\Other');
        $metadata->expects(self::never())->method('setPrimaryTable');
        $event = $this->createMock(LoadClassMetadataEventArgs::class);
        $event->method('getClassMetadata')->willReturn($metadata);
        $listener->loadClassMetadata($event);
    }

    public function testUnknownProfileAndDisabledProfile(): void
    {
        $resolver = new MarketingConfigResolver([
            'default' => ['enabled' => false, 'tools' => []],
        ], 'default', false);

        self::assertFalse($resolver->resolve('missing')->enabled);
        self::assertFalse($resolver->resolve('default')->enabled);

        $renderer = new MarketingScriptRenderer(
            $resolver,
            new ToolRendererRegistry([]),
            new CookieConsentGate(new RequestStack(), false),
        );
        self::assertSame('', $renderer->renderHead('default'));
    }

    public function testImportEmptyToolsAndUpsertExisting(): void
    {
        $existing = (new MarketingTool())->setProfile('default')->setCode('gtm');
        $repo     = $this->createMock(MarketingToolRepository::class);
        $repo->method('findByProfileOrdered')->willReturn([$existing]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($existing);
        $em->expects(self::once())->method('flush');

        $service = new MarketingToolAdminService(
            $repo,
            new MarketingToolCatalog(),
            $em,
            [
                'empty'   => ['tools' => []],
                'default' => [
                    'tools' => [
                        'gtm' => ['type' => 'gtm', 'options' => ['container_id' => 'GTM-2']],
                    ],
                ],
            ],
            'default',
        );

        self::assertSame(0, $service->importFromYaml('empty'));
        self::assertSame(1, $service->importFromYaml('default'));
        self::assertSame('GTM-2', $existing->getOptions()['container_id']);
    }
}
