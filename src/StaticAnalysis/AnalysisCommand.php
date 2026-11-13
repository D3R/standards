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
        $analysisStarted = false;
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
            $paths = $this->sourcePaths($manifest, $options['path'] ?? [], $project);
            $patches = [$reportDirectory . '/models.mago.php'];
            $finderSource = $vendor . '/d3r/core/library/d3r_core/D3R/Model.php';
            $contracts = json_decode(file_get_contents($this->package . '/resources/mago/framework-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
            $collectionSource = dirname($finderSource) . '/Model/Collection.php';
            $finderContracts = 'native-source-fallback';
            if (
                is_file($finderSource) && hash_file('sha256', $finderSource) === $contracts['finderSourceSha256']
                && is_file($collectionSource) && hash_file('sha256', $collectionSource) === $contracts['collectionSourceSha256']
            ) {
                $patches[] = $this->package . '/resources/static-analysis/finders.stub.php';
                $finderContracts = 'reviewed-source-pair';
                $inventory['inputs'][$collectionSource] = $contracts['collectionSourceSha256'];
                file_put_contents($reportDirectory . '/inventory.json', $this->json($inventory));
            }
            $coverage['finderCollectionContracts'] = $finderContracts;
            file_put_contents($reportDirectory . '/discovery.json', $this->json($coverage));
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
            $analysisStarted = true;
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
                'discoveryGaps' => count($coverage['gaps']), 'metadataModels' => count($inventory['models']), 'finderCollectionContracts' => $finderContracts,
                'wallSeconds' => round(microtime(true) - $started, 3), 'magoExit' => $exit,
                'exit' => $coverage['gaps'] ? 2 : $findingExit];
            file_put_contents($reportDirectory . '/result.json', $this->json($result));
            $this->report($result, $diagnostics['issues'], $coverage['gaps'], $project, isset($options['github']));
            echo $this->json($result + ['reports' => $reportDirectory]);
            return $result['exit'];
        } catch (Throwable $error) {
            fwrite(STDERR, 'D3R analysis tooling error: ' . $error->getMessage() . "\n");
            $result = ['status' => 'tooling-failure', 'exit' => 2, 'message' => $error->getMessage()];
            if ($reportDirectory !== null && is_dir($reportDirectory)) {
                $stderr = $reportDirectory . '/mago.stderr';
                if ($analysisStarted && is_file($stderr)) {
                    $result['stderr'] = file_get_contents($stderr, false, null, max(0, filesize($stderr) - 12000), 12000);
                }
                file_put_contents($reportDirectory . '/result.json', $this->json($result));
            }
            $this->report($result, [], [], ($project ?? false) ?: getcwd(), isset($options['github']));
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
     * Explain failures inline, with bounded logs, annotations and a job summary.
     *
     * @param array $result Analysis status, counts and optional tooling error.
     * @param array $issues Mago diagnostics; at most 50 become annotations.
     * @param array $gaps Unresolved discovery cases; at most 50 are displayed.
     * @param string $project Absolute consuming-project root.
     * @param bool $github Whether GitHub reporting was explicitly requested.
     * @return void
     */
    private function report(array $result, array $issues, array $gaps, string $project, bool $github): void
    {
        if (!$github) {
            return;
        }
        $escapeData = static fn(string $text): string => str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $text
        );
        $escapeProperty = static fn(string $text): string => str_replace(
            [',', ':'],
            ['%2C', '%3A'],
            $escapeData($text)
        );
        $cell = static function (string $text) use ($project): string {
            $text = str_replace([$project . '/', "\r", "\n"], ['', ' ', ' '], $text);
            $text = htmlspecialchars(substr($text, 0, 800), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return str_replace(['|', '`'], ['&#124;', '&#96;'], $text);
        };
        $reason = match ($result['status']) {
            'clean' => 'Mago completed with no diagnostics or recorded discovery gaps.',
            'diagnostics' => 'Mago reported ' . $result['diagnostics'] . ' diagnostics.',
            'incomplete-discovery' => 'D3R discovery is incomplete: ' . $result['discoveryGaps'] . ' gaps; '
                . $result['diagnostics'] . ' Mago diagnostics. See the discovery cases below.',
            default => 'Mago tooling failed: ' . ($result['message'] ?? 'Unknown error.'),
        };
        $reason = substr(str_replace(["\r", "\n"], ' ', $reason), 0, 1000);
        if ($result['exit'] !== 0) {
            echo '::error title=D3R Mago result::' . $escapeData($reason) . "\n";
        }
        $output = getenv('GITHUB_OUTPUT');
        if ($output) {
            file_put_contents($output, 'reason=' . $reason . "\nstatus=" . $result['status'] . "\n", FILE_APPEND);
        }
        $findings = [];
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
                $line = $span['start']['line'] + 1;
                $url = $this->githubSourceUrl($file, $line);
                $message = substr($issue['message'], 0, 800);
                $location = str_replace(['[', ']'], ['&#91;', '&#93;'], $cell($file . '#L' . $line));
                if ($url !== null) {
                    $message .= ' Source: ' . $url;
                    $location = '[' . $location . '](' . $url . ')';
                }
                $findings[] = '| ' . $cell($issue['code']) . ' | ' . $location . ' | ' . $cell($issue['message']) . ' |';
                echo '::warning file=' . $escapeProperty($file) . ',line=' . $line
                    . ',title=' . $escapeProperty($issue['code']) . '::' . $escapeData($message) . "\n";
                break;
            }
        }
        $lines = ['### D3R Mago pilot', '', '**' . $cell($reason) . '**', '',
            'Status: ' . $cell($result['status']) . '; exit ' . $result['exit'] . '.', ''];
        if (isset($result['metadataModels'])) {
            $lines[] = $result['metadataModels'] . ' models with generated metadata. Counts are findings, not confirmed bugs.';
            $lines[] = 'Finder/collection contracts: ' . $cell($result['finderCollectionContracts'] ?? 'native-source-fallback') . '.';
        }
        $counts = array_count_values(array_column($issues, 'code'));
        arsort($counts);
        if ($counts) {
            $lines = array_merge($lines, ['', '#### Diagnostic categories (top 20)', '', '| Category | Count |', '| --- | ---: |']);
            foreach (array_slice($counts, 0, 20, true) as $code => $count) {
                $lines[] = '| ' . $cell($code) . ' | ' . $count . ' |';
            }
            $lines[] = '';
            $lines[] = 'Up to 50 individual findings appear as file/line annotations in the analysis step.';
        }
        if ($findings) {
            $lines = array_merge($lines, ['', '#### Findings (first 50)', '',
                '| Category | Source | Message |', '| --- | --- | --- |'], $findings);
        }
        if ($gaps) {
            $lines = array_merge($lines, ['', '#### Discovery gaps (first 50 of ' . count($gaps) . ')', '',
                '| Provider or model | Reason |', '| --- | --- |']);
            foreach (array_slice($gaps, 0, 50) as $gap) {
                $lines[] = '| ' . $cell($gap['provider'] ?? $gap['model'] ?? 'Discovery') . ' | ' . $cell($gap['reason']) . ' |';
            }
        }
        if (!empty($result['stderr'])) {
            $lines[] = "\n#### Tool stderr (last 12 KB)\n\n<pre>" . htmlspecialchars(
                $result['stderr'],
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '</pre>';
        }
        $lines[] = "\nOutput is capped. Full reports remain on the runner only unless artifact upload is explicitly enabled.";
        $text = implode("\n", $lines) . "\n";
        echo "::group::D3R Mago failure details and discovery coverage\n";
        // Source-derived text must not be interpreted as additional workflow commands.
        $token = bin2hex(random_bytes(16));
        echo '::stop-commands::' . $token . "\n" . $text . '::' . $token . "::\n::endgroup::\n";
        $summary = getenv('GITHUB_STEP_SUMMARY');
        if ($summary) {
            file_put_contents($summary, $text, FILE_APPEND);
        }
    }

    /**
     * Link to the exact checkout revision supplied by Actions, including PR merge commits.
     *
     * @param string $file Project-relative source path.
     * @param int $line One-based diagnostic line.
     * @return ?string Encoded source permalink, or null outside a valid Actions context.
     */
    private function githubSourceUrl(string $file, int $line): ?string
    {
        $server = rtrim(getenv('GITHUB_SERVER_URL') ?: 'https://github.com', '/');
        $repository = getenv('GITHUB_REPOSITORY') ?: '';
        $revision = getenv('D3R_ANALYSIS_SOURCE_REVISION') ?: (getenv('GITHUB_SHA') ?: '');
        $parts = explode('/', $file);
        if (
            !preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]+)?$~D', $server)
            || !preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~D', $repository)
            || !preg_match('/^[a-f0-9]{40}$/iD', $revision)
            || array_intersect($parts, ['', '.', '..']) || $line < 1
        ) {
            return null;
        }
        return $server . '/' . $repository . '/blob/' . $revision . '/'
            . implode('/', array_map('rawurlencode', $parts)) . '#L' . $line;
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
