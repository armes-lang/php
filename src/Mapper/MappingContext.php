<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class MappingContext
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $runtimeContext
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $target,
        public readonly bool $strict = true,
        public readonly array $runtimeContext = [],
        public readonly array $options = [],
    ) {
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
