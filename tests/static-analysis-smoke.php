<?php

declare(strict_types=1);

// Standalone integration fixture: no project loader, application bootstrap or database.
$package = dirname(__DIR__);
$cache = $argv[1] ?? null;
if ($cache === null) {
    fwrite(STDERR, "Usage: php tests/static-analysis-smoke.php /path/to/tool-cache\n");
    exit(2);
}
$fixture = sys_get_temp_dir() . '/d3r-analysis-fixture-' . bin2hex(random_bytes(6));
mkdir($fixture . '/src', 0775, true);
mkdir($fixture . '/vendor/composer', 0775, true);
file_put_contents($fixture . '/composer.json', json_encode(['name' => 'fixture/analysis',
    'autoload' => ['psr-4' => ['Fixture\\' => 'src/']]], JSON_PRETTY_PRINT));
$files = [
    'D3R_Model.php' => '<?php abstract class D3R_Model { public static function getXmlSources(): array { return []; } }',
    'D3R_Model_Field_Text.php' => '<?php class D3R_Model_Field_Text { public function getLength(): int { return 1; } public function expose($args = false) { return $this; } }',
    'Provider.php' => '<?php namespace Fixture; class Provider { public function getModelList() { return ["Product" => Product::class]; } }',
    'Product.php' => '<?php namespace Fixture; file_put_contents(__DIR__ . "/EXECUTED", "bad"); class Product extends \\D3R_Model { public function __get($name): mixed { return null; } public function __call($name, $args): mixed { return null; } }',
    'Control.php' => '<?php namespace Fixture; function valid(Product $product): int { return $product->title()->getLength(); }',
];
foreach ($files as $name => $contents) {
    file_put_contents($fixture . '/src/' . $name, $contents);
}
file_put_contents($fixture . '/src/Product.xml', '<model><fieldset><field><name>Title</name><dbname>title</dbname><type>Text</type></field><field><name>Display name &amp; Value</name><type>Text</type></field></fieldset></model>');
file_put_contents($fixture . '/vendor/composer/autoload_classmap.php', '<?php return ["D3R_Model" => $baseDir . "/src/D3R_Model.php", "D3R_Model_Field_Text" => $baseDir . "/src/D3R_Model_Field_Text.php"];');
file_put_contents($fixture . '/vendor/composer/autoload_psr4.php', '<?php return ["Fixture\\\\" => [$baseDir . "/src"]];');
file_put_contents($fixture . '/vendor/composer/autoload_namespaces.php', '<?php return [];');
file_put_contents($fixture . '/vendor/d3r.php', '<?php D3R::container()->get("providers")->register(["Fixture\\\\Provider"]);');

/**
 * Run a complete CLI process and check its real exit status.
 *
 * @param string $package Package input for this operation.
 * @param string $fixture Fixture input for this operation.
 * @param string $cache Cache input for this operation.
 * @param int $expected Expected input for this operation.
 * @return array Result of the documented operation.
 */
$runCheck = static function (string $package, string $fixture, string $cache, int $expected, bool $focused = true): array {
    $command = [PHP_BINARY, $package . '/bin/d3r-analysis', 'check', '--project=' . $fixture,
        '--cache-dir=' . $cache, '--report-dir=' . $fixture . '/reports', '--github'];
    if ($focused) {
        $command[] = '--path=src/Control.php';
    }
    $environment = getenv();
    $environment['GITHUB_STEP_SUMMARY'] = $fixture . '/summary.md';
    $environment['GITHUB_OUTPUT'] = $fixture . '/outputs';
    file_put_contents($environment['GITHUB_STEP_SUMMARY'], '');
    file_put_contents($environment['GITHUB_OUTPUT'], '');
    $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, $environment);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    if ($exit !== $expected || file_exists($fixture . '/src/EXECUTED')) {
        throw new RuntimeException('Unexpected CLI result or executed source: ' . $stdout);
    }
    $result = json_decode(file_get_contents($fixture . '/reports/result.json'), true, 512, JSON_THROW_ON_ERROR);
    $summary = file_get_contents($environment['GITHUB_STEP_SUMMARY']);
    $outputs = file_get_contents($environment['GITHUB_OUTPUT']);
    if (!str_contains($summary, $result['status']) || !str_contains($outputs, 'reason=')) {
        throw new RuntimeException('Inline status or action failure reason is missing.');
    }
    if ($result['status'] === 'incomplete-discovery' && !str_contains($summary, 'getModelList')) {
        throw new RuntimeException('Discovery failure has no inline explanation.');
    }
    if (str_contains($summary, 'Download the report artifact')) {
        throw new RuntimeException('Failure explanation still requires an artifact.');
    }
    return $result;
};

$valid = $runCheck($package, $fixture, $cache, 0);
$inventory = json_decode(file_get_contents($fixture . '/reports/inventory.json'), true, 512, JSON_THROW_ON_ERROR);
if (!isset($inventory['models']['Fixture\\Product']['supported']['display_name_and_value'])) {
    throw new RuntimeException('D3R implicit field name or arbitrary XML container root was not discovered.');
}
file_put_contents($fixture . '/src/Control.php', '<?php namespace Fixture; function invalid(Product $product): mixed { return $product->titel(); }');
$invalid = $runCheck($package, $fixture, $cache, 1);
$wholeSource = $runCheck($package, $fixture, $cache, 1, false);
if ($valid['diagnostics'] !== 0 || $invalid['diagnostics'] === 0) {
    throw new RuntimeException('Known XML methods or typo detection regressed.');
}
file_put_contents($fixture . '/src/Provider.php', '<?php namespace Fixture; class Provider { public function getModelList() { return unknownDynamicModels(); } }');
$partial = $runCheck($package, $fixture, $cache, 2);
if ($partial['status'] !== 'incomplete-discovery') {
    throw new RuntimeException('Partial discovery was presented as complete.');
}
file_put_contents($fixture . '/reports/mago.stderr', 'STALE ERROR FROM AN EARLIER RUN');
file_put_contents($fixture . '/vendor/composer/autoload_psr4.php', '<?php return INVALID;');
$toolingFailure = $runCheck($package, $fixture, $cache, 2);
if ($toolingFailure['status'] !== 'tooling-failure' || isset($toolingFailure['stderr'])) {
    throw new RuntimeException('Tooling failure was misclassified or reused stale process errors.');
}
echo "PASS: clean XML method, typo finding, incomplete-discovery exit, inline failure reporting, and source-execution sentinel.\n";
echo 'Fixture/reports retained at ' . $fixture . "\n";
