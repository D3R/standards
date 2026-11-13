<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use RuntimeException;
use Throwable;

/** Shared local/CI entry point; never bootstraps the project or uses its Composer loader. */
final class AnalysisCommand
{
    /**
     * Locate package-owned source and pinned tooling resources.
     *
     * @param string $package Absolute standards package root.
     */
    public function __construct(private string $package)
    {
    }

    /**
     * Run one explicit tooling operation, retaining discovery and analysis failure boundaries.
     *
     * @param array $arguments Arguments input for this operation.
     * @return int Result of the documented operation.
     */
    public function run(array $arguments): int
    {
        $reportDirectory = null;
        try {
            [$command, $options] = $this->arguments($arguments);
            if (in_array($command, ['help', '--help', '-h'], true)) {
                echo "D3R Mago pilot: d3r-analysis <doctor|setup|discover|check> [options]\n";
                echo "  --project=PATH       Composer project root (default: current directory)\n";
                echo "  --cache-dir=PATH     Tool cache (default: .cache/d3r-analysis)\n";
                echo "  --report-dir=PATH    Reports (default: cache/reports)\n";
                echo "  --path=PATH          Source file/directory; repeat to select a focused scan\n";
                echo "  --github             Emit capped annotations and a job summary\n";
                echo "Exit: 0 clean, 1 diagnostics, 2 tooling failure or incomplete discovery.\n";
                return 0;
            }
            $project = realpath($options['project'] ?? getcwd());
            if ($project === false || !is_file($project . '/composer.json')) {
                throw new RuntimeException('Run from a Composer project root or pass --project=PATH.');
            }
            $manifest = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $vendor = $this->absolute($manifest['config']['vendor-dir'] ?? 'vendor', $project);
            $cache = $this->absolute($options['cache-dir'] ?? '.cache/d3r-analysis', $project);
            $reportDirectory = $this->absolute($options['report-dir'] ?? $cache . '/reports', $project);
            $toolchain = new Toolchain($this->package, $cache);
            if ($command === 'doctor') {
                $status = $toolchain->status();
                $status['project'] = $project;
                $status['providersReady'] = is_file($vendor . '/d3r.php');
                $status['ready'] = $status['sdkReady'] && $status['binaryReady'] && $status['providersReady'];
                echo $this->json($status);
                if (!$status['ready']) {
                    fwrite(STDERR, "Run vendor/bin/d3r-analysis setup after the project's normal Composer install.\n");
                }
                return $status['ready'] ? 0 : 2;
            }
            $toolchain->setup();
            if ($command === 'setup') {
                echo "Pinned Mago/SDK/parser toolchain is ready. No application loader was used.\n";
                return 0;
            }
            // This is exclusively the locked tooling project, not the application's loader.
            require_once $toolchain->sdk() . '/vendor/autoload.php';
            Toolchain::directory($reportDirectory);
            $discovery = new ProjectDiscovery($project, $vendor);
            $builder = new ProjectInventory($discovery, $project, $vendor);
            $coverage = $builder->discover();
            if ($command === 'discover') {
                file_put_contents($reportDirectory . '/discovery.json', $this->json($coverage));
                echo $this->json(['providers' => count($coverage['providers']),
                    'aliasCandidates' => count($coverage['aliasCandidates']), 'models' => count($coverage['models']),
                    'gaps' => count($coverage['gaps']), 'report' => $reportDirectory . '/discovery.json']);
                return $coverage['gaps'] ? 2 : 0;
            }
            $inventory = $builder->generate($coverage, $reportDirectory);
            file_put_contents($reportDirectory . '/discovery.json', $this->json($coverage));
            $paths = $this->sourcePaths($manifest, $options['path'] ?? [], $project);
            $patches = [$reportDirectory . '/models.mago.php'];
            $finderSource = $vendor . '/d3r/core/library/d3r_core/D3R/Model.php';
            $contracts = json_decode(file_get_contents($this->package . '/resources/mago/framework-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
            if (is_file($finderSource) && hash_file('sha256', $finderSource) === $contracts['finderSourceSha256']) {
                $patches[] = $this->package . '/resources/static-analysis/finders.stub.php';
            }
            $phpVersion = $manifest['config']['platform']['php'] ?? '8.2';
            if (!preg_match('/^(\d+\.\d+)/', $phpVersion, $match)) {
                throw new RuntimeException('Cannot determine target PHP version from Composer platform.php.');
            }
            $includes = array_values(array_filter($discovery->symbolPaths(), static function (string $include) use ($paths): bool {
                foreach ($paths as $source) {
                    if ($include === $source || (is_dir($source) && str_starts_with($include, $source . '/'))) {
                        return false;
                    }
                }
                return true;
            }));
            $configuration = 'version = "1.59.0"' . "\nphp-version = " . $this->toml($match[1]) . "\nthreads = 2\n[source]\n";
            $configuration .= 'paths = ' . $this->toml($paths) . "\nincludes = " . $this->toml($includes) . "\n";
            $configuration .= 'patches = ' . $this->toml($patches) . "\n[extension-hosts.d3r]\ncommand = ";
            $configuration .= $this->toml([PHP_BINARY, $this->package . '/tools/mago-metadata-worker.php',
                $toolchain->sdk(), $reportDirectory . '/inventory.json']) . "\nworkers = 1\n";
            file_put_contents($reportDirectory . '/mago.toml', $configuration);
            $toolCommand = [$toolchain->binary(), '--workspace', $project, '--config', $reportDirectory . '/mago.toml',
                '--colors', 'never', 'analyze', '--reporting-format', 'json'];
            $started = microtime(true);
            $process = proc_open($toolCommand, [0 => STDIN, 1 => ['file', $reportDirectory . '/mago.json', 'w'],
                2 => ['file', $reportDirectory . '/mago.stderr', 'w']], $pipes, $project);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start Mago.');
            }
            $exit = proc_close($process);
            $raw = file_get_contents($reportDirectory . '/mago.json');
            if (trim($raw) === '') {
                throw new RuntimeException('Mago produced no JSON report; inspect mago.stderr (including source-discovery errors).');
            }
            $diagnostics = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($diagnostics['issues']) || !in_array($exit, [0, 1], true)) {
                throw new RuntimeException('Mago failed; inspect mago.stderr and mago.json.');
            }
            $findingExit = $diagnostics['issues'] ? 1 : 0;
            $result = ['status' => $coverage['gaps'] ? 'incomplete-discovery' : ($findingExit ? 'diagnostics' : 'clean'),
                'tool' => 'Mago 1.59.0', 'diagnostics' => count($diagnostics['issues']),
                'discoveryGaps' => count($coverage['gaps']), 'metadataModels' => count($inventory['models']),
                'wallSeconds' => round(microtime(true) - $started, 3), 'magoExit' => $exit,
                'exit' => $coverage['gaps'] ? 2 : $findingExit];
            file_put_contents($reportDirectory . '/result.json', $this->json($result));
            $this->report($result, $diagnostics['issues'], $project, isset($options['github']));
            echo $this->json($result + ['reports' => $reportDirectory]);
            return $result['exit'];
        } catch (Throwable $error) {
            fwrite(STDERR, 'D3R analysis tooling error: ' . $error->getMessage() . "\n");
            if ($reportDirectory !== null && is_dir($reportDirectory)) {
                file_put_contents($reportDirectory . '/result.json', $this->json(['status' => 'tooling-failure',
                    'exit' => 2, 'message' => $error->getMessage()]));
            }
            return 2;
        }
    }

