<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Runtime\LogicOperators;
use Armes\Runtime\ValueTypes;
use Armes\Tree\Node;

final class SourceSerializer
{
    public static function toSourceValue(mixed $value, string $operatorStyle = 'readable', bool $explicitTypedValues = false): mixed
    {
        if ($value instanceof Node) {
            return self::toSourceNode($value, $operatorStyle, $explicitTypedValues);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = self::toSourceValue($child, $operatorStyle, $explicitTypedValues);
            }
            return $result;
        }

        return $value;
    }

    public static function toSourceNode(Node $node, string $operatorStyle = 'readable', bool $explicitTypedValues = false): mixed
    {
        return match ($node->kind) {
            Node::FRAGMENT => array_map(fn (Node $child): mixed => self::toSourceNode($child, $operatorStyle, $explicitTypedValues), $node->children),
            Node::ELEMENT => self::elementToSource($node, $operatorStyle, $explicitTypedValues),
            Node::RUNTIME => self::runtimeToSource($node, $operatorStyle, $explicitTypedValues),
            Node::VALUE => self::valueToSource($node, $operatorStyle, $explicitTypedValues),
            Node::LOGIC => self::logicToSource($node, $operatorStyle, $explicitTypedValues),
            default => null,
        };
    }

    private static function elementToSource(Node $node, string $operatorStyle, bool $explicitTypedValues): array
    {
        $children = array_map(fn (Node $child): mixed => self::toSourceNode($child, $operatorStyle, $explicitTypedValues), $node->children);
        if ($node->props === []) {
            return [(string) $node->name => $children === [] ? [] : (count($children) === 1 ? $children[0] : $children)];
        }

        $body = ['@' => self::propsToSource($node->props, $operatorStyle, $explicitTypedValues)];
        if ($children !== []) {
            $body['#'] = $children;
        }
        return [(string) $node->name => $body];
    }

    private static function runtimeToSource(Node $node, string $operatorStyle, bool $explicitTypedValues): array
    {
        if ($node->name === 'expr') {
            return [':expr' => self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues)];
        }

        if (in_array($node->name, ['props', 'attributes'], true)) {
            return [':' . $node->name => self::toSourceValue($node->value ?? $node->props, $operatorStyle, $explicitTypedValues)];
        }

        if ($node->name === 'if') {
            return [':if' => self::ifBodyToSource($node, $operatorStyle, $explicitTypedValues)];
        }

        if ($node->props === [] && $node->children === [] && $node->value !== null) {
            return [':' . $node->name => self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues)];
        }

        $body = [];
        if ($node->props !== []) {
            $body['@'] = self::propsToSource($node->props, $operatorStyle, $explicitTypedValues);
        }
        if ($node->children !== []) {
            $body['#'] = array_map(fn (Node $child): mixed => self::toSourceNode($child, $operatorStyle, $explicitTypedValues), $node->children);
        }
        if ($node->value !== null) {
            $body['value'] = self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues);
        }
        return [':' . $node->name => $body];
    }

    private static function logicToSource(Node $node, string $operatorStyle, bool $explicitTypedValues): array
    {
        $args = array_map(fn (Node $arg): mixed => self::toSourceNode($arg, $operatorStyle, $explicitTypedValues), $node->args);
        $body = $node->op === 'var' && count($args) === 1 ? $args[0] : $args;

        if ($operatorStyle === 'symbol') {
            return [':' . LogicOperators::symbol((string) $node->op) => $body];
        }

        return [':logic:' . $node->op => $body];
    }

    private static function valueToSource(Node $node, string $operatorStyle, bool $explicitTypedValues): mixed
    {
        if ($explicitTypedValues && ValueTypes::isCanonical((string) $node->type)) {
            return [ValueTypes::key((string) $node->type) => self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues)];
        }

        return match ($node->type) {
            'string', 'int', 'float', 'bool', 'null', 'array', 'object', 'text' => self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues),
            default => [':' . $node->type => self::toSourceValue($node->value, $operatorStyle, $explicitTypedValues)],
        };
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function propsToSource(array $props, string $operatorStyle, bool $explicitTypedValues): array
    {
        $result = [];
        foreach ($props as $key => $value) {
            $result[$key] = self::toSourceValue($value, $operatorStyle, $explicitTypedValues);
        }
        return $result;
    }

    private static function ifBodyToSource(Node $node, string $operatorStyle, bool $explicitTypedValues): array
    {
        $props = $node->props;
        $elseChildren = isset($props['else']) && is_array($props['else']) ? $props['else'] : [];
        unset($props['else']);

        $thenChildren = [];
        foreach ($node->children as $child) {
            if ($child->kind === Node::RUNTIME && in_array($child->name, ['else', 'elseif'], true)) {
                if ($elseChildren === []) {
                    $elseChildren = $child->children;
                }
                continue;
            }
            $thenChildren[] = $child;
        }

        $body = [];
        if ($props !== []) {
            $body['@'] = self::propsToSource($props, $operatorStyle, $explicitTypedValues);
        }
        if ($thenChildren !== []) {
            $body['#'] = array_map(fn (Node $child): mixed => self::toSourceNode($child, $operatorStyle, $explicitTypedValues), $thenChildren);
        }
        if ($elseChildren !== []) {
            $body[':else'] = array_map(fn (Node $child): mixed => self::toSourceNode($child, $operatorStyle, $explicitTypedValues), $elseChildren);
        }

        return $body;
    }
}
