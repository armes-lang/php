<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class ReactImport
{
    public const KIND_NAMED = 'named';
    public const KIND_DEFAULT = 'default';
    public const KIND_NAMESPACE = 'namespace';

    public function __construct(
        public readonly string $source,
        public readonly string $export,
        public readonly string $as,
        public readonly string $kind = self::KIND_NAMED,
    ) {
    }

    public function key(): string
    {
        return implode("\0", [$this->kind, $this->source, $this->export, $this->as]);
    }

    public function statement(): string
    {
        return match ($this->kind) {
            self::KIND_DEFAULT => sprintf('import %s from "%s";', $this->as, $this->source),
            self::KIND_NAMESPACE => sprintf('import * as %s from "%s";', $this->as, $this->source),
            default => $this->export === $this->as
                ? sprintf('import { %s } from "%s";', $this->export, $this->source)
                : sprintf('import { %s as %s } from "%s";', $this->export, $this->as, $this->source),
        };
    }
}
