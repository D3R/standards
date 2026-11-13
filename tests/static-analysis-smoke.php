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
mkdir($fixture . '/tests', 0775, true);
mkdir($fixture . '/vendor/composer', 0775, true);
file_put_contents($fixture . '/composer.json', json_encode(['name' => 'fixture/analysis',
    'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
    'autoload-dev' => ['psr-4' => ['FixtureTests\\' => 'tests/']]], JSON_PRETTY_PRINT));
$files = [
    'D3R_Model.php' => '<?php abstract class D3R_Model { public static function getXmlSources(): array { return []; } }',
    'D3R_Model_Field_Text.php' => '<?php class D3R_Model_Field_Text { public function getLength(): int { return 1; } public function expose($args = false) { return $this; } }',
    'D3R_Model_Field_Date.php' => '<?php class D3R_Model_Field_Date { public function getYear(): int { return 2026; } public function expose($args = false) { return \'formatted\'; } }',
    'Provider.php' => '<?php namespace Fixture; class Provider { public function getModelList() { return ["Product" => Product::class]; } }',
    'Product.php' => '<?php namespace Fixture; file_put_contents(__DIR__ . "/EXECUTED", "bad"); /** Concrete source model. */ class Product extends \\D3R_Model { public int $display_name_and_value = 1; public function nativeValue(): int { return $this->display_name_and_value; } public function insideLabel(): int { return $this->title()->getLength(); } public function rawLabel(): mixed { return $this->title; } public function year(): int { return $this->release_date()->getYear(); } public function __get($name): mixed { return null; } public function __call($name, $args): mixed { return null; } }',
    'Control.php' => '<?php namespace Fixture; function valid(Product $product): int { return $product->title()->getLength(); }',
];
foreach ($files as $name => $contents) {
    file_put_contents($fixture . '/src/' . $name, $contents);
}
file_put_contents($fixture . '/src/Product.xml', '<model><fieldset><field><name>Title</name><dbname>title</dbname><type>Text</type></field><field><name>Display name &amp; Value</name><type>Text</type></field><field><name>Release Date</name><type>Date</type></field></fieldset></model>');
file_put_contents($fixture . '/vendor/composer/autoload_classmap.php', '<?php return ["D3R_Model" => $baseDir . "/src/D3R_Model.php", "D3R_Model_Field_Text" => $baseDir . "/src/D3R_Model_Field_Text.php", "D3R_Model_Field_Date" => $baseDir . "/src/D3R_Model_Field_Date.php"];');
file_put_contents($fixture . '/vendor/composer/autoload_psr4.php', '<?php return ["Fixture\\\\" => [$baseDir . "/src"], "FixtureTests\\\\" => [$baseDir . "/tests"]];');
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
$runCheck = static function (string $package, string $fixture, string $cache, int $expected, bool $focused = true, string $path = 'src/Control.php'): array {
    $command = [PHP_BINARY, $package . '/bin/d3r-analysis', 'check', '--project=' . $fixture,
        '--cache-dir=' . $cache, '--report-dir=' . $fixture . '/reports', '--github'];
    if ($focused) {
        $command[] = '--path=' . $path;
    }
    $environment = getenv();
    $environment['GITHUB_SERVER_URL'] = 'https://github.com';
    $environment['GITHUB_REPOSITORY'] = 'D3R/report-fixture';
    $environment['GITHUB_SHA'] = str_repeat('b', 40);
    $environment['D3R_ANALYSIS_SOURCE_REVISION'] = str_repeat('a', 40);
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
    if (!empty($result['diagnostics']) && !str_contains($summary, 'https://github.com/D3R/report-fixture/blob/' . str_repeat('a', 40) . '/')) {
        throw new RuntimeException('Diagnostic summary has no commit-pinned source links.');
    }
    return $result;
};

file_put_contents($fixture . '/tests/Double.php', '<?php namespace FixtureTests; class Double {} class_alias(Double::class, \'Fixture\\\\Product\');');

