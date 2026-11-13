<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;

/**
 * Build analysis-only magic-member declarations without loading a D3R application.
 */
final class XmlMetadata
{
    /**
     * Generate tool-specific patches/stubs and a shared coverage inventory.
     *
     * The caller supplies XML sources in FieldBroker order: first source wins.
     * Raw property values remain mixed because storage types do not prove PHP types.
     *
     * @param array $manifest Model classes, ordered XML sources and field-class contracts.
     * @param string $baseDirectory Directory used to resolve manifest-relative paths.
     * @return array Generated PHP declaration text and coverage inventory.
     */
    public static function generate(array $manifest, string $baseDirectory): array
    {
        $contracts = $manifest['fieldClasses'] ?? [];
        $models = $manifest['models'] ?? [];
        if (!is_array($contracts) || !is_array($models) || !$contracts || !$models) {
            throw new InvalidArgumentException('models and fieldClasses must be non-empty.');
        }
        foreach ($contracts as $type => $class) {
            self::assertName($type, false);
            self::assertName($class, true);
        }

        $inventory = ['models' => [], 'inputs' => [], 'valueTypesInferred' => false];
        $declarations = [];
        $magoDeclarations = [];
        $referencedClasses = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                throw new InvalidArgumentException('Each model must be an object.');
            }
            $class = $model['class'] ?? '';
            self::assertName($class, true);
            if (isset($declarations[$class])) {
                throw new InvalidArgumentException('Duplicate model class: ' . $class);
            }
            if (empty($model['xmlSources']) || !is_array($model['xmlSources'])) {
                throw new InvalidArgumentException('Each model requires ordered xmlSources.');
            }
            if (empty($model['phpSources']) || !is_array($model['phpSources'])) {
                throw new InvalidArgumentException('Each model requires phpSources to preserve native methods.');
            }
            $nativeMethods = self::nativeMethods($model['phpSources'], $baseDirectory, $inventory['inputs']);
            $fields = [];
            foreach ($model['xmlSources'] as $source) {
                $path = self::resolvePath($source, $baseDirectory);
                $contents = file_get_contents($path);
                if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
                    throw new RuntimeException('Unreadable or unsafe XML: ' . $path);
                }
                $inventory['inputs'][$path] = hash('sha256', $contents);
                $previous = libxml_use_internal_errors(true);
                try {
                    $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);
                    if ($xml === false) {
                        throw new RuntimeException('Invalid fields XML: ' . $path);
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }

