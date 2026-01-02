<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\FullyQualifiedStrictTypesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPreparedSets(
        psr12: true,
        common: true,
        strict: true,
    )
    ->withConfiguredRule(FullyQualifiedStrictTypesFixer::class, [
        'import_symbols' => true,
    ])
    ->withSkip([
        // Skip headers.php as it defines global functions intentionally
        __DIR__ . '/src/headers.php',
    ]);
