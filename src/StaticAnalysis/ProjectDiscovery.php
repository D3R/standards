<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use Throwable;

/** Source inventory, deliberately not an executable PHP evaluator. */
final class ProjectDiscovery
{
    private $parser;
    private NodeFinder $finder;
    private array $classmap;
    private array $psr4;
    private array $psr0;
    private array $cache = [];
    private array $parsedFiles = [];
    private array $inputHashes = [];
    private array $devPaths = [];

    /**
     * Read generated maps as syntax trees, not as PHP includes.
     *
     * @param string $project Absolute consuming-project root.
     * @param string $vendor Installed Composer vendor directory.
     */
    public function __construct(private string $project, private string $vendor)
    {
        $manifest = json_decode(file_get_contents($project . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['psr-4', 'psr-0', 'classmap'] as $kind) {
            foreach ($manifest['autoload-dev'][$kind] ?? [] as $paths) {
                foreach ((array) $paths as $path) {
                    $resolved = realpath($project . '/' . $path);
                    if ($resolved !== false) {
                        $this->devPaths[] = $resolved;
                    }
                }
            }
        }
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
        $this->classmap = $this->composerMap($vendor . '/composer/autoload_classmap.php');
        $this->psr4 = $this->composerMap($vendor . '/composer/autoload_psr4.php');
        $this->psr0 = $this->composerMap($vendor . '/composer/autoload_namespaces.php');
        uksort($this->psr4, fn($left, $right) => strlen($right) <=> strlen($left));
    }

    /**
     * Parse only; no source declarations are loaded into the PHP process.
     *
     * @param string $file File input for this operation.
     * @return array Result of the documented operation.
     */
    public function parse(string $file): array
    {
        $contents = file_get_contents($file);
        $this->inputHashes[$file] = hash('sha256', $contents);
        return $this->parser->parse($contents) ?? [];
    }

    /**
     * Accept literals plus explicitly whitelisted map/class constants.
     *
     * @param Expr $expression Expression input for this operation.
     * @param string $file File input for this operation.
     * @param ?string $context Context input for this operation.
     * @return mixed Result of the documented operation.
     */
    public function literalValue(Expr $expression, string $file, ?string $context = null): mixed
    {
        $evaluator = new ConstExprEvaluator(function (Expr $node) use ($file, $context) {
            if ($node instanceof \PhpParser\Node\Scalar\MagicConst\Dir) {
                return dirname($file);
            }
            if ($node instanceof Expr\Variable && $node->name === 'vendorDir') {
                return $this->vendor;
            }
            if ($node instanceof Expr\Variable && $node->name === 'baseDir') {
                return $this->project;
            }
            if ($node instanceof Expr\ClassConstFetch && $node->name->toString() === 'class') {
                $name = $node->class->toString();
                return in_array(strtolower($name), ['self', 'static'], true) ? $context : $name;
            }
            throw new RuntimeException('Unsupported expression ' . $node->getType() . ' in ' . $file);
        });
        return $evaluator->evaluateSilently($expression);
    }

    /**
     * Read only the returned literal mapping from a standard Composer map.
     *
     * @param string $file File input for this operation.
     * @return array Result of the documented operation.
     */
    public function composerMap(string $file): array
    {
        $returns = $this->finder->findInstanceOf($this->parse($file), Stmt\Return_::class);
        if (count($returns) !== 1 || !$returns[0]->expr instanceof Expr\Array_) {
            throw new RuntimeException('Unexpected Composer map shape: ' . $file);
        }
        return $this->literalValue($returns[0]->expr, $file);
    }

    /**
     * Resolve a class declaration through existing Composer classmap/PSR-4 data.
     *
     * @param string $class Class input for this operation.
     * @return array Result of the documented operation.
     */
    public function declaration(string $class): array
    {
        $class = ltrim($class, '\\');
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }
        $file = $this->classmap[$class] ?? null;
        if ($file === null) {
            foreach ($this->psr4 as $prefix => $directories) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                foreach ($directories as $directory) {
                    $candidate = $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($candidate)) {
                        $file = $candidate;
                        break 2;
                    }
                }
            }
        }
        if ($file === null) {
            $position = strrpos($class, '\\');
            $logicalPath = $position === false
                ? str_replace('_', '/', $class) . '.php'
                : str_replace('\\', '/', substr($class, 0, $position + 1))
                    . str_replace('_', '/', substr($class, $position + 1)) . '.php';
            foreach ($this->psr0 as $prefix => $directories) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                foreach ($directories as $directory) {
                    if (is_file($directory . '/' . $logicalPath)) {
                        $file = $directory . '/' . $logicalPath;
                        break 2;
                    }
                }
            }
        }
        if ($file === null || !is_file($file)) {
            throw new RuntimeException('Unresolved declaration: ' . $class);
        }
        if (!isset($this->parsedFiles[$file])) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $nodes = $traverser->traverse($this->parse($file));
            foreach ($this->finder->findInstanceOf($nodes, Stmt\ClassLike::class) as $node) {
                if ($node->name === null || !isset($node->namespacedName)) {
                    continue;
                }
                $name = $node->namespacedName->toString();
                $traits = [];
                $adaptations = [];
                foreach ($node->getTraitUses() as $use) {
                    foreach ($use->traits as $trait) {
                        $traits[] = $trait->toString();
                    }
                    foreach ($use->adaptations as $adaptation) {
                        $adaptations[] = $adaptation->getType() . ':' . $adaptation->getStartLine();
                    }
                }
                $this->cache[$name] = [
                    'node' => $node,
                    'file' => $file,
                    'parent' => $node instanceof Stmt\Class_ && $node->extends ? $node->extends->toString() : null,
                    'traits' => $traits,
                    'adaptations' => $adaptations,
                ];
            }
            $this->parsedFiles[$file] = true;
        }
        if (!isset($this->cache[$class])) {
            throw new RuntimeException('Mapped file does not declare ' . $class . ': ' . $file);
        }
        return $this->cache[$class];
    }

    /**
     * Find a member while retaining its declaring context; adaptations are not emulated.
     *
     * @param string $class Class input for this operation.
     * @param string $kind Kind input for this operation.
     * @param string $name Name input for this operation.
     * @param array $seen Seen input for this operation.
     * @return ?array Result of the documented operation.
     */
    public function member(string $class, string $kind, string $name, array $seen = []): ?array
    {
        if (isset($seen[$class])) {
            throw new RuntimeException('Cyclic source graph at ' . $class);
        }
        $seen[$class] = true;
        $declaration = $this->declaration($class);
        foreach ($declaration['node']->stmts as $statement) {
            if ($kind === 'method' && $statement instanceof Stmt\ClassMethod && strtolower($statement->name->toString()) === strtolower($name)) {
                return [$statement, $class, $declaration['file']];
            }
            if ($kind === 'property' && $statement instanceof Stmt\Property) {
                foreach ($statement->props as $property) {
                    if ($property->name->toString() === $name) {
                        return [$property, $class, $declaration['file']];
                    }
                }
            }
        }
        foreach ($declaration['node']->getTraitUses() as $use) {
            foreach ($use->adaptations as $adaptation) {
                $renamed = $adaptation instanceof Stmt\TraitUseAdaptation\Alias ? $adaptation->newName : null;
                if (
                    strcasecmp($adaptation->method->toString(), $name) === 0
                    || ($renamed && strcasecmp($renamed->toString(), $name) === 0)
                ) {
                    throw new RuntimeException('Relevant trait adaptation requires semantic resolution in ' . $class);
                }
            }
        }
        $matches = [];
        foreach ($declaration['traits'] as $trait) {
            $match = $this->member($trait, $kind, $name, $seen);
            if ($match !== null) {
                $matches[] = $match;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Conflicting trait member: ' . $class . '::' . $name);
        }
        if ($matches) {
            return $matches[0];
        }
        return $declaration['parent'] ? $this->member($declaration['parent'], $kind, $name, $seen) : null;
    }

    /**
     * Resolve only simple model-list declarations, not arbitrary provider behaviour.
     *
     * @param string $class Class input for this operation.
     * @return ?array Result of the documented operation.
     */
    public function models(string $class): ?array
    {
        return (new ProviderModels($this))->read($class);
    }

    /**
     * Check a parsed inheritance/interface graph without class loading.
     *
     * @param string $class Class or interface to inspect.
     * @param string $target Required ancestor/interface name.
     * @param array $seen Cycle guard.
     * @return bool Whether the declaration graph contains the target.
     */
    public function isA(string $class, string $target, array $seen = []): bool
    {
        if (strcasecmp($class, $target) === 0) {
            return true;
        }
        if (isset($seen[$class])) {
            return false;
        }
        $seen[$class] = true;
        $declaration = $this->declaration($class);
        $node = $declaration['node'];
        $ancestors = $node instanceof Stmt\Interface_ ? $node->extends : ($node->implements ?? []);
        foreach ($ancestors as $ancestor) {
            if ($this->isA($ancestor->toString(), $target, $seen)) {
                return true;
            }
        }
        return $declaration['parent'] !== null && $this->isA($declaration['parent'], $target, $seen);
    }

    /**
     * Follow concrete/parent classes and breadth-first nested traits as FieldBroker does.
     *
     * @param string $class Class input for this operation.
     * @return array Result of the documented operation.
     */
    public function modelSources(string $class): array
    {
        $files = [];
        $adaptations = [];
        $lineage = [];
        $cursor = $class;
        while ($cursor !== 'D3R_Model') {
            if (isset($lineage[$cursor])) {
                throw new RuntimeException('Cyclic parent graph: ' . $cursor);
            }
            $lineage[$cursor] = true;
            $declaration = $this->declaration($cursor);
            $files[] = $declaration['file'];
            $adaptations = array_merge($adaptations, $declaration['adaptations']);
            $queue = $declaration['traits'];
            while ($queue) {
                $trait = $this->declaration(array_shift($queue));
                if (in_array($trait['file'], $files, true)) {
                    continue;
                }
                $files[] = $trait['file'];
                $adaptations = array_merge($adaptations, $trait['adaptations']);
                $queue = array_merge($queue, $trait['traits']);
            }
            $cursor = $declaration['parent'];
            if ($cursor === null) {
                throw new RuntimeException('Not derived from D3R_Model: ' . $class);
            }
        }
        $xmlCandidates = array_map(fn($file) => str_replace('.php', '.xml', $file), $files);
        foreach ($xmlCandidates as $xmlFile) {
            if (!is_file($xmlFile)) {
                $this->inputHashes[$xmlFile] = null;
            }
        }
        $xml = array_values(array_filter($xmlCandidates, 'is_file'));
        $extraSources = null;
        // Structural sources are useful even when trait adaptations prevent full member resolution.
        try {
            $method = $this->member($class, 'method', 'getXmlSources');
            if ($method && $method[1] !== 'D3R_Model') {
                $extraSources = $method[1] . ':' . $method[0]->getStartLine();
            }
        } catch (Throwable $error) {
            $extraSources = $error->getMessage();
        }
        return ['structuralPhpSources' => $files, 'structuralXmlSources' => $xml,
            'additionalXmlRequiresReview' => $extraSources, 'traitAdaptations' => $adaptations];
    }

    /**
     * Record literal container bindings without claiming control-flow or runtime resolution.
     *
     * @param string $class Class input for this operation.
     * @return array Result of the documented operation.
     */
    public function bindings(string $class): array
    {
        $declaration = $this->declaration($class);
        $bindings = [];
        foreach ($this->finder->findInstanceOf($declaration['node']->stmts, Expr\MethodCall::class) as $call) {
            if (
                !$call->var instanceof Expr\Variable || $call->var->name !== 'container'
                || !$call->name instanceof Node\Identifier
                || !in_array($call->name->toString(), ['add', 'share'], true)
                || count($call->args) < 2
            ) {
                continue;
            }
            try {
                $alias = $this->literalValue($call->args[0]->value, $declaration['file'], $class);
                $concrete = $this->literalValue($call->args[1]->value, $declaration['file'], $class);
                if (is_string($alias) && is_string($concrete)) {
                    $bindings[] = ['alias' => $alias, 'class' => $concrete,
                        'file' => $declaration['file'], 'line' => $call->getStartLine()];
                }
            } catch (Throwable $error) {
                // Closures, variables and executable expressions are deliberately not evaluated.
            }
        }
        return $bindings;
    }

    /**
     * Return parsed inputs and absent adjacent XML paths for worker freshness validation.
     *
     * @return array Result of the documented operation.
     */
    public function inputHashes(): array
    {
        return $this->inputHashes;
    }

    /**
     * Return declaration-only symbol paths without including a Composer loader.
     *
     * @return array Result of the documented operation.
     */
    public function symbolPaths(): array
    {
        $directories = [];
        foreach (array_merge(array_values($this->psr4), array_values($this->psr0)) as $paths) {
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $directories[$path] = true;
                }
            }
        }
        $paths = array_keys($directories);
        foreach ($this->classmap as $file) {
            if (!is_file($file)) {
                continue;
            }
            $covered = false;
            foreach ($directories as $directory => $unused) {
                if (str_starts_with($file, rtrim($directory, '/') . '/')) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $paths[] = $file;
            }
        }
        return array_values(array_filter($paths, function (string $path): bool {
            foreach ($this->devPaths as $devPath) {
                if ($path === $devPath || str_starts_with($path, $devPath . '/')) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Read the generated provider registration list as data.
     *
     * @return array Result of the documented operation.
     */
    public function providers(): array
    {
        $arrays = $this->finder->findInstanceOf($this->parse($this->vendor . '/d3r.php'), Expr\Array_::class);
        if (count($arrays) !== 1) {
            throw new RuntimeException('Unexpected generated provider-list syntax.');
        }
        return $this->literalValue($arrays[0], $this->vendor . '/d3r.php');
    }
}
