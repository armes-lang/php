<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
final readonly class CompiledReference
{
    /** target owns the consuming value plan. Operand edges are reads, never writes. */
    public function __construct(public string $id, public string $document, public StructuralAddress $source, public array $target, public array $provenance) {}
}
