<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use RuntimeException;
use Throwable;

/** Read declarative provider lists using a bounded expression whitelist, never PHP execution. */
final class ProviderModels
{
    /** @param ProjectDiscovery $source Parsed declarations and Composer source index. */
    public function __construct(private ProjectDiscovery $source)
    {
    }

    /**
     * Resolve a provider's model list, preserving the concrete receiver through parent calls.
     *
     * @param string $class Concrete provider class.
     * @param ?string $lookup Class from which a parent method lookup starts.
     * @param ?array $state Local interpretation state for the models property.
     * @param int $depth Bounded recursion depth.
     * @return ?array Alias-to-class declarations, or null for non-model providers.
     */
    public function read(string $class, ?string $lookup = null, ?array &$state = null, int $depth = 0): ?array
    {
        if ($depth > 32) {
            throw new RuntimeException('Provider model expression recursion limit: ' . $class);
        }
        $member = $this->source->member($lookup ?? $class, 'method', 'getModelList');
        if ($member === null) {
            return null;
        }
        if ($class === 'D3R_Provider_ModuleFolder' && $member[1] === $class) {
            return $this->legacyModules($class);
        }
        $state ??= [];
        try {
            [$returned, $value] = $this->statements($member[0]->stmts ?? [], $class, $member[1], $member[2], $state, $depth + 1);
        } catch (Throwable $error) {
            throw new RuntimeException('Cannot resolve getModelList in ' . $class . ': ' . $error->getMessage(), 0, $error);
        }
        if (!$returned || !is_array($value)) {
            throw new RuntimeException('Unresolved model-list return in ' . $class);
        }
        return $value;
    }

