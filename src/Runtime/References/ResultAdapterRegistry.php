<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
final readonly class ResultAdapterRegistry
{
    private function __construct(private array $adapters = []) {}
    public static function empty(): self { return new self(); }
    /** PHP providers are synchronous callables; no event loop is imposed on hosts. */
    public function with(string $kind, string $name, callable $adapter): self
    {
        if (!in_array($kind,['element','runtime'],true)) throw new \InvalidArgumentException('Adapters require an element or runtime name.');
        return new self([...$this->adapters, json_encode([$kind,$name])=>\Closure::fromCallable($adapter)]);
    }
    public function get(Node $node): ?\Closure { return $this->adapters[json_encode([$node->kind,$node->name])]??null; }
}
