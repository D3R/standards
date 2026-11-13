<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use RuntimeException;

/** Install pinned, isolated tooling without loading or changing a consumer application. */
final class Toolchain
{
    /**
     * Package and writable cache roots; neither is an application autoloader.
     *
     * @param string $package Absolute standards package root.
     * @param string $cache Writable tool-owned cache directory.
     */
    public function __construct(private string $package, private string $cache)
    {
    }

    /**
     * Resolve the SDK/parser environment by the checked-in toolchain lock hash.
     *
     * @return string Result of the documented operation.
     */
    public function sdk(): string
    {
        $hash = hash_file('sha256', $this->package . '/resources/mago/toolchain/composer.lock');
        return $this->cache . '/tools/sdk-' . substr($hash, 0, 16);
    }

    /**
     * Resolve the pinned binary for this operating system and CPU.
     *
     * @return string Result of the documented operation.
     */
    public function binary(): string
    {
        $asset = $this->asset();
        return $this->cache . '/tools/' . basename($asset['name'], '.tar.gz') . '/mago';
    }

    /**
     * Report missing prerequisites without downloading or running project code.
     *
     * @return array Result of the documented operation.
     */
    public function status(): array
    {
        return [
            'php' => PHP_VERSION,
            'sdkReady' => is_file($this->sdk() . '/vendor/autoload.php'),
            'binaryReady' => is_executable($this->binary()),
            'sdk' => $this->sdk(),
            'binary' => $this->binary(),
        ];
    }

    /**
     * Install public tool dependencies with plugins/scripts disabled and a verified native binary.
     *
     * @return void
     */
    public function setup(): void
    {
        $sdk = $this->sdk();
        self::directory($sdk);
        $lock = fopen($this->cache . '/tools/setup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the toolchain cache.');
        }
        try {
            if (!is_file($sdk . '/vendor/autoload.php')) {
                foreach (['composer.json', 'composer.lock'] as $file) {
                    if (!copy($this->package . '/resources/mago/toolchain/' . $file, $sdk . '/' . $file)) {
                        throw new RuntimeException('Cannot prepare isolated toolchain: ' . $file);
                    }
                }
                $exit = self::run([
                    'composer', 'install', '--working-dir=' . $sdk, '--no-dev', '--no-plugins',
                    '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist',
                ]);
                if ($exit !== 0) {
                    throw new RuntimeException('Isolated toolchain installation failed.');
                }
            }
            if (!is_executable($this->binary())) {
                $this->installBinary();
            }
            $process = proc_open([$this->binary(), '--version'], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot verify Mago version.');
            }
            $version = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            if (proc_close($process) !== 0 || !preg_match('/\b1\.59\.0\b/', $version)) {
                throw new RuntimeException('Cached Mago binary has an unexpected version.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Download one pinned official release and extract only named binary/licence members.
     *
     * @return void
     */
    private function installBinary(): void
    {
        $asset = $this->asset();
        $directory = dirname($this->binary());
        self::directory($directory);
        $archive = $directory . '/download.tar.gz';
        $context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'D3R-Mago-pilot']]);
        $input = fopen($asset['browser_download_url'], 'rb', false, $context);
        $output = fopen($archive, 'wb');
        if ($input === false || $output === false) {
            throw new RuntimeException('Cannot download the pinned Mago release.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Mago release download was interrupted.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ('sha256:' . hash_file('sha256', $archive) !== $asset['digest']) {
            throw new RuntimeException('Mago release checksum mismatch; binary was not installed.');
        }
        $member = basename($asset['name'], '.tar.gz');
        if (
            self::run([
            'tar', '-xzf', $archive, '-C', $directory, '--strip-components=1',
            $member . '/mago', $member . '/LICENSE-MIT', $member . '/LICENSE-APACHE',
            ]) !== 0 || !chmod($this->binary(), 0755)
        ) {
            throw new RuntimeException('Cannot extract the verified Mago binary.');
        }
    }

    /**
     * Select only platforms whose official release digests are checked into this package.
     *
     * @return array Result of the documented operation.
     */
    private function asset(): array
    {
        $architecture = match (php_uname('m')) {
            'arm64', 'aarch64' => 'aarch64',
            'x86_64', 'amd64' => 'x86_64',
            default => throw new RuntimeException('Unsupported Mago CPU architecture.'),
        };
        $platform = match (PHP_OS_FAMILY) {
            'Darwin' => 'apple-darwin',
            'Linux' => 'unknown-linux-gnu',
            default => throw new RuntimeException('This pilot supports macOS and glibc Linux only.'),
        };
        $releases = json_decode(file_get_contents($this->package . '/resources/mago/releases.json'), true, 512, JSON_THROW_ON_ERROR);
        $name = 'mago-' . $releases['version'] . '-' . $architecture . '-' . $platform . '.tar.gz';
        foreach ($releases['assets'] as $asset) {
            if ($asset['name'] === $name && preg_match('/^sha256:[a-f0-9]{64}$/D', $asset['digest'])) {
                return $asset;
            }
        }
        throw new RuntimeException('No verified Mago release is configured for this platform.');
    }

    /**
     * Create a tool-owned output directory or stop before analysis.
     *
     * @param string $path Path input for this operation.
     * @return void
     */
    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create directory: ' . $path);
        }
    }

    /**
     * Run an argument-array command without shell interpolation or application initialization.
     *
     * @param array $command Command input for this operation.
     * @return int Result of the documented operation.
     */
    public static function run(array $command): int
    {
        $process = proc_open($command, [0 => STDIN, 1 => STDERR, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start tool process.');
        }
        return proc_close($process);
    }
}