    /**
     * Accept a small explicit option surface; reject typos rather than ignoring them.
     *
     * @param array $arguments Arguments input for this operation.
     * @return array Result of the documented operation.
     */
    private function arguments(array $arguments): array
    {
        $command = array_shift($arguments) ?? 'help';
        if (!in_array($command, ['doctor', 'setup', 'discover', 'check', 'help', '--help', '-h'], true)) {
            throw new RuntimeException('Unknown command: ' . $command);
        }
        $options = [];
        foreach ($arguments as $argument) {
            if ($argument === '--github') {
                $options['github'] = true;
                continue;
            }
            if (!preg_match('/^--(project|cache-dir|report-dir|path)=(.+)$/D', $argument, $match)) {
                throw new RuntimeException('Unknown option (use --name=value): ' . $argument);
            }
            if ($match[1] === 'path') {
                $options['path'][] = $match[2];
            } else {
                $options[$match[1]] = $match[2];
            }
        }
        return [$command, $options];
    }

    /**
     * Default to root Composer source roots; paths outside this checkout are not scan targets.
     *
     * @param array $manifest Manifest input for this operation.
     * @param array $requested Requested input for this operation.
     * @param string $project Absolute consuming-project root.
     * @return array Result of the documented operation.
     */
    private function sourcePaths(array $manifest, array $requested, string $project): array
    {
        if (!$requested) {
            foreach (['psr-4', 'psr-0'] as $kind) {
                foreach ($manifest['autoload'][$kind] ?? [] as $paths) {
                    $requested = array_merge($requested, (array) $paths);
                }
            }
            $requested = array_merge($requested, $manifest['autoload']['classmap'] ?? []);
        }
        if (!$requested && is_dir($project . '/src')) {
            $requested = ['src'];
        }
        $resolved = [];
        foreach ($requested as $path) {
            $absolute = realpath($this->absolute($path, $project));
            if ($absolute === false || !str_starts_with($absolute, $project . '/')) {
                throw new RuntimeException('Source path must exist inside the project: ' . $path);
            }
            $resolved[$absolute] = true;
        }
        if (!$resolved) {
            throw new RuntimeException('No source roots found; pass --path=src or the appropriate project source directory.');
        }
        return array_keys($resolved);
    }

