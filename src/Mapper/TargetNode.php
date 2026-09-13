<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class TargetNode
{
    public const ELEMENT = 'element';
    public const TEXT = 'text';
    public const RAW_TEXT = 'raw_text';
    public const COMMENT = 'comment';
    public const DOCTYPE = 'doctype';
    public const FRAGMENT = 'fragment';

    /**
     * @param array<string, mixed> $props
     * @param list<TargetNode> $children
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $name = null,
        public readonly array $props = [],
        public readonly array $children = [],
        public readonly mixed $value = null,
    ) {
    }

    /**
     * @param array<string, mixed> $props
     * @param list<TargetNode> $children
     */
    public static function element(string $name, array $props = [], array $children = []): self
    {
        return new self(self::ELEMENT, $name, $props, $children);
    }

    public static function text(mixed $value): self
    {
        return new self(self::TEXT, null, [], [], $value);
    }

    public static function rawText(mixed $value): self
    {
        return new self(self::RAW_TEXT, null, [], [], $value);
    }

    public static function comment(string $value): self
    {
        return new self(self::COMMENT, null, [], [], $value);
    }

    public static function doctype(string $value): self
    {
        return new self(self::DOCTYPE, null, [], [], $value);
    }

    /**
     * @param list<TargetNode> $children
     */
    public static function fragment(array $children): self
    {
        return new self(self::FRAGMENT, null, [], $children);
    }
}
