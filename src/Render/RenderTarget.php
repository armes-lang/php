<?php

declare(strict_types=1);

namespace Armes\Render;

use Armes\Emitter\EmitterInterface;
use Armes\Mapper\MapperInterface;
use Armes\Mapper\MappingContext;
use Armes\Tree\Node;

final class RenderTarget
{
    private function __construct(
        private readonly MapperInterface $mapper,
        private readonly EmitterInterface $emitter,
    ) {
    }

    public static function make(MapperInterface $mapper, EmitterInterface $emitter): self
    {
        return new self($mapper, $emitter);
    }

    public function mapper(): MapperInterface
    {
        return $this->mapper;
    }

    public function emitter(): EmitterInterface
    {
        return $this->emitter;
    }

    public function render(Node $tree, MappingContext $context): string
    {
        return $this->emitter->emit($this->mapper->map($tree, $context));
    }
}