    /**
     * Emit read-only workflow annotations and a short summary; retain full findings in artifacts.
     *
     * @param array $result Result input for this operation.
     * @param array $issues Issues input for this operation.
     * @param string $project Absolute consuming-project root.
     * @param bool $github Github input for this operation.
     * @return void
     */
    private function report(array $result, array $issues, string $project, bool $github): void
    {
        if (!$github) {
            return;
        }
        foreach (array_slice($issues, 0, 50) as $issue) {
            foreach ($issue['annotations'] ?? [] as $annotation) {
                if ($annotation['kind'] !== 'Primary') {
                    continue;
                }
                $span = $annotation['span'];
                $file = $span['file_id']['path'];
                if (!str_starts_with($file, $project . '/')) {
                    continue;
                }
                $file = substr($file, strlen($project) + 1);
                $escape = static fn(string $text): string => str_replace(
                    ['%', "\r", "\n", ',', ':'],
                    ['%25', '%0D', '%0A', '%2C', '%3A'],
                    $text
                );
                echo '::warning file=' . $escape($file) . ',line=' . ($span['start']['line'] + 1)
                    . ',title=' . $escape($issue['code']) . '::' . $escape($issue['message']) . "\n";
                break;
            }
        }
        $summary = getenv('GITHUB_STEP_SUMMARY');
        if ($summary) {
            $text = "### D3R Mago pilot\n\nStatus: **" . $result['status'] . "**\n\n";
            $text .= $result['diagnostics'] . ' diagnostics; ' . $result['discoveryGaps'] . ' discovery gaps; '
                . $result['metadataModels'] . " models with generated metadata.\n\n";
            $text .= "Download the report artifact for complete diagnostics and discovery provenance.\n";
            file_put_contents($summary, $text, FILE_APPEND);
        }
    }

    /**
     * Resolve a CLI path without changing the process working directory.
     *
     * @param string $path Path input for this operation.
     * @param string $project Absolute consuming-project root.
     * @return string Result of the documented operation.
     */
    private function absolute(string $path, string $project): string
    {
        return str_starts_with($path, '/') ? $path : $project . '/' . $path;
    }

    /**
     * Encode string arrays using the JSON-compatible subset of TOML.
     *
     * @param mixed $value Value input for this operation.
     * @return string Result of the documented operation.
     */
    private function toml(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Encode readable machine reports with a trailing newline.
     *
     * @param array $value Value input for this operation.
     * @return string Result of the documented operation.
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
