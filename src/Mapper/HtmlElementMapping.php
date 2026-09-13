<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class HtmlElementMapping
{
    private function __construct(public readonly string $tag)
    {
    }

    public static function tag(string $tag): self
    {
        return new self($tag);
    }
}
