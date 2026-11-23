<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/demo',
        // @var mixed required: Form PHPDoc types MarketingTool while data_class=null returns array.
        __DIR__ . '/tests/Unit/Form/MarketingToolTypeTest.php',
        // 2.0 makes the admin controller final: keep its action signatures as in 1.x (no unannounced change).
        NarrowObjectReturnTypeRector::class => [__DIR__ . '/src/Controller/MarketingToolAdminController.php'],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
