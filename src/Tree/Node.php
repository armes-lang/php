<?php

declare(strict_types=1);

namespace Armes\Tree;

use InvalidArgumentException;

final class Node
{
    public const ELEMENT = 'element';
    public const RUNTIME = 'runtime';
    public const VALUE = 'value';
    public const FRAGMENT = 'fragment';
    public const LOGIC = 'logic';

    /**
     * @param array<string, mixed> $props
     * @param list<Node> $children
     * @param array<string, mixed> $meta
     * @param list<Node> $args
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $name = null,
        public readonly array $props = [],
        public readonly array $children = [],
        public readonly ?string $type = null,
        public readonly mixed $value = null,
        public readonly array $meta = [],
        public readonly ?string $op = null,
        public readonly array $args = [],
    ) {
        if (!in_array($kind, [self::ELEMENT, self::RUNTIME, self::VALUE, self::FRAGMENT, self::LOGIC], true)) {
            throw new InvalidArgumentException(sprintf('Unknown ARMES node kind "%s".', $kind));
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param list<Node> $children
     * @param array<string, mixed> $meta
     */
    public static function element(string $name, array $props = [], array $children = [], array $meta = []): self
    {
        return new self(self::ELEMENT, $name, $props, self::assertChildren($children), null, null, $meta);
    }

    /**
     * @param array<string, mixed> $props
     * @param list<Node> $children
     * @param array<string, mixed> $meta
     */
    public static function runtime(
        string $name,
        array $props = [],
        array $children = [],
        mixed $value = null,
        array $meta = [],
    ): self {
        return new self(self::RUNTIME, $name, $props, self::assertChildren($children), null, $value, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function value(string $type, mixed $value, array $meta = []): self
    {
        return new self(self::VALUE, null, [], [], $type, $value, $meta);
    }

    /**
     * @param list<Node> $children
     * @param array<string, mixed> $meta
     */
    public static function fragment(array $children = [], array $meta = []): self
    {
        return new self(self::FRAGMENT, null, [], self::assertChildren($children), null, null, $meta);
    }

    /**
     * @param list<Node> $args
     * @param array<string, mixed> $meta
     */
    public static function logic(string $op, array $args = [], array $meta = []): self
    {
        return new self(self::LOGIC, null, [], [], null, null, $meta, $op, self::assertChildren($args));
    }

    /**
     * @param array<string, mixed> $props
     */
    public function withProps(array $props): self
    {
        return new self($this->kind, $this->name, $props, $this->children, $this->type, $this->value, $this->meta, $this->op, $this->args);
    }

    /**
     * @param list<Node> $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->kind, $this->name, $this->props, self::assertChildren($children), $this->type, $this->value, $this->meta, $this->op, $this->args);
    }

    public function withValue(mixed $value): self
    {
        return new self($this->kind, $this->name, $this->props, $this->children, $this->type, $value, $this->meta, $this->op, $this->args);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->kind === self::VALUE) {
            $result = [
                'kind' => self::VALUE,
                'type' => $this->type,
                'value' => $this->valueToArray($this->value),
            ];
            if ($this->meta !== []) {
                $result['meta'] = $this->meta;
            }
            return $result;
        }

        if ($this->kind === self::ELEMENT) {
            $result = ['kind' => self::ELEMENT, 'name' => $this->name];
            if ($this->props !== []) {
                $result['props'] = $this->propsToArray($this->props);
            }
            if ($this->children !== []) {
                $result['children'] = array_map(static fn (self $child): array => $child->toArray(), $this->children);
            }
            if ($this->meta !== []) {
                $result['meta'] = $this->meta;
            }
            return $result;
        }

        if ($this->kind === self::RUNTIME) {
            $result = ['kind' => self::RUNTIME, 'name' => $this->name];
            if ($this->props !== []) {
                $result['props'] = $this->propsToArray($this->props);
            }
            if ($this->children !== []) {
                $result['children'] = array_map(static fn (self $child): array => $child->toArray(), $this->children);
            }
            if ($this->value !== null) {
                $result['value'] = $this->valueToArray($this->value);
            }
            if ($this->meta !== []) {
                $result['meta'] = $this->meta;
            }
            return $result;
        }

        if ($this->kind === self::LOGIC) {
            $result = ['kind' => self::LOGIC, 'op' => $this->op];
            if ($this->args !== []) {
                $result['args'] = array_map(static fn (self $arg): array => $arg->toArray(), $this->args);
            }
            if ($this->meta !== []) {
                $result['meta'] = $this->meta;
            }
            return $result;
        }

        $result = ['kind' => self::FRAGMENT];
        if ($this->children !== []) {
            $result['children'] = array_map(static fn (self $child): array => $child->toArray(), $this->children);
        }
        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kind = $data['kind'] ?? null;
        return match ($kind) {
            self::ELEMENT => self::element(
                self::expectString($data['name'] ?? null, 'element.name'),
                self::propsFromArray($data['props'] ?? []),
                self::childrenFromArray($data['children'] ?? []),
                self::expectArray($data['meta'] ?? []),
            ),
            self::RUNTIME => self::runtime(
                self::expectString($data['name'] ?? null, 'runtime.name'),
                self::propsFromArray($data['props'] ?? []),
                self::childrenFromArray($data['children'] ?? []),
                self::valueFromArray($data['value'] ?? null),
                self::expectArray($data['meta'] ?? []),
            ),
            self::VALUE => self::value(
                self::expectString($data['type'] ?? null, 'value.type'),
                self::valueFromArray($data['value'] ?? null),
                self::expectArray($data['meta'] ?? []),
            ),
            self::FRAGMENT => self::fragment(
                self::childrenFromArray($data['children'] ?? []),
                self::expectArray($data['meta'] ?? []),
            ),
            self::LOGIC => self::logic(
                self::expectString($data['op'] ?? null, 'logic.op'),
                self::childrenFromArray($data['args'] ?? []),
                self::expectArray($data['meta'] ?? []),
            ),
            default => throw new InvalidArgumentException('Invalid serialized ARMES node.'),
        };
    }

    /**
     * @param list<Node> $children
     * @return list<Node>
     */
    private static function assertChildren(array $children): array
    {
        foreach ($children as $child) {
            if (!$child instanceof self) {
                throw new InvalidArgumentException('ARMES node children must be Node instances.');
            }
        }
        return array_values($children);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function propsToArray(array $props): array
    {
        return array_map(fn (mixed $value): mixed => $this->valueToArray($value), $props);
    }

    private function valueToArray(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->valueToArray($item), $value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function propsFromArray(array $props): array
    {
        $result = [];
        foreach ($props as $key => $value) {
            $result[$key] = self::valueFromArray($value);
        }
        return $result;
    }

    private static function valueFromArray(mixed $value): mixed
    {
        if (is_array($value) && isset($value['kind'])) {
            return self::fromArray($value);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::valueFromArray($item), $value);
        }
        return $value;
    }

    /**
     * @param mixed $children
     * @return list<Node>
     */
    private static function childrenFromArray(mixed $children): array
    {
        if (!is_array($children)) {
            throw new InvalidArgumentException('Serialized children must be arrays.');
        }
        return array_map(static fn (array $child): self => self::fromArray($child), $children);
    }

    /**
     * @return array<string, mixed>
     */
    private static function expectArray(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected array value.');
        }
        return $value;
    }

    private static function expectString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Serialized %s must be a non-empty string.', $field));
        }
        return $value;
    }
}
