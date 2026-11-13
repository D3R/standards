<?php

declare(strict_types=1);

use D3R\Standards\StaticAnalysis\MagoXmlMethods;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

// SDK-only Composer project and generated inventory; never an application loader.
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/mago-metadata-worker.php sdk-project inventory.json\n");
    exit(2);
}

try {
    $sdk = realpath($argv[1]);
    if ($sdk === false) {
        throw new RuntimeException('SDK project does not exist.');
    }
    $manifest = json_decode(file_get_contents($sdk . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $requirements = array_keys($manifest['require'] ?? []);
    if (
        empty($manifest['require']['carthage-software/mago'])
        || array_diff($requirements, ['carthage-software/mago', 'nikic/php-parser', 'php'])
        || !empty($manifest['require-dev'])
        || !empty($manifest['autoload'])
        || !empty($manifest['autoload-dev'])
    ) {
        throw new RuntimeException('Worker requires an isolated SDK-only project, not an application.');
    }
    $inventory = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory['inputs'] ?? [] as $path => $expectedHash) {
        $stale = $expectedHash === null ? is_file($path)
            : (!is_file($path) || hash_file('sha256', $path) !== $expectedHash);
        if ($stale) {
            throw new RuntimeException('Model metadata is stale; regenerate before analysis: ' . $path);
        }
    }
    require $sdk . '/vendor/autoload.php';
    require dirname(__DIR__) . '/src/StaticAnalysis/MagoXmlMethods.php';
    $plugin = new MagoXmlMethods($inventory);
    $extension = new Extension(
        'd3r/xml-metadata-pilot',
        'D3R XML metadata pilot',
        '0.0.0',
        analyzerPlugins: [$plugin]
    );
    (new Worker($extension))->run();
} catch (Throwable $error) {
    // stdout belongs exclusively to the worker protocol.
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
