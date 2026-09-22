<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
final class StructuralView
{
    public readonly string $kind;
    private ?Node $tree = null;
    public function __construct(public readonly array $source, public readonly int $revision, private readonly \Closure $resolve) { $this->kind='structure'; }
    public function materialize(): Node { return $this->tree ??= ($this->resolve)(); }
}
