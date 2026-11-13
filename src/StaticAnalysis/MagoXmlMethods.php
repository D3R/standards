<?php

declare(strict_types=1);

namespace D3R\Standards\StaticAnalysis;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\UndeclaredReturnTypeProvider;

/**
 * Supply only inventoried XML field-object methods to Mago's native analyser.
 */
final class MagoXmlMethods implements Plugin, MethodReturnTypeProvider, CallableSignatureProvider, UndeclaredReturnTypeProvider
{
    /** @var array Model names mapped to supported field metadata. */
    private array $models;

    /**
     * Load the generator's explicit supported-field inventory.
     *
     * @param array $inventory Output of XmlMetadata::generate().
     */
    public function __construct(array $inventory)
    {
        $this->models = [];
        foreach ($inventory['models'] as $class => $model) {
            $this->models[strtolower($class)] = $model['supported'];
        }
    }

    /**
     * Describe this optional analyser plugin.
     *
     * @return PluginDefinition Package-owned registration metadata.
     */
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'd3r/xml-model-methods',
            'D3R XML model methods',
            'Recognises explicitly inventoried XML field-object methods.'
        );
    }

    /**
     * Register return types and the accompanying dynamic callable signatures.
     *
     * @param PluginRegistry $registry Native analyser provider registry.
     * @return void
     */
    public function register(PluginRegistry $registry): void
    {
        if ($this->getTargets()) {
            $registry->registerMethodReturnTypeProvider($this);
        }
    }

    /**
     * Limit callbacks to known model/field pairs.
     *
     * @return array Narrow class/method targets; unknown fields are never claimed.
     */
    public function getTargets(): array
    {
        $targets = [];
        foreach ($this->models as $class => $fields) {
            foreach ($fields as $name => $field) {
                if ($field['magicMethod'] ?? false) {
                    $targets[] = MethodTarget::exact($class, $name);
                }
            }
        }
        return $targets;
    }

    /**
     * Resolve the field-object result without instantiating a model.
     *
     * @param ReturnTypeProviderContext $context Native resolved call information.
     * @return Type|null Field-object type, or no answer for unrecognised receivers.
     */
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $fieldClass = $this->fieldClass($context->invocation);
        if ($fieldClass === null) {
            return null;
        }
        if (!$this->reliableFieldClass($context->invocation)) {
            return Type::mixed();
        }
        return !$context->invocation->arguments || $this->returnsFieldObject($context->invocation)
            ? Type::namedObject($fieldClass) : Type::mixed();
    }

    /**
     * Establish known magic calls before Mago validates their arguments.
     *
     * @param CallableSignatureProviderContext $context Native unresolved call information.
     * @return EffectiveCallableSignature|null Recognised field call or native fallback.
     */
    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        if ($this->fieldClass($context->invocation) === null) {
            return null;
        }
        return new EffectiveCallableSignature([
            new CallableParameter(name: '$args', type: Type::mixed(), variadic: true),
        ]);
    }

    /**
     * Avoid promising a concrete field type for unproved container overrides.
     *
     * @param Invocation $invocation Recognised concrete model field call.
     * @return bool Whether the default field class is authoritative.
     */
    private function reliableFieldClass(Invocation $invocation): bool
    {
        $type = $invocation->receiverType->atomicTypes[0];
        return $this->models[strtolower($type->name)][$invocation->name]['fieldClassReliable'] ?? true;
    }

    /**
     * Return a precise object only when the inventoried field contract proves it.
     *
     * @param Invocation $invocation Invocation input for this operation.
     * @return bool Result of the documented operation.
     */
    private function returnsFieldObject(Invocation $invocation): bool
    {
        $type = $invocation->receiverType->atomicTypes[0];
        return $this->models[strtolower($type->name)][$invocation->name]['returnsFieldObject'] ?? true;
    }

    /**
     * Match exact inventoried concrete receivers without assuming descendant XML.
     *
     * @param Invocation $invocation Native receiver type and requested method name.
     * @return string|null Configured field-object class or no supported contract.
     */
    private function fieldClass(Invocation $invocation): ?string
    {
        if ($invocation->kind !== InvocationKind::InstanceMethod) {
            return null;
        }
        $types = $invocation->receiverType->atomicTypes ?? [];
        if (count($types) !== 1 || !($types[0] instanceof NamedObjectType)) {
            return null;
        }
        $class = strtolower($types[0]->name);
        if (!($this->models[$class][$invocation->name]['magicMethod'] ?? false)) {
            return null;
        }
        return $this->models[$class][$invocation->name]['fieldClass'] ?? null;
    }
}
