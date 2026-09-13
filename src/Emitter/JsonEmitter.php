<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Tree\Node;

final class JsonEmitter
{
    public const MODE_CANONICAL = 'canonical';
    public const MODE_COMPACT = 'compact';
    public const MODE_TAGGED = 'tagged';

    /** @var array<string, true> */
    private const RAW_TEXT_ELEMENTS = [
        'script' => true,
        'style' => true,
    ];

    public function emitTree(Node $node, bool $pretty = true, string $mode = self::MODE_CANONICAL): string
    {
        return json_encode(
            $this->toData($node, $mode),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0),
        );
    }

    public function emitCompactTree(Node $node, bool $pretty = false): string
    {
        return $this->emitTree($node, $pretty, self::MODE_COMPACT);
    }

    public function emitTaggedTree(Node $node, bool $pretty = true): string
    {
        return $this->emitTree($node, $pretty, self::MODE_TAGGED);
    }

    public function toData(Node $node, string $mode = self::MODE_CANONICAL): mixed
    {
        return match ($mode) {
            self::MODE_CANONICAL => $node->toArray(),
            self::MODE_COMPACT => $this->compactNode($node),
            self::MODE_TAGGED => $this->taggedNode($node),
            default => throw new \InvalidArgumentException(sprintf('Unknown JSON emit mode "%s".', $mode)),
        };
    }

    private function compactNode(Node $node, ?string $parentName = null): mixed
    {
        return match ($node->kind) {
            Node::FRAGMENT => array_map(fn (Node $child): mixed => $this->compactNode($child, $parentName), $node->children),
            Node::ELEMENT => $this->compactElement($node),
            Node::RUNTIME => $this->compactRuntime($node),
            Node::VALUE => $this->compactValue($node, $parentName),
            Node::LOGIC => [':logic:' . $node->op => $this->logicArgs($node, self::MODE_COMPACT)],
            default => null,
        };
    }

    private function compactElement(Node $node): array
    {
        $name = (string) $node->name;
        $children = array_map(fn (Node $child): mixed => $this->compactNode($child, strtolower($name)), $node->children);

        if ($node->props === []) {
            if ($children === []) {
                return [$name => []];
            }

            return [$name => count($children) === 1 ? $children[0] : $children];
        }

        $body = ['@' => $this->compactProps($node->props)];
        if ($children !== []) {
            $body['#'] = $children;
        }

        return [$name => $body];
    }

    private function compactRuntime(Node $node): array
    {
        $body = $this->runtimeBody($node, self::MODE_COMPACT);
        return [':' . $node->name => $body];
    }

    private function compactValue(Node $node, ?string $parentName): mixed
    {
        if ($node->type === 'raw' && $parentName !== null && isset(self::RAW_TEXT_ELEMENTS[$parentName])) {
            return $node->value;
        }

        return match ($node->type) {
            'string', 'int', 'float', 'bool', 'null' => $node->value,
            'comment' => [':comment' => $node->value],
            'doctype' => [':doctype' => $node->value],
            'cdata' => [':cdata' => $node->value],
            'raw' => [':raw' => $node->value],
            'array' => [':array' => $node->value],
            'object' => [':object' => $node->value],
            default => [':' . $node->type => $node->value],
        };
    }

    private function taggedNode(Node $node, ?string $parentName = null): mixed
    {
        return match ($node->kind) {
            Node::FRAGMENT => array_map(fn (Node $child): mixed => $this->taggedNode($child, $parentName), $node->children),
            Node::ELEMENT => $this->taggedElement($node),
            Node::RUNTIME => [':' . $node->name => $this->runtimeBody($node, self::MODE_TAGGED)],
            Node::VALUE => [':' . ($node->type === 'string' ? 'string' : $node->type) => $node->value],
            Node::LOGIC => [':logic:' . $node->op => $this->logicArgs($node, self::MODE_TAGGED)],
            default => null,
        };
    }

    private function taggedElement(Node $node): array
    {
        $name = (string) $node->name;
        $body = [];
        if ($node->props !== []) {
            $body['@'] = $this->taggedProps($node->props);
        }
        if ($node->children !== []) {
            $body['#'] = array_map(fn (Node $child): mixed => $this->taggedNode($child, strtolower($name)), $node->children);
        }

        return [$name => $body];
    }

    private function runtimeBody(Node $node, string $mode): mixed
    {
        if ($node->props === [] && $node->children === []) {
            return $this->plainValue($node->value, $mode);
        }

        $body = [];
        if ($node->props !== []) {
            $body['@'] = $mode === self::MODE_TAGGED
                ? $this->taggedProps($node->props)
                : $this->compactProps($node->props);
        }
        if ($node->children !== []) {
            $body['#'] = array_map(
                fn (Node $child): mixed => $mode === self::MODE_TAGGED ? $this->taggedNode($child) : $this->compactNode($child),
                $node->children,
            );
        }
        if ($node->value !== null) {
            $body['value'] = $this->plainValue($node->value, $mode);
        }

        return $body;
    }

    private function logicArgs(Node $node, string $mode): mixed
    {
        $args = array_map(
            fn (Node $arg): mixed => $mode === self::MODE_TAGGED ? $this->taggedNode($arg) : $this->compactNode($arg),
            $node->args,
        );

        return $node->op === 'var' && count($args) === 1 ? $args[0] : $args;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function compactProps(array $props): array
    {
        $result = [];
        foreach ($props as $key => $value) {
            $result[$key] = $this->plainValue($value, self::MODE_COMPACT);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function taggedProps(array $props): array
    {
        $result = [];
        foreach ($props as $key => $value) {
            $result[$key] = $this->plainValue($value, self::MODE_TAGGED);
        }
        return $result;
    }

    private function plainValue(mixed $value, string $mode): mixed
    {
        if ($value instanceof Node) {
            return $mode === self::MODE_TAGGED ? $this->taggedNode($value) : $this->compactNode($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = $this->plainValue($child, $mode);
            }
            return $result;
        }

        return $value;
    }
}
