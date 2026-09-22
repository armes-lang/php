<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Runtime\LogicOperators;
use Armes\Runtime\ValueTypes;
use Armes\Tree\Node;

final class ArmesEmitter
{
    /** @var array<string, true> */
    private const TYPED_VALUE_TAGS = [
        'string' => true,
        'int' => true,
        'float' => true,
        'bool' => true,
        'null' => true,
        'array' => true,
        'object' => true,
    ];

    public function emitTree(Node $node, bool $pretty = false, string $operatorStyle = 'readable', string $indent = "\t", bool $explicitTypedValues = false): string
    {
        return $this->emitNode($node, 0, $pretty, $operatorStyle, $indent, $explicitTypedValues);
    }

    private function emitNode(Node $node, int $depth, bool $pretty, string $operatorStyle, string $indent, bool $explicitTypedValues): string
    {
        return match ($node->kind) {
            Node::FRAGMENT => implode($pretty ? "\n" : '', array_map(fn (Node $child): string => $this->emitNode($child, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues), $node->children)),
            Node::ELEMENT => $this->emitTag((string) $node->name, $node->props, $node->children, $depth, $pretty, $operatorStyle, $indent, null, false, $explicitTypedValues),
            Node::RUNTIME => $this->emitRuntime($node, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues),
            Node::VALUE => $this->emitValue($node, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues),
            Node::LOGIC => $this->emitLogic($node, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues),
            default => '',
        };
    }

    private function emitRuntime(Node $node, int $depth, bool $pretty, string $operatorStyle, string $indent, bool $explicitTypedValues): string
    {
        if ($node->name === 'if') {
            $props = $node->props;
            $elseChildren = isset($props['else']) && is_array($props['else']) ? $props['else'] : [];
            unset($props['else']);
            $children = [];
            foreach ($node->children as $child) {
                if ($child->kind === Node::RUNTIME && $child->name === 'else') {
                    if ($elseChildren === []) {
                        $elseChildren = $child->children;
                    }
                    continue;
                }
                $children[] = $child;
            }
            if ($elseChildren !== []) {
                $children[] = Node::runtime('else', [], $elseChildren);
            }
            return $this->emitTag(':if', $props, $children, $depth, $pretty, $operatorStyle, $indent, null, false, $explicitTypedValues);
        }

        $props = in_array($node->name, ['props', 'attributes'], true) && is_array($node->value)
            ? $node->value
            : $node->props;
        $fallback = in_array($node->name, ['props', 'attributes'], true) && is_array($node->value)
            ? null
            : $node->value;

        return $this->emitTag(':' . $node->name, $props, $node->children, $depth, $pretty, $operatorStyle, $indent, $fallback, false, $explicitTypedValues);
    }

    private function emitLogic(Node $node, int $depth, bool $pretty, string $operatorStyle, string $indent, bool $explicitTypedValues): string
    {
        return $this->emitTag(LogicOperators::tag((string) $node->op, $operatorStyle), [], $node->args, $depth, $pretty, $operatorStyle, $indent, null, true, $explicitTypedValues);
    }

    private function emitValue(Node $node, int $depth, bool $pretty, string $operatorStyle, string $indent, bool $explicitTypedValues): string
    {
        $prefix = $pretty ? str_repeat($indent, $depth) : '';
        if (($explicitTypedValues || in_array($node->type, ['object', 'array'], true)) && ValueTypes::isCanonical((string) $node->type)) {
            return $this->emitTag(ValueTypes::tag((string) $node->type), [], [Node::value('string', $this->valueText($node->value))], $depth, $pretty, $operatorStyle, $indent, null, false, $explicitTypedValues);
        }

        return match ($node->type) {
            'comment' => $prefix . '<!--' . str_replace('--', '- -', (string) $node->value) . '-->',
            'doctype' => $prefix . '<!DOCTYPE ' . (string) ($node->value ?? 'html') . '>',
            'cdata' => $prefix . '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $node->value) . ']]>',
            'raw' => $prefix . (string) $node->value,
            default => $prefix . $this->escapeText($this->valueText($node->value)),
        };
    }

    /**
     * @param array<string, mixed> $props
     * @param list<Node> $children
     */
    private function emitTag(
        string $name,
        array $props,
        array $children,
        int $depth,
        bool $pretty,
        string $operatorStyle,
        string $indent,
        mixed $fallback = null,
        bool $logicChildren = false,
        bool $explicitTypedValues = false,
    ): string {
        $prefix = $pretty ? str_repeat($indent, $depth) : '';
        $open = '<' . $name . $this->attributes($props, $operatorStyle, $explicitTypedValues) . '>';
        $close = '</' . $name . '>';
        $effectiveChildren = $children !== [] ? $children : ($fallback !== null ? [Node::value('string', $this->valueText($fallback))] : []);

        if ($effectiveChildren === []) {
            return $prefix . $open . $close;
        }

        if (!$logicChildren && count($effectiveChildren) === 1 && $effectiveChildren[0]->kind === Node::VALUE) {
            return $prefix . $open . $this->escapeText($this->valueText($effectiveChildren[0]->value)) . $close;
        }

        if (!$pretty) {
            return $open . implode('', array_map(fn (Node $child): string => $logicChildren
                ? $this->emitLogicArg($child, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues)
                : $this->emitNode($child, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues), $effectiveChildren)) . $close;
        }

        $body = implode("\n", array_map(fn (Node $child): string => $logicChildren
            ? $this->emitLogicArg($child, $depth + 1, $pretty, $operatorStyle, $indent, $explicitTypedValues)
            : $this->emitNode($child, $depth + 1, $pretty, $operatorStyle, $indent, $explicitTypedValues), $effectiveChildren));

        return $prefix . $open . "\n" . $body . "\n" . $prefix . $close;
    }

    private function emitLogicArg(Node $node, int $depth, bool $pretty, string $operatorStyle, string $indent, bool $explicitTypedValues): string
    {
        if ($node->kind !== Node::VALUE || !isset(self::TYPED_VALUE_TAGS[(string) $node->type])) {
            return $this->emitNode($node, $depth, $pretty, $operatorStyle, $indent, $explicitTypedValues);
        }

        return $this->emitTag(ValueTypes::tag((string) $node->type), [], [Node::value('string', $this->valueText($node->value))], $depth, $pretty, $operatorStyle, $indent, null, false, $explicitTypedValues);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function attributes(array $props, string $operatorStyle, bool $explicitTypedValues): string
    {
        $parts = [];
        foreach ($props as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $parts[] = $name;
                continue;
            }

            $sourceValue = SourceSerializer::toSourceValue($value, $operatorStyle, $explicitTypedValues);
            $text = is_string($sourceValue) ? $sourceValue : json_encode($sourceValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $quote = is_scalar($sourceValue) || $sourceValue === null ? '"' : "'";
            $parts[] = $name . '=' . $quote . $this->escapeAttribute((string) $text, $quote) . $quote;
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    private function valueText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_array($value) || is_object($value)
            ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            : (string) $value;
    }

    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $value, string $quote): string
    {
        $escaped = htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        return $quote === "'"
            ? str_replace("'", '&#39;', $escaped)
            : str_replace('"', '&quot;', $escaped);
    }
}