    /**
     * Interpret only return, if and models-array assignments; unknown statements stay gaps.
     *
     * @param array $statements Parsed method or branch statements.
     * @param string $class Concrete receiver.
     * @param string $owner Declaring class or trait.
     * @param string $file Declaring source file.
     * @param array $state Models-property state.
     * @param int $depth Recursion depth.
     * @return array Whether a return was reached and its value.
     */
    private function statements(array $statements, string $class, string $owner, string $file, array &$state, int $depth): array
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Return_) {
                return [true, $this->expression($statement->expr, $class, $owner, $file, $state, $depth)];
            }
            if ($statement instanceof Stmt\If_) {
                $branches = array_merge([$statement], $statement->elseifs);
                $selected = $statement->else->stmts ?? [];
                foreach ($branches as $branch) {
                    if ($this->expression($branch->cond, $class, $owner, $file, $state, $depth)) {
                        $selected = $branch->stmts;
                        break;
                    }
                }
                $result = $this->statements($selected, $class, $owner, $file, $state, $depth + 1);
                if ($result[0]) {
                    return $result;
                }
                continue;
            }
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign) {
                $assignment = $statement->expr;
                if ($assignment->var instanceof Expr\ArrayDimFetch && $this->isModels($assignment->var->var)) {
                    $this->property($class, $state);
                    $key = $this->expression($assignment->var->dim, $class, $owner, $file, $state, $depth);
                    $state['models'][$key] = $this->expression($assignment->expr, $class, $owner, $file, $state, $depth);
                    continue;
                }
            }
            throw new RuntimeException('Unsupported provider statement ' . $statement->getType() . ' in ' . $owner);
        }
        return [false, null];
    }

    /**
     * Evaluate a small set of declarative expressions and declaration-existence predicates.
     *
     * @param Expr $node Parsed expression; arbitrary calls are rejected.
     * @param string $class Concrete receiver.
     * @param string $owner Declaring class or trait.
     * @param string $file Source file.
     * @param array $state Models-property state.
     * @param int $depth Recursion depth.
     * @return mixed Statically resolved expression value.
     */
    private function expression(Expr $node, string $class, string $owner, string $file, array &$state, int $depth): mixed
    {
        if ($depth > 32) {
            throw new RuntimeException('Provider expression recursion limit in ' . $class);
        }
        $value = function (Expr $expr) use ($class, $owner, $file, &$state, $depth): mixed {
            return $this->expression($expr, $class, $owner, $file, $state, $depth + 1);
        };
        if ($this->isModels($node)) {
            if ($node instanceof Expr\StaticPropertyFetch && $node->class->toString() === 'self' && $owner !== $class) {
                throw new RuntimeException('Inherited lexical self::$models requires a reviewed static-property rule.');
            }
            return $this->property($class, $state);
        }
        if ($node instanceof Expr\ArrayDimFetch) {
            return $value($node->var)[$value($node->dim)] ?? null;
        }
        if ($node instanceof Expr\Isset_) {
            foreach ($node->vars as $variable) {
                if ($value($variable) === null) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Expr\BooleanNot) {
            return !$value($node->expr);
        }
        if ($node instanceof Expr\BinaryOp\BooleanAnd) {
            return $value($node->left) && $value($node->right);
        }
        if ($node instanceof Expr\BinaryOp\Equal) {
            return $value($node->left) == $value($node->right);
        }
        if ($node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this' && !$node->args) {
            $method = $this->source->member($class, 'method', $node->name->toString());
            if ($method && count($method[0]->stmts ?? []) === 1 && $method[0]->stmts[0] instanceof Stmt\Return_) {
                return $this->expression($method[0]->stmts[0]->expr, $class, $method[1], $method[2], $state, $depth + 1);
            }
        }
        if ($node instanceof Expr\StaticCall && $node->class->toString() === 'parent' && $node->name->toString() === 'getModelList' && !$node->args) {
            $parent = $this->source->declaration($owner)['parent'];
            if ($parent !== null) {
                return $this->read($class, $parent, $state, $depth + 1);
            }
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof \PhpParser\Node\Name) {
            $name = strtolower($node->name->toString());
            if ($name === 'array_merge') {
                foreach ($node->args as $argument) {
                    if ($argument->unpack || $argument->name !== null) {
                        throw new RuntimeException('Unpacked or named provider merge arguments require review.');
                    }
                }
                return array_merge(...array_map(fn($argument) => $value($argument->value), $node->args));
            }
            if (in_array($name, ['interface_exists', 'class_exists'], true) && count($node->args) === 1) {
                try {
                    $declaration = $this->source->declaration($value($node->args[0]->value));
                    return $name === 'interface_exists' ? $declaration['node'] instanceof Stmt\Interface_ : $declaration['node'] instanceof Stmt\Class_;
                } catch (Throwable $error) {
                    if (str_starts_with($error->getMessage(), 'Unresolved declaration:')) {
                        return false;
                    }
                    throw $error;
                }
            }
        }
        return $this->source->literalValue($node, $file, $owner);
    }

    /** @param Expr $node Expression to recognise. @return bool Whether it is the conventional models property. */
    private function isModels(Expr $node): bool
    {
        return ($node instanceof Expr\PropertyFetch && $node->var instanceof Expr\Variable && $node->var->name === 'this'
            || $node instanceof Expr\StaticPropertyFetch && in_array($node->class->toString(), ['self', 'static'], true))
            && $node->name->toString() === 'models';
    }

    /** @param string $class Concrete provider. @param array $state Local state. @return array Literal model map. */
    private function property(string $class, array &$state): array
    {
        if (!array_key_exists('models', $state)) {
            $property = $this->source->member($class, 'property', 'models');
            if ($property === null) {
                if (
                    ($this->source->member($class, 'method', 'getModelList')[1] ?? null) === 'D3R_Provider_ModelServiceTrait'
                    && !$this->source->isA($class, 'D3R_Provider_ModelInterface')
                ) {
                    return $state['models'] = [];
                }
                throw new RuntimeException('Missing models initializer in ' . $class);
            }
            $state['models'] = $this->source->literalValue($property[0]->default, $property[2], $property[1]);
        }
        return $state['models'];
    }

    /**
     * Read the legacy ModuleFolder default convention, with no constructors or reflection execution.
     *
     * @param string $class Exact legacy provider class.
     * @return array Concrete nonabstract model classes found in module model folders.
     */
    private function legacyModules(string $class): array
    {
        $contracts = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/mago/framework-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = $this->source->declaration($class)['file'];
        if (hash_file('sha256', $source) !== $contracts['moduleFolderSourceSha256']) {
            throw new RuntimeException('Unreviewed ModuleFolder implementation; review its filesystem discovery rule.');
        }
        $constructor = $this->source->member($class, 'method', '__construct');
        $statements = $constructor[0]->stmts ?? [];
        if (count($statements) !== 2 || !$statements[0] instanceof Stmt\If_) {
            throw new RuntimeException('Unrecognised ModuleFolder constructor; review its default directory rule.');
        }
        $assignments = (new NodeFinder())->findInstanceOf($statements[0]->stmts, Expr\Assign::class);
        if (count($assignments) !== 1 || $assignments[0]->var->name->toString() !== 'modulesFolderPath') {
            throw new RuntimeException('Unrecognised ModuleFolder default directory.');
        }
        $root = $this->source->literalValue($assignments[0]->expr, $constructor[2], $class);
        foreach (glob($root . '/*/models/*') ?: [] as $file) {
            if (!str_contains(basename(dirname(dirname($file))), '.') && !str_starts_with(basename($file), '.') && str_contains(basename($file), '.php')) {
                throw new RuntimeException('Legacy module model files require a reviewed instantiability rule: ' . $file);
            }
        }
        return [];
    }
}
