<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
use Armes\Emitter\{SourceSerializer,YamlEmitter,TomlEmitter};
use Armes\Mapper\{MapperInterface,MappingContext};

/** Opt-in references. The ordinary parser, resolver and mapper APIs are unchanged. */
final class ReferenceSession
{
    private ReferenceState $state;
    private Node $tree;
    private int $revision=1;
    private ?array $snapshot=null;
    private array $listeners=[];
    public function __construct(Node $tree,private array $options=[]) {
        $this->state=new ReferenceState();$this->tree=$this->state->copy($tree);
    }
    public function tree():Node{return $this->tree;}
    public function snapshot():?array{return $this->snapshot;}
    public function authoredRevision():int{return $this->revision;}
    public function nodeId(Node $node):string{return $this->state->id($node);}
    public function locationPath(string $id):?array{return $this->state->locationPaths[$id]??null;}
    public function addressOf(Node|string $node):StructuralAddress {
        $id=is_string($node)?$node:$this->nodeId($node);$index=new ReferenceIndex($this->tree,$this->state);
        return $index->entries[$id]['address']??throw new ReferenceException('missing-reference','Node is not in this document.');
    }
    private function engine():ReferenceEngine{return new ReferenceEngine($this->tree,$this->state,$this->revision,$this->options);}
    public function compile():array{return $this->engine()->compile();}
    public function resolve():array {
        if(($this->snapshot['revision']??null)===$this->revision)return $this->snapshot;
        $this->snapshot=$this->engine()->run();foreach($this->listeners as $listener)$listener($this->snapshot);return $this->snapshot;
    }
    public function subscribe(callable $listener):\Closure {
        $id=count($this->listeners);while(isset($this->listeners[$id]))$id++;$this->listeners[$id]=$listener;
        return function()use($id){unset($this->listeners[$id]);};
    }
    /** @param list<array{0:Node,1:Node}> $correspondence */
    public function transaction(Node $tree,array $correspondence=[],?array $context=null):int {
        $this->compile();$before=new ReferenceIndex($this->tree,$this->state);
        foreach($correspondence as [$old,$next])$this->state->ids[$next]=$this->state->id($old);
        $this->tree=$this->state->copy($tree);$after=new ReferenceIndex($this->tree,$this->state);
        foreach($this->state->pins as &$pin) {
            $old=$before->entries[$pin['node']]['node']??null;$next=$after->entries[$pin['node']]['node']??null;if(!$old||!$next)continue;
            foreach($pin['path'] as $i=>$part)if(is_int($part)){
                try{if(ReferenceTree::get($old,array_slice($pin['path'],0,$i))!==ReferenceTree::get($next,array_slice($pin['path'],0,$i)))$pin['stale']=true;}catch(ReferenceException){$pin['stale']=true;}break;
            }
        }unset($pin);
        if($context!==null)$this->options['context']=$context;return ++$this->revision;
    }
    public function fork():self {
        $next=clone $this;$next->state=clone $this->state;$next->state->ids=clone $this->state->ids;$next->listeners=[];return $next;
    }
    public function bind(string|StructuralAddress $target,string $source):void {StructuralAddress::parse($source);$this->editBinding($target,[':ref'=>$source]);}
    public function unbind(string|StructuralAddress $target,mixed $replacement):void {$this->editBinding($target,$replacement);}
    private function editBinding(string|StructuralAddress $target,mixed $replacement):void {
        $index=new ReferenceIndex($this->tree,$this->state);$found=$index->locate(is_string($target)?StructuralAddress::parse($target):$target);$path=$found['path'];$node=$found['entry']['node'];
        $direct=count($path)===2&&$path[0]==='props'&&($node->kind===Node::ELEMENT||$node->kind===Node::RUNTIME&&in_array($node->name,['if','each','import','include'],true));
        $direct=$direct||count($path)===3&&array_slice($path,0,2)===['props','props']&&$node->kind===Node::RUNTIME&&in_array($node->name,['import','include'],true);
        if($node->kind===Node::VALUE||!in_array($path[0]??null,['props','value'],true)||(!$direct&&!(isset($this->options['isExpressionLocation'])&&($this->options['isExpressionLocation'])($node,$path))))throw new ReferenceException('not-bindable','Location cannot accept a reference.');
        for($i=1;$i<count($path);$i++){$parent=ReferenceTree::get($node,array_slice($path,0,$i));if($parent instanceof Node&&$parent->kind===Node::VALUE)throw new ReferenceException('not-bindable','Typed literals are opaque.');}
        $pairs=[];
        $rewrite=function(Node $n)use(&$rewrite,$node,$path,$replacement,&$pairs):Node {
            $next=ReferenceTree::map($n,$rewrite);if($n===$node)$next=ReferenceTree::set($next,$path,$replacement);$pairs[]=[$n,$next];return $next;
        };
        $tree=$rewrite($this->tree);$key='document:'.$this->nodeId($node).':'.json_encode($path);
        $this->transaction($tree,$pairs);unset($this->state->pins[$key],$this->state->generatedPins[$key]);
    }
    public function duplicate(string|StructuralAddress $address):string {
        $engine=$this->engine();$engine->compile();$found=$engine->index->locate(is_string($address)?StructuralAddress::parse($address):$address);
        if($found['path']||isset($found['result']))throw new ReferenceException('not-structural','Only authored subtrees can be copied.');
        $original=$found['entry']['node'];$copies=[];
        $copy=function(Node $node)use(&$copy,&$copies):Node{$next=ReferenceTree::map($node,$copy);$copies[$this->nodeId($node)]=$this->nodeId($next);return $next;};
        $duplicate=$copy($original);$pairs=[];
        $rewrite=function(Node $n)use(&$rewrite,$original,$duplicate,&$pairs):Node {
            $next=ReferenceTree::map($n,$rewrite);
            $children=$n->kind===Node::LOGIC?$n->args:$n->children;
            if(($i=array_search($original,$children,true))!==false){$changed=$n->kind===Node::LOGIC?$next->args:$next->children;array_splice($changed,$i+1,0,[$duplicate]);$next=$n->kind===Node::LOGIC?Node::logic((string)$n->op,$changed,$n->meta):$next->withChildren($changed);}
            if($n->kind===Node::RUNTIME&&is_array($n->props['else']??null)&&($i=array_search($original,$n->props['else'],true))!==false){$props=$next->props;array_splice($props['else'],$i+1,0,[$duplicate]);$next=$next->withProps($props);}
            $pairs[]=[$n,$next];return $next;
        };
        $next=$rewrite($this->tree);if($original===$this->tree)$next=Node::fragment([$next,$duplicate]);
        $pins=[];foreach($engine->plans as $id=>$plan)if(isset($copies[$this->nodeId($plan['owner'])],$this->state->pins[$id])){$pin=$this->state->pins[$id];$pin['node']=$copies[$pin['node']]??$pin['node'];$pins['document:'.$copies[$this->nodeId($plan['owner'])].':'.json_encode($plan['path'])]=$pin;}
        $this->transaction($next,$pairs);$this->state->pins=[...$this->state->pins,...$pins];return $this->nodeId($duplicate);
    }
    public function sourceTree():Node {
        $engine=$this->engine();$refs=$engine->compile();$updates=[];
        foreach($refs as $ref) {
            $pin=$this->state->pins[$ref->id]??null;if(!$pin)continue;
            if(($pin['stale']??false))throw new ReferenceException('unserializable-reference','Stale reference requires rebinding.');
            $entry=$engine->index->entries[$pin['node']]??null;if(!$entry)throw new ReferenceException('unserializable-reference','Referenced source was deleted.');
            if(isset($this->state->generatedPins[$ref->id])&&($this->snapshot['revision']??null)!==$this->revision)throw new ReferenceException('unserializable-reference','Evaluate the current revision before saving a generated selection.');
            if(($this->snapshot['revision']??null)===$this->revision&&array_filter($this->snapshot['diagnostics'],fn($d)=>$d['code']==='stale-reference'))throw new ReferenceException('unserializable-reference','Stale generated reference.');
            $spelling=$ref->provenance['authored'];
            try{$located=$engine->index->locate($ref->source);if($located['entry']['id']!==$pin['node']||$located['path']!==$pin['path']||($located['result']??null)!==($pin['result']??null))$spelling=(string)$engine->index->address($entry,$pin['path'],$pin['result']);}
            catch(ReferenceException){$spelling=(string)$engine->index->address($entry,$pin['path'],$pin['result']);}
            $plan=$engine->plans[$ref->id];$updates[$this->nodeId($plan['owner'])][]=[$plan['path'],$spelling];
        }
        $rewrite=function(Node $node)use(&$rewrite,$updates):Node {
            $next=ReferenceTree::map($node,$rewrite);
            foreach($updates[$this->nodeId($node)]??[] as [$path,$spelling]){
                $slot=ReferenceTree::get($next,$path);
                if($slot instanceof Node&&$slot->kind===Node::RUNTIME&&$slot->name==='ref'){
                    if($slot->value===null&&count($slot->children)===1)$slot=$slot->withChildren([$slot->children[0]->withValue($spelling)]);else $slot=$slot->withValue($spelling);
                }else $slot=[':ref'=>$spelling];
                $next=ReferenceTree::set($next,$path,$slot);
            }
            return $next;
        };return $rewrite($this->tree);
    }
    public function source(string $format='json'):string {
        $data=SourceSerializer::toSourceNode($this->sourceTree());
        return match($format){'json'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),'yaml'=>(new YamlEmitter())->emitData($data),'toml'=>(new TomlEmitter())->emitData($data),default=>throw new \InvalidArgumentException('Unsupported source format.')};
    }
    public function materialize(?array $snapshot=null):Node {
        $snapshot??=$this->resolve();if($snapshot['status']!=='ready')throw new ReferenceException('evaluation-error',implode("\n",array_column($snapshot['diagnostics'],'message')));
        $active = new \SplObjectStorage();
        $materialize = function (mixed $value) use (&$materialize, $active): mixed {
            if (is_array($value)) return array_map($materialize, $value);
            if (!is_object($value)) return $value;
            if ($active->contains($value)) throw new ReferenceException('dependency-cycle', 'Structural materialization cycle.');
            $active->attach($value);
            try {
                if ($value instanceof StructuralView) return $materialize($value->materialize());
                if ($value instanceof \stdClass) return (object) array_map($materialize, get_object_vars($value));
                if (!$value instanceof Node) return $value;
                return match ($value->kind) {
                    Node::ELEMENT => Node::element((string) $value->name, $materialize($value->props), $materialize($value->children), $value->meta),
                    Node::RUNTIME => Node::runtime((string) $value->name, $materialize($value->props), $materialize($value->children), $materialize($value->value), $value->meta),
                    Node::LOGIC => Node::logic((string) $value->op, $materialize($value->args), $value->meta),
                    Node::FRAGMENT => Node::fragment($materialize($value->children), $value->meta),
                    default => Node::value((string) $value->type, $materialize($value->value), $value->meta),
                };
            } finally {
                $active->detach($value);
            }
        };
        return $materialize($snapshot['tree']);
    }

    public function map(MapperInterface $mapper,string $target='default'):mixed{return $mapper->map($this->materialize(),new MappingContext($target,true,$this->options['context']??[]));}
}