                $groups = count($xml->fieldset) ? $xml->fieldset : [$xml];
                foreach ($groups as $group) {
                    $local = [];
                    foreach ($group->field as $field) {
                        $type = trim((string) $field->type);
                        $type = ucfirst($type);
                        if ($type === '') {
                            throw new RuntimeException('Field without type in ' . $path);
                        }
                        if ($type === 'False') {
                            continue;
                        }
                        $name = trim((string) $field->dbname);
                        if ($name === '') {
                            $name = self::implicitFieldName((string) $field->name, $path);
                        }
                        $local[$name] = ['type' => $type, 'source' => $path];
                    }
                    // Last occurrence inside a fieldset wins; earlier fieldsets/sources win.
                    $fields += $local;
                }
            }

            $members = [];
            $magoMembers = [];
            $supported = [];
            $unsupported = [];
            ksort($fields);
            foreach ($fields as $name => $field) {
                $fieldClass = $contracts[$field['type']] ?? null;
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || $fieldClass === null) {
                    $unsupported[$name] = $field;
                    continue;
                }
                $members[] = ' * @property mixed $' . $name;
                $magoMembers[] = ' * @property mixed $' . $name;
                $isMagicMethod = !isset($nativeMethods[strtolower($name)]);
                $returnsFieldObject = ($manifest['fieldMethodReturns'][$field['type']] ?? 'field-object') === 'field-object';
                if ($isMagicMethod) {
                    $returnType = $returnsFieldObject ? '\\' . $fieldClass : 'mixed';
                    $members[] = ' * @method ' . $returnType . ' ' . $name . '()';
                }
                $referencedClasses[$fieldClass] = true;
                $supported[$name] = $field + [
                    'fieldClass' => $fieldClass,
                    'magicMethod' => $isMagicMethod,
                    'returnsFieldObject' => $returnsFieldObject,
                ];
            }
            $declarations[$class] = self::declaration($class, $members);
            $magoDeclarations[$class] = self::declaration($class, $magoMembers);
            $inventory['models'][$class] = [
                'supported' => $supported,
                'unsupported' => $unsupported,
                'xmlDiscovery' => 'caller-supplied-ordered-sources',
                'nativeMethods' => array_keys($nativeMethods),
            ];
        }

        foreach ($referencedClasses as $class => $unused) {
            if (isset($declarations[$class])) {
                throw new InvalidArgumentException('Field class collides with model: ' . $class);
            }
            // PHPStan validates referenced stub symbols independently of source discovery.
            $declarations[$class] = self::declaration($class, []);
        }
        ksort($declarations);
        ksort($magoDeclarations);
        ksort($inventory['inputs']);
        $inventory['manifestHash'] = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
        $inventory['inputHash'] = hash('sha256', json_encode($inventory['inputs'], JSON_THROW_ON_ERROR));
        $header = "<?php\n\n// Generated D3R analysis metadata. Never autoload this file.\n";
        $header .= '// Manifest: ' . $inventory['manifestHash'] . "\n";
        $header .= '// Source inputs: ' . $inventory['inputHash'] . "\n\n";
        return [
            'php' => $header . implode("\n", $declarations),
            'magoPhp' => $header . implode("\n", $magoDeclarations),
            'inventory' => $inventory,
        ];
    }

    /**
     * Match D3R_String::getUrlName($name, '_') for ASCII labels without loading the framework.
     *
     * @param string $name Human-readable XML label.
     * @param string $path Source path for an actionable unsupported-name error.
     * @return string Normalised magic-member name.
     */
    private static function implicitFieldName(string $name, string $path): string
    {
        $name = html_entity_decode(strip_tags(trim($name)));
        if (preg_match('/[^\x00-\x7F]/', $name)) {
            throw new RuntimeException('Implicit Unicode field name needs a reviewed transliteration rule in ' . $path);
        }
        $name = str_replace('&', 'and', $name);
        $name = str_replace([' ', '_', '-', '/'], '_', $name);
        $name = str_replace(["'", '"', '!', ',', '*', '?'], '', $name);
        $name = preg_replace('/[^\w_]/', '', $name);
        $name = strtolower(preg_replace('/_{2,}/', '_', $name));
        if ($name === '') {
            throw new RuntimeException('Empty implicit field name in ' . $path);
        }
        return $name;
    }

    /**
     * Reserve named functions in explicit class/parent/trait source files.
     *
     * This conservative token scan executes no PHP. Additional named functions in
     * a supplied file also reserve their names rather than risk replacing a method.
     *
     * @param array $sources Explicit PHP declaration sources.
     * @param string $baseDirectory Manifest directory.
     * @param array $inputs Source hashes to extend.
     * @return array Lowercase method names mapped to true.
     */
    private static function nativeMethods(array $sources, string $baseDirectory, array &$inputs): array
    {
        $methods = [];
        foreach ($sources as $source) {
            $path = self::resolvePath($source, $baseDirectory);
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Cannot read PHP declaration source: ' . $path);
            }
            $inputs[$path] = hash('sha256', $contents);
            $tokens = token_get_all($contents, TOKEN_PARSE);
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_FUNCTION) {
                    continue;
                }
                $index++;
                while (
                    isset($tokens[$index]) && is_array($tokens[$index])
                    && (in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                        || $tokens[$index][1] === '&')
                ) {
                    $index++;
                }
                if (isset($tokens[$index]) && is_array($tokens[$index]) && $tokens[$index][0] === T_STRING) {
                    $methods[strtolower($tokens[$index][1])] = true;
                }
            }
        }
        ksort($methods);
        return $methods;
    }

    /**
     * Validate names before interpolating them into generated PHPDoc/declarations.
     *
     * @param string $name Class name or XML field-type identifier.
     * @param bool $namespaced Whether namespace separators are allowed.
     * @return void
     */
    private static function assertName(string $name, bool $namespaced): void
    {
        $parts = $namespaced ? explode('\\', $name) : [$name];
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part)) {
                throw new InvalidArgumentException('Invalid PHP identifier: ' . $name);
            }
        }
    }

    /**
     * Resolve a required source input without selecting an application environment.
     *
     * @param string $source Absolute or manifest-relative XML filename.
     * @param string $baseDirectory Manifest directory.
     * @return string Existing absolute filename.
     */
    private static function resolvePath(string $source, string $baseDirectory): string
    {
        $path = $source !== '' && $source[0] === '/' ? $source : $baseDirectory . '/' . $source;
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Missing XML input: ' . $path);
        }
        return $resolved;
    }

    /**
     * Render a declaration patch without duplicating runtime implementation.
     *
     * @param string $class Fully qualified class name.
     * @param array $members Magic property and method PHPDoc lines.
     * @return string PHP namespace block.
     */
    private static function declaration(string $class, array $members): string
    {
        $parts = explode('\\', $class);
        $shortName = array_pop($parts);
        $namespace = implode('\\', $parts);
        $doc = "/**\n * Analysis-only XML member contracts.\n" . implode("\n", $members) . "\n */\n";
        return 'namespace ' . $namespace . " {\n" . $doc . 'class ' . $shortName . "\n{\n}\n}\n";
    }
}
