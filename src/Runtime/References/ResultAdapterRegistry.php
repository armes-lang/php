<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
final readonly class ResultAdapterRegistry
{
    private function __construct(private array $adapters = [],private array $permissions=[]) {}
    public static function empty(): self { return new self(); }
    public function with(string $kind,string $name,callable $adapter,bool $referenceReadable=false):self {return $this->withOutput($kind,$name,'result',$adapter,$referenceReadable);}
    public function withOutput(string $kind,string $name,string $output,callable $adapter,bool $referenceReadable=false):self {
        if(!in_array($kind,['element','runtime'],true)||$output==='')throw new \InvalidArgumentException('Adapters require an element/runtime and output name.');
        $key=json_encode([$kind,$name,$output]);return new self([...$this->adapters,$key=>\Closure::fromCallable($adapter)],[...$this->permissions,$key=>$referenceReadable]);
    }
    public function get(Node $node,string $output='result'):?\Closure {return $this->adapters[json_encode([$node->kind,$node->name,$output])]??null;}
    public function referenceReadable(Node $node,string $output='result'):bool {return $this->permissions[json_encode([$node->kind,$node->name,$output])]??false;}
}
