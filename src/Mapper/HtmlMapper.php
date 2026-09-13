<?php

declare(strict_types=1);

namespace Armes\Mapper;

use Armes\Exception\MappingException;
use Armes\Tree\Node;

final class HtmlMapper implements MapperInterface
{
    /** @var array<string, true> */
    private const RAW_TEXT_ELEMENTS = [
        'script' => true,
        'style' => true,
    ];

    /** @var array<string, HtmlElementMapping> */
    private readonly array $elements;

    /**
     * @param array<string, HtmlElementMapping> $elements
     */
    public function __construct(
        private readonly bool $strict = true,
        array $elements = [],
    ) {
        $this->elements = $elements;
    }

    public static function make(bool $strict = true): self
    {
        return new self($strict);
    }

    public function element(string $name, HtmlElementMapping $mapping): self
    {
        $elements = $this->elements;
        $elements[$name] = $mapping;
        return new self($this->strict, $elements);
    }

    public function map(Node $node, ?MappingContext $context = null): TargetNode
    {
        return $this->mapNode($node, null, $context);
    }

    private function mapNode(Node $node, ?string $parentElement, ?MappingContext $context): TargetNode
    {
        return match ($node->kind) {
            Node::FRAGMENT => TargetNode::fragment(array_map(fn (Node $child): TargetNode => $this->mapNode($child, null, $context), $node->children)),
            Node::ELEMENT => $this->mapElement($node, $context),
            Node::VALUE => $this->mapValue($node, $parentElement),
            Node::RUNTIME => $this->handleRuntime($node, $context),
            Node::LOGIC => $this->handleLogic($node, $context),
            default => throw new MappingException(sprintf('HTML mapper cannot map node kind "%s".', $node->kind)),
        };
    }

    private function mapElement(Node $node, ?MappingContext $context): TargetNode
    {
        $name = (string) $node->name;
        $mappedName = $this->elements[$name]->tag ?? $name;
        $parentName = strtolower($mappedName);

        return TargetNode::element(
            $mappedName,
            $node->props,
            array_map(fn (Node $child): TargetNode => $this->mapNode($child, $parentName, $context), $node->children),
        );
    }

    private function mapValue(Node $node, ?string $parentElement): TargetNode
    {
        if ($node->type === 'comment') {
            return TargetNode::comment($this->stringify($node->value));
        }

        if ($node->type === 'doctype') {
            return TargetNode::doctype($this->stringify($node->value));
        }

        if ($node->type === 'raw' || ($parentElement !== null && isset(self::RAW_TEXT_ELEMENTS[$parentElement]))) {
            return TargetNode::rawText($this->stringify($node->value));
        }

        return TargetNode::text($this->stringify($node->value));
    }

    private function handleRuntime(Node $node, ?MappingContext $context): TargetNode
    {
        if ($context?->strict ?? $this->strict) {
            throw new MappingException(sprintf('HTML mapper received unresolved runtime node ":%s".', $node->name));
        }
        return TargetNode::fragment([]);
    }

    private function handleLogic(Node $node, ?MappingContext $context): TargetNode
    {
        if ($context?->strict ?? $this->strict) {
            throw new MappingException(sprintf('HTML mapper received unresolved logic node "%s".', $node->op));
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
