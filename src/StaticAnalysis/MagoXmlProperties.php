<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** Establish known XML properties even when Mago analyses the model's own method bodies. */
final class MagoXmlProperties implements Plugin, PropertyTypeProvider
{
    /** @var array Concrete model names mapped to known XML member names. */
    private array $models = [];

    /** @param array $inventory Source-derived model metadata. */
    public function __construct(array $inventory)
    {
        foreach ($inventory['models'] as $class => $model) {
            $this->models[strtolower($class)] = $model['supported'];
        }
    }

    /** @return PluginDefinition Native property-provider registration metadata. */
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('d3r/xml-model-properties', 'D3R XML properties', 'Recognises inventoried XML property names and the base model id.');
    }

    /** @param PluginRegistry $registry Native provider registry. @return void */
    public function register(PluginRegistry $registry): void
    {
        $registry->registerPropertyTypeProvider($this);
    }

    /** @return array Narrow targets; the base id contract is inherited by D3R models. */
    public function getTargets(): array
    {
        $targets = [PropertyTarget::exact('D3R_Model', 'id')];
        foreach ($this->models as $class => $fields) {
            foreach ($fields as $name => $unused) {
                if ($name !== 'id') {
                    $targets[] = PropertyTarget::exact($class, $name);
                }
            }
        }
        return $targets;
    }

    /**
     * Supply field presence without guessing storage-value types or overriding native properties.
     *
     * @param PropertyTypeProviderContext $context Resolved property access and codebase.
     * @return ?PropertyType Mixed XML value contract, read-only id, or native fallback.
     */
    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $access = $context->access;
        if ($context->codebase->getProperty($access->class, $access->property) !== null) {
            return null;
        }
        if ($access->property === 'id') {
            return new PropertyType(readType: Type::mixed());
        }
        if (!isset($this->models[strtolower($access->class)][$access->property])) {
            return null;
        }
        return new PropertyType(readType: Type::mixed(), writeType: Type::mixed());
    }
}
