<?php

declare(strict_types=1);

namespace Armes\Mapper;

use Armes\Exception\MappingException;
use Armes\Tree\Node;

final class ReactMapper implements MapperInterface
{
    /** @var array<string, ReactComponent> */
    private readonly array $components;

    /**
     * @param array<string, ReactComponent> $components
     */
    public function __construct(
        private readonly bool $strict = true,
        array $components = [],
    ) {
        $this->components = $components;
    }

    public static function make(bool $strict = true): self
    {
        return new self($strict);
    }

    public function component(string $name, ReactComponent $component): self
    {
        $components = $this->components;
        $components[$name] = $component;
        return new self($this->strict, $components);
    }

    public function map(Node $node, ?MappingContext $context = null): JsxDocument
    {
        $imports = [];
        $root = $this->mapNode($node, $context, $imports);

        return new JsxDocument($root, array_values($imports));
    }

    /**
     * @param array<string, ReactImport> $imports
     */
    private function mapNode(Node $node, ?MappingContext $context, array &$imports): TargetNode
    {
        return match ($node->kind) {
            Node::FRAGMENT => TargetNode::fragment($this->mapChildren($node->children, $context, $imports)),
            Node::ELEMENT => $this->mapElement($node, $context, $imports),
            Node::VALUE => $this->mapValue($node),
            Node::RUNTIME => $this->handleRuntime($node, $context),
            Node::LOGIC => $this->handleLogic($node, $context),
            default => throw new MappingException(sprintf('React mapper cannot map node kind "%s".', $node->kind)),
        };
    }

    /**
     * @param array<string, ReactImport> $imports
     */
    private function mapElement(Node $node, ?MappingContext $context, array &$imports): TargetNode
    {
        $name = (string) $node->name;
        $component = $this->components[$name] ?? null;
        if ($component instanceof ReactComponent) {
            $name = $component->name;
            if ($component->import instanceof ReactImport) {
                $imports[$component->import->key()] = $component->import;
            }
        }

        return TargetNode::element(
            $name,
            $this->mapProps($node->props),
            $this->mapChildren($node->children, $context, $imports),
        );
    }

    /**
     * @param list<Node> $children
     * @param array<string, ReactImport> $imports
     * @return list<TargetNode>
     */
    private function mapChildren(array $children, ?MappingContext $context, array &$imports): array
    {
        $mapped = [];
        foreach ($children as $child) {
            $mapped[] = $this->mapNode($child, $context, $imports);
        }
        return $mapped;
    }

    private function mapValue(Node $node): TargetNode
    {
        if ($node->type === 'comment') {
            return TargetNode::comment($this->stringify($node->value));
        }

        if ($node->type === 'doctype') {
            return TargetNode::fragment([]);
        }

        if ($node->type === 'raw') {
            return TargetNode::rawText($this->stringify($node->value));
        }

        return TargetNode::text($this->stringify($node->value));
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function mapProps(array $props): array
    {
        if (array_key_exists('class', $props) && !array_key_exists('className', $props)) {
            $props['className'] = $props['class'];
            unset($props['class']);
        }
        return $props;
    }

    private function handleRuntime(Node $node, ?MappingContext $context): TargetNode
    {
        if ($context?->strict ?? $this->strict) {
            throw new MappingException(sprintf('React mapper received unresolved runtime node ":%s".', $node->name));
        }
        return TargetNode::fragment([]);
    }

    private function handleLogic(Node $node, ?MappingContext $context): TargetNode
    {
        if ($context?->strict ?? $this->strict) {
            throw new MappingException(sprintf('React mapper received unresolved logic node "%s".', $node->op));
        }
        return TargetNode::fragment([]);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return (string) $value;
    }
}