$valid = $runCheck($package, $fixture, $cache, 0);
if ($valid['finderCollectionContracts'] !== 'native-source-fallback') {
    throw new RuntimeException('An unreviewed framework source received finder patches.');
}
$modelBody = $runCheck($package, $fixture, $cache, 0, true, 'src/Product.php');
file_put_contents($fixture . '/src/Report #1.php', "<?php\nnamespace Fixture;\nfunction reportLocation(Product \$product): mixed { return \$product->titel(); }\n");
$reportLocation = $runCheck($package, $fixture, $cache, 1, true, 'src/Report #1.php');
$linkedSummary = file_get_contents($fixture . '/summary.md');
if (!str_contains($linkedSummary, '/blob/' . str_repeat('a', 40) . '/src/Report%20%231.php#L3')) {
    throw new RuntimeException('Source links lost path encoding or one-based diagnostic lines.');
}
$originalModel = file_get_contents($fixture . '/src/Product.php');
file_put_contents($fixture . '/src/Product.php', str_replace('return $this->title;', 'return $this->titel;', $originalModel));
$propertyTypo = $runCheck($package, $fixture, $cache, 1, true, 'src/Product.php');
file_put_contents($fixture . '/src/Product.php', $originalModel);
$runCheck($package, $fixture, $cache, 0);
$inventory = json_decode(file_get_contents($fixture . '/reports/inventory.json'), true, 512, JSON_THROW_ON_ERROR);
if (!isset($inventory['models']['Fixture\\Product']['supported']['display_name_and_value'])) {
    throw new RuntimeException('D3R implicit field name or arbitrary XML container root was not discovered.');
}
// Provider inheritance, parent merges and pure declaration predicates.
file_put_contents($fixture . '/src/BaseProvider.php', '<?php namespace Fixture; class BaseProvider { protected $models = ["Product" => Product::class]; public function getModelList() { return $this->models; } }');
file_put_contents($fixture . '/src/EnabledInterface.php', '<?php namespace Fixture; interface EnabledInterface {}');
file_put_contents($fixture . '/src/Provider.php', '<?php namespace Fixture; class Provider extends BaseProvider { public function getModelList() { if ($this->enabled()) { $this->models["Extra"] = Product::class; } return array_merge(parent::getModelList(), ["Merged" => Product::class]); } protected function enabled() { return interface_exists(EnabledInterface::class) && class_exists(Product::class); } }');
$providers = $runCheck($package, $fixture, $cache, 0);
$discovered = json_decode(file_get_contents($fixture . '/reports/discovery.json'), true, 512, JSON_THROW_ON_ERROR);
if (!isset($discovered['aliasCandidates']['Extra'], $discovered['aliasCandidates']['Merged'])) {
    throw new RuntimeException('Declarative parent merge or installed-interface conditional was not resolved.');
}
// Parent contracts use their own XML graph; child-only fields never leak upward.
file_put_contents($fixture . '/src/BaseProduct.php', '<?php namespace Fixture; class BaseProduct extends \\D3R_Model { public function __get($name): mixed { return null; } public function __call($name, $args): mixed { return null; } }');
file_put_contents($fixture . '/src/BaseProduct.xml', '<fields><field><name>Inherited</name><type>Text</type></field></fields>');
file_put_contents($fixture . '/src/Product.php', str_replace('extends \\D3R_Model', 'extends BaseProduct', $originalModel));
file_put_contents($fixture . '/src/Control.php', '<?php namespace Fixture; function inherited(BaseProduct $product): int { return $product->inherited()->getLength(); }');
$parentContract = $runCheck($package, $fixture, $cache, 0);
file_put_contents($fixture . '/src/Control.php', '<?php namespace Fixture; function childOnly(BaseProduct $product): mixed { return $product->title(); }');
$childOnly = $runCheck($package, $fixture, $cache, 1);
// A literal field alias with a factory is not proof of the default concrete field type.
$providerSource = file_get_contents($fixture . '/src/Provider.php');
file_put_contents($fixture . '/src/Provider.php', '<?php namespace Fixture; class Provider { public function getModelList() { return ["Product" => Product::class]; } public function registerServices($container) { $container->add("D3R_Model_Field_Text", function () { file_put_contents(__DIR__ . "/EXECUTED", "bad"); return new \\D3R_Model_Field_Text(); }); } }');
file_put_contents($fixture . '/src/Control.php', '<?php namespace Fixture; function factoryField(Product $product): mixed { return $product->title(); }');
$factoryField = $runCheck($package, $fixture, $cache, 0);
$factoryInventory = json_decode(file_get_contents($fixture . '/reports/inventory.json'), true, 512, JSON_THROW_ON_ERROR);
if ($factoryInventory['models']['Fixture\\Product']['supported']['title']['fieldClassReliable'] !== false) {
    throw new RuntimeException('A field factory was incorrectly treated as a proven default class.');
}
file_put_contents($fixture . '/src/Provider.php', $providerSource);
// Refuse ambiguous lexical self binding instead of using the child's shadowing map.
$baseProviderSource = file_get_contents($fixture . '/src/BaseProvider.php');
file_put_contents($fixture . '/src/BaseProvider.php', '<?php namespace Fixture; class BaseProvider { protected static $models = ["Product" => Product::class]; public function getModelList() { return self::$models; } }');
file_put_contents($fixture . '/src/Provider.php', '<?php namespace Fixture; class Provider extends BaseProvider { protected static $models = []; }');
$lexical = $runCheck($package, $fixture, $cache, 2);
file_put_contents($fixture . '/src/Provider.php', $providerSource);
file_put_contents($fixture . '/src/BaseProvider.php', $baseProviderSource);
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
// Exercise the real finder patch separately, without weakening the application's source-hash guard.
$finderRoot = $fixture . '/finder';
mkdir($finderRoot);
file_put_contents($finderRoot . '/Declarations.php', '<?php class D3R_Model { public static function findById($id) {} public static function findFirstById($id) {} public static function findFirst($whereOrId = "all", $params = []) {} public static function findFirstBySql($sql, $params = []) {} public static function findBySql($sql, $params = []) {} public static function getCollection() {} } class D3R_Model_Collection implements ArrayAccess, IteratorAggregate, Countable { public function __construct(D3R_Model $model) {} public function getIterator(): Traversable {} public function toArray() {} public function offsetGet($key) {} public function offsetSet($key, $value): void {} public function offsetUnset($key): void {} public function offsetExists($key): bool {} public function count(): int {} public function current() {} public function firstByField($field, $value) {} } class FinderProduct extends D3R_Model { public function label(): int { return 1; } }');
$runFinder = static function (string $source, int $expected) use ($finderRoot, $package, $cache): array {
    file_put_contents($finderRoot . '/Control.php', '<?php ' . $source);
    file_put_contents($finderRoot . '/mago.toml', "php-version = \"8.2\"\n[source]\npaths = [\"Control.php\"]\nincludes = [\"Declarations.php\"]\npatches = ["
        . json_encode($package . '/resources/static-analysis/finders.stub.php', JSON_UNESCAPED_SLASHES) . "]\n");
    $release = json_decode(file_get_contents($package . '/resources/mago/releases.json'), true, 512, JSON_THROW_ON_ERROR);
    $matches = glob($cache . '/tools/mago-' . $release['version'] . '-*/mago');
    if (count($matches) !== 1) {
        throw new RuntimeException('Finder fixture needs exactly one installed pinned Mago binary.');
    }
    $process = proc_open([$matches[0], '--workspace', $finderRoot, '--config', $finderRoot . '/mago.toml', '--colors', 'never',
        'analyze', '--reporting-format', 'json'], [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $finderRoot);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if ($exit !== $expected) {
        throw new RuntimeException('Unexpected finder contract result: ' . $raw);
    }
    return $report['issues'];
};
$runFinder('function first(): int { $p = FinderProduct::findFirst(); if ($p === false) { return 0; } return $p->label(); } function sql(): int { $p = FinderProduct::findFirstBySql("sql"); if ($p === false) { return 0; } return $p->label(); } function scalar(): int { $p = FinderProduct::findFirstById(1); if ($p === false) { return 0; } return $p->label(); }', 0);
$runFinder('function broad(D3R_Model_Collection $items): void {} function passCollection(): void { broad(FinderProduct::getCollection()); } function items(): int { $items = FinderProduct::findBySql("sql"); if ($items === false) { return 0; } foreach ($items as $item) { if ($item !== false) { return $item->label(); } } return 0; } function offset(): int { $item = FinderProduct::getCollection()[0]; if ($item === false) { return 0; } return $item->label(); } function constructed(): int { $items = new D3R_Model_Collection(new FinderProduct()); $item = $items->current(); if ($item === false) { return 0; } return $item->label(); }', 0);
$runFinder('function typo(): mixed { $items = FinderProduct::getCollection(); foreach ($items as $item) { if ($item !== false) { return $item->lable(); } } return null; }', 1);
$finderIssues = $runFinder('function unsafe(): int { return FinderProduct::findFirst()->label(); } function collection(): int { $p = FinderProduct::findFirstById([1]); if ($p === false) { return 0; } return $p->label(); }', 1);
$finderCodes = array_column($finderIssues, 'code');
if (!in_array('invalid-method-access', $finderCodes, true) || !in_array('non-existent-method', $finderCodes, true)) {
    throw new RuntimeException('Finder false branch or array collection distinction was lost: ' . json_encode($finderCodes));
}
echo "PASS: XML methods/properties, parent boundaries, finder contracts, typo findings, incomplete-discovery exit, inline reporting, and source-execution sentinel.\n";
echo 'Fixture/reports retained at ' . $fixture . "\n";
