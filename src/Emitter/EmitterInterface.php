<?php

declare(strict_types=1);

namespace Armes\Emitter;

interface EmitterInterface
{
    public function emit(mixed $node): string;
}
