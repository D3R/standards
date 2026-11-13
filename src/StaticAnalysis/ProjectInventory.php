<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use RuntimeException;
use Throwable;

/** Collect automatic source metadata, preserving incomplete discovery as an explicit result. */
final class ProjectInventory
{
    /**
     * Bind discovery to a project and the already resolved vendor directory.
     *
     * @param ProjectDiscovery $discovery Discovery input for this operation.
     * @param string $project Absolute consuming-project root.
     * @param string $vendor Installed Composer vendor directory.
     */
    public function __construct(private ProjectDiscovery $discovery, private string $project, private string $vendor)
    {
    }

    /**
     * Discover common declarations without constructing providers, models or containers.
     *
     * @return array Result of the documented operation.
     */
    public function discover(): array
    {
        $report = ['schemaVersion' => 1, 'project' => $this->project, 'providers' => [],
            'aliasCandidates' => [], 'literalBindings' => [], 'models' => [], 'gaps' => []];
        foreach ($this->discovery->providers() as $index => $provider) {
            try {
                $report['literalBindings'][$provider] = $this->discovery->bindings($provider);
                $models = $this->discovery->models($provider);
                $report['providers'][] = ['class' => $provider, 'order' => $index, 'models' => count($models ?? [])];
                foreach ($models ?? [] as $alias => $class) {
                    if (!is_string($alias) || !is_string($class)) {
                        throw new RuntimeException('Non-string model declaration.');
                    }
                    $report['aliasCandidates'][$alias] = ['class' => ltrim($class, '\\'), 'provider' => $provider];
                }
            } catch (Throwable $error) {
                $report['providers'][] = ['class' => $provider, 'order' => $index, 'unresolved' => true];
                $report['gaps'][] = ['provider' => $provider, 'reason' => $error->getMessage()];
            }
        }
        foreach (array_unique(array_column($report['aliasCandidates'], 'class')) as $class) {
            try {
                $sources = $this->discovery->modelSources($class);
                $report['models'][$class] = $sources;
                if ($sources['additionalXmlRequiresReview'] !== null) {
                    $report['gaps'][] = ['model' => $class, 'reason' => $sources['additionalXmlRequiresReview']];
                }
            } catch (Throwable $error) {
                $report['gaps'][] = ['model' => $class, 'reason' => $error->getMessage()];
            }
        }
        $report['bindingStatus'] = 'source-declarations-only';
        $report['limitations'] = [
            'Container factories, conditional registration and boot-time changes are not resolved.',
            'Alias candidates are not emitted as authoritative container return types.',
            'Dynamic getXmlSources and model lists remain explicit gaps.',
        ];
        return $report;
    }

    /**
     * Generate known field presence and conservative magic-call contracts automatically.
     *
     * @param string $directory Directory input for this operation.
     * @return array Result of the documented operation.
     */
    public function generate(array &$report, string $directory): array
    {
        $classes = [];
        $returns = [];
        $reliable = [];
        $bindingCandidates = [];
        foreach ($report['literalBindings'] as $bindings) {
            foreach ($bindings as $binding) {
                $bindingCandidates[$binding['alias']] = $binding;
            }
        }
        $inventory = ['models' => [], 'inputs' => [], 'valueTypesInferred' => false];
        $patch = "<?php\n\n// Generated analysis-only contracts. Never autoload.\n";
        foreach ($report['models'] as $class => $sources) {
            if ($sources['additionalXmlRequiresReview'] !== null || !$sources['structuralXmlSources']) {
                continue;
            }
            try {
                foreach ($sources['structuralXmlSources'] as $file) {
                    $contents = file_get_contents($file);
                    if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
                        throw new RuntimeException('Unsafe or unreadable XML: ' . $file);
                    }
                    $previous = libxml_use_internal_errors(true);
                    try {
                        $xml = simplexml_load_string($contents, options: LIBXML_NONET);
                        if ($xml === false) {
                            throw new RuntimeException('Malformed XML: ' . $file);
                        }
                    } finally {
                        libxml_clear_errors();
                        libxml_use_internal_errors($previous);
                    }
                    foreach ($xml->xpath('//field/type') as $node) {
                        $type = ucfirst(trim((string) $node));
                        if ($type === 'False' || $type === '') {
                            continue;
                        }
                        $alias = 'D3R_Model_Field_' . $type;
                        $fieldClass = $bindingCandidates[$alias]['class'] ?? $alias;
                        $reliable[$type] = !isset($bindingCandidates[$alias]);
                        $this->discovery->declaration($fieldClass);
                        $classes[$type] = $fieldClass;
                        $returns[$type] = 'mixed';
                        if (!isset($bindingCandidates[$alias])) {
                            try {
                                $expose = $this->discovery->member($fieldClass, 'method', 'expose');
                                $statements = $expose[0]->stmts ?? [];
                                if (
                                    count($statements) === 1 && $statements[0] instanceof \PhpParser\Node\Stmt\Return_
                                    && $statements[0]->expr instanceof \PhpParser\Node\Expr\Variable
                                    && $statements[0]->expr->name === 'this'
                                ) {
                                    $returns[$type] = 'field-object';
                                }
                            } catch (Throwable $error) {
                                // Presence is known; an unproved expose contract deliberately stays mixed.
                            }
                        }
                    }
                }
                $model = ['class' => $class, 'xmlSources' => $sources['structuralXmlSources'],
                    'phpSources' => array_merge($sources['structuralPhpSources'], [$this->discovery->declaration('D3R_Model')['file']])];
                $generated = XmlMetadata::generate(['fieldClasses' => $classes, 'fieldMethodReturns' => $returns, 'fieldClassReliability' => $reliable,
                    'models' => [$model]], $this->project);
                if ($generated['inventory']['models'][$class]['unsupported']) {
                    $report['gaps'][] = ['model' => $class, 'reason' => 'Some XML member names are unsupported; inspect inventory.json.'];
                }
                $patch .= preg_replace('/^<\?php\s*/', '', $generated['magoPhp']) . "\n";
                $inventory['models'] += $generated['inventory']['models'];
                $inventory['inputs'] += $generated['inventory']['inputs'];
            } catch (Throwable $error) {
                $report['gaps'][] = ['model' => $class, 'reason' => 'Metadata omitted: ' . $error->getMessage()];
            }
        }
        foreach (
            ['composer.json', 'composer.lock', 'd3r.php', 'composer/autoload_classmap.php',
            'composer/autoload_psr4.php', 'composer/autoload_namespaces.php', 'composer/installed.json'] as $relative
        ) {
            $file = in_array($relative, ['composer.json', 'composer.lock'], true)
                ? $this->project . '/' . $relative : $this->vendor . '/' . $relative;
            if (is_file($file)) {
                $inventory['inputs'][$file] = hash_file('sha256', $file);
            }
        }
        $inventory['inputs'] += $this->discovery->inputHashes();
        $report['metadataModels'] = count($inventory['models']);
        $report['fieldMethodReturns'] = $returns;
        Toolchain::directory($directory);
        if (
            file_put_contents($directory . '/models.mago.php', $patch) === false
            || file_put_contents($directory . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false
        ) {
            throw new RuntimeException('Cannot write generated metadata.');
        }
        return $inventory;
    }
}
