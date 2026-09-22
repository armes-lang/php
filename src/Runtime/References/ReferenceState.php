<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
/** @internal Session-owned identity and correspondence tables; never source data. */
final class ReferenceState
{
    public \WeakMap $ids;
    public array $pins = [], $locations = [], $locationPaths = [], $imports = [], $versions = [], $generatedPins = [];
    private int $next = 0;
    public function __construct() { $this->ids = new \WeakMap(); }
    public function id(Node $node): string { return $this->ids[$node] ??= 'n'.++$this->next; }
    public function location(string $node, array $path): string {
        $key=json_encode([$node,$path]);
        if (!isset($this->locations[$key])) { $this->locations[$key]='l'.++$this->next; $this->locationPaths[$this->locations[$key]]=$path; }
        return $this->locations[$key];
    }
    public function copy(Node $node, bool $identity = true): Node {
        $next = ReferenceTree::map($node, fn(Node $n):Node=>$this->copy($n,$identity));
        if ($identity) $this->ids[$next]=$this->id($node);
        return $next;
    }
}
