<?php

declare(strict_types=1);

namespace Armes\Render;

use Armes\Exception\MappingException;

final class RenderTargetRegistry
{
    /**
     * @param array<string, RenderTarget> $targets
     */
    public function __construct(private readonly array $targets = [])
    {
    }

    public function register(string $name, RenderTarget $target): self
    {
        return $this->with($name, $target);
    }

    public function with(string $name, RenderTarget $target): self
    {
        $targets = $this->targets;
        $targets[$name] = $target;
        return new self($targets);
    }

    public function get(string $name): RenderTarget
    {
        if (!$this->has($name)) {
            throw new MappingException(sprintf('Unknown render target "%s".', $name));
        }

        return $this->targets[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->targets[$name]);
    }
}
