<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class ReactComponent
{
    private function __construct(
        public readonly string $name,
        public readonly ?ReactImport $import = null,
    ) {
    }

    public static function local(string $name): self
    {
        return new self($name);
    }

    public static function imported(
        string $source,
        string $export,
        ?string $as = null,
        string $importKind = ReactImport::KIND_NAMED,
    ): self {
        $name = $as ?? $export;
        return new self($name, new ReactImport($source, $export, $name, $importKind));
    }
}
