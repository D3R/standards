<?php

declare(strict_types=1);

use D3R\Standards\StaticAnalysis\XmlMetadata;

// Deliberately require only the pure generator, never the consuming app autoloader.
require dirname(__DIR__) . '/src/StaticAnalysis/XmlMetadata.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/static-analysis-metadata.php manifest.json output-directory\n");
    exit(2);
}

try {
    $manifestPath = realpath($argv[1]);
    if ($manifestPath === false) {
        throw new RuntimeException('Manifest file does not exist.');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $result = XmlMetadata::generate($manifest, dirname($manifestPath));
    $inventoryJson = json_encode($result['inventory'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $output = $argv[2];
    if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
        throw new RuntimeException('Cannot create metadata output directory.');
    }
    if (file_put_contents($output . '/models.stub.php', $result['php']) === false) {
        throw new RuntimeException('Cannot write generated declarations.');
    }
    if (file_put_contents($output . '/models.mago.php', $result['magoPhp']) === false) {
        throw new RuntimeException('Cannot write Mago property declarations.');
    }
    if (file_put_contents($output . '/inventory.json', $inventoryJson) === false) {
        throw new RuntimeException('Cannot write metadata inventory.');
    }
    echo 'Generated ' . count($result['inventory']['models']) . " model declaration(s).\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
