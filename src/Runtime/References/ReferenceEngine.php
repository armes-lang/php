<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
use Armes\Runtime\{RuntimeResolver, LogicOperators, ValueTypes};
use Armes\Emitter\SourceSerializer;

/** @internal Compiler/evaluator using the existing runtime's directive handlers. */
final class ReferenceEngine extends RuntimeResolver
{
    public ReferenceIndex $index;
    public array $references=[], $plans=[], $edges=[], $results=[], $diagnostics=[];
    private array $compiledScopes=[];
    private array $scope, $values=[], $busy=[], $activeOwners=[], $groups=[];
    public function __construct(Node $root,private ReferenceState $state,private int $revision,private array $options=[]) {
        parent::__construct(true,$options['parser']??null);
        $this->index=new ReferenceIndex($root,$state);
        $this->scope=['id'=>'document','index'=>$this->index,'context'=>$options['context']??[],'stack'=>[],'parent'=>null,'iteration'=>null];
    }
    private function runtimeId(Node $n,?array $scope=null):string {
        $scope??=$this->scope;return $scope['id']==='document'?$this->state->id($n):$scope['id'].'/'.$this->state->id($n);
    }
    private function refId(Node $n,array $path):string {return $this->scope['id'].':'.$this->state->id($n).':'.json_encode($path);}
    public function compile():array {
        if (isset($this->compiledScopes[$this->scope['id']])) return array_values($this->references);
        $this->compiledScopes[$this->scope['id']]=true;
        foreach($this->scope['index']->entries as $entry) {
            $n=$entry['node'];$parent=$entry['parent']?$this->scope['index']->entries[$entry['parent']]['node']:null;
            if($parent?->kind===Node::LOGIC)continue;
            if($n->kind===Node::ELEMENT||($n->kind===Node::RUNTIME&&in_array($n->name,['if','each','import','include'],true)))
                foreach($n->props as $k=>$v)if($k!=='else')$this->compileValue($v,$n,['props',$k],['props',$k],true);
            if($n->kind===Node::RUNTIME&&in_array($n->name,['import','include'],true)&&is_array($n->props['props']??null))
                foreach($n->props['props'] as $k=>$v)$this->compileValue($v,$n,['props','props',$k],['props','props',$k],true);
            if($n->kind===Node::LOGIC||($n->kind===Node::RUNTIME&&in_array($n->name,['ref','expr'],true)))$this->compileValue($n,$n,[],[],true);
            if($n->kind===Node::RUNTIME&&in_array($n->name,['props','attributes'],true))foreach(($n->value??$n->props) as $k=>$v){$p=[$n->value!==null?'value':'props',$k];$this->compileValue($v,$n,$p,$p,true);}
        }
        return array_values($this->references);
    }
    private static function literal(mixed $v):bool {
        return $v instanceof Node&&$v->kind===Node::VALUE || is_array($v)&&count($v)===1&&in_array(ValueTypes::normalize((string)array_key_first($v)),['array','object'],true);
    }
    private static function payload(Node $n):mixed {return $n->value??(count($n->children)===1&&$n->children[0]->kind===Node::VALUE?$n->children[0]->value:null);}
    private function eligible(Node $owner,array $path):bool {return isset($this->options['isExpressionLocation'])&&($this->options['isExpressionLocation'])($owner,$path)===true;}
    private function compileValue(mixed $v,Node $owner,array $path,array $plan,bool $eligible):void {
        if(self::literal($v))return;
        $reference=$v instanceof Node&&$v->kind===Node::RUNTIME&&$v->name==='ref';
        $envelope=is_array($v)&&count($v)===1&&array_key_exists(':ref',$v);
        if($eligible&&($reference||$envelope)) {
            $id=$this->refId($owner,$path);if(isset($this->references[$id]))return;
            $authored=$reference?self::payload($v):$v[':ref'];
            try {
                if(!is_string($authored))throw new ReferenceException('invalid-reference',':ref requires a structural address.');
                $ref=new CompiledReference($id,$this->scope['id'],StructuralAddress::parse($authored),['owner'=>$this->runtimeId($owner),'location'=>$this->state->location($this->runtimeId($owner),$plan)],['authored'=>$authored,'operand'=>$path,'meta'=>$owner->meta]);
                $this->references[$id]=$ref;$this->plans[$id]=['owner'=>$owner,'path'=>$path];
                if(($this->state->pins[$id]['authored']??null)!==$authored) {
                    unset($this->state->pins[$id],$this->state->generatedPins[$id]);
                    try{$found=$this->scope['index']->locate($ref->source);$this->state->pins[$id]=['authored'=>$authored,'node'=>$found['entry']['id'],'path'=>$found['path'],'result'=>$found['result']??null];}catch(ReferenceException){}
                }
            }catch(ReferenceException $e){$this->diagnostics[]=['code'=>$e->diagnosticCode,'message'=>$e->getMessage(),'reference'=>$id];}
            return;
        }
        if($eligible&&$v instanceof Node&&$v->kind===Node::LOGIC){foreach($v->args as $i=>$arg)$this->compileValue($arg,$owner,[...$path,'args',$i],$plan,true);return;}
        if($eligible&&$v instanceof Node&&$v->kind===Node::RUNTIME&&$v->name==='expr'){$this->compileValue($v->value,$owner,[...$path,'value'],$plan,true);return;}
        if($eligible&&is_array($v)&&count($v)===1&&in_array('value',$path,true)) {
            $op=(string)array_key_first($v);$operand=$v[$op];
            if(LogicOperators::normalize($op,true)!==null||$op==='in') {
                $list=is_array($operand)&&array_is_list($operand);
                foreach($list?$operand:[$operand] as $i=>$arg)$this->compileValue($arg,$owner,[...$path,$op,...($list?[$i]:[])],$plan,true);return;
            }
        }
        if(is_array($v))foreach($v as $k=>$child){$next=[...$path,$k];$allowed=$this->eligible($owner,$next);$this->compileValue($child,$owner,$next,$allowed?$next:$plan,$allowed);}
    }
    public function run():array {
        $this->compile();
        try{$tree=$this->resolve($this->scope['index']->root,$this->scope['context']);return $this->snapshot('ready',$tree);}
        catch(\Throwable $e){$this->diagnostics[]=['code'=>$e instanceof ReferenceException?$e->diagnosticCode:'evaluation-error','message'=>$e->getMessage()];return $this->snapshot('error');}
    }
    private function snapshot(string $status,?Node $tree=null):array {return ['revision'=>$this->revision,'status'=>$status,'tree'=>$tree,'references'=>array_values($this->references),'edges'=>array_values($this->edges),'results'=>array_values($this->results),'diagnostics'=>$this->diagnostics];}
    private function cell(string $key,callable $run):mixed {
        if(array_key_exists($key,$this->values))return $this->values[$key];
        if(isset($this->busy[$key]))throw new ReferenceException('dependency-cycle','Dependency cycle: '.implode(' -> ',[...array_keys($this->busy),$key]));
        $this->busy[$key]=true;try{return $this->values[$key]=$run();}finally{unset($this->busy[$key]);}
    }
    protected function resolveToList(Node $node,array $context,?string $parentKind,array $importStack):array {
        return $this->cell($this->runtimeId($node).':result',function()use($node,$context,$parentKind,$importStack){
            $this->activeOwners[]=$node;
            try{
                if($node->kind===Node::LOGIC||$node->kind===Node::RUNTIME&&in_array($node->name,['ref','expr'],true)) {
                    $value=$this->value($node,$node,[],$context,$importStack,true);
                    return [$value instanceof StructuralView?$value->materialize():Node::value($this->inferType($value),$value,$node->meta)];
                }
                if($node->kind===Node::RUNTIME&&($this->options['resultAdapters']??null)?->get($node)) {
                    $output=$this->adapterResult($node,$context,$importStack);
                    return [$output['kind']==='structure'?$output['tree']:Node::value($this->inferType($output['value']),$output['value'])];
                }
                $result=parent::resolveToList($node,$context,$parentKind,$importStack);
                if($node->kind===Node::RUNTIME&&in_array($node->name,['each','import','include'],true))$this->results[$this->runtimeId($node)]=['producer'=>$this->runtimeId($node),'address'=>(string)$this->scope['index']->entries[$this->state->id($node)]['address'].'/result','output'=>['kind'=>'structure','tree'=>ReferenceTree::collapse($result)]];
                return $result;
            }finally{array_pop($this->activeOwners);}
        });
    }
    protected function resolveProps(array $props,array $context,array $stack):array {
        $owner=end($this->activeOwners);$output=[];
        $prefix=$owner->kind===Node::RUNTIME&&in_array($owner->name,['import','include'],true)?['props','props']:['props'];
        foreach($props as $key=>$v)$output[$key]=$key==='else'?$v:$this->value($v,$owner,[...$prefix,$key],$context,$stack,true);
        return $output;
    }
    protected function resolvePropValue(mixed $value,array $context,array $stack):mixed {
        $owner=end($this->activeOwners);$key=array_search($value,$owner->props,true);
        return $this->value($value,$owner,$key!==false?['props',$key]:['value'],$context,$stack,true);
    }
    protected function resolveModifierProps(Node $node,array $context,array $stack):array {
        $result=[];foreach(($node->value??$node->props) as $key=>$v)$result[$key]=$this->value($v,$node,[$node->value!==null?'value':'props',$key],$context,$stack,true);return $result;
    }
    private function value(mixed $v,Node $owner,array $path,array $context,array $stack,bool $eligible):mixed {
        return $this->cell($this->runtimeId($owner).':'.json_encode($path),function()use($v,$owner,$path,$context,$stack,$eligible){
            $ref=$this->references[$this->refId($owner,$path)]??null;
            if($ref)return $this->read($ref,$context,$stack);
            if($v instanceof Node&&$v->kind===Node::VALUE)return $eligible?$v->value:SourceSerializer::toSourceNode($v);
            if(self::literal($v))return $eligible?reset($v):$v;
            if($eligible&&$v instanceof Node&&$v->kind===Node::LOGIC) {
                $args=[];foreach($v->args as $i=>$arg){$value=$this->value($arg,$owner,[...$path,'args',$i],$context,$stack,true);if($value instanceof StructuralView)throw new ReferenceException('selection-kind','Logic needs material values.');
                    if($v->op==='and'&&!$this->logic()->truthy($value))return false;if($v->op==='or'&&$this->logic()->truthy($value))return true;$args[]=Node::value($this->inferType($value),$value);}
                return $this->logic()->evaluate(Node::logic((string)$v->op,$args),$context);
            }
            if($v instanceof Node) {
                if(!$eligible)return SourceSerializer::toSourceNode($v);
                if($v->kind===Node::RUNTIME&&$v->name==='expr')return $this->expression($v->value,$owner,[...$path,'value'],$context,$stack);
                if($v->kind===Node::RUNTIME&&$v->name==='ref')throw new ReferenceException('invalid-reference','Invalid reference expression.');
                return parent::resolvePropValue($v,$context,$stack);
            }
            if(is_array($v)) {
                if($eligible&&count($v)===1&&array_key_exists(':ref',$v))throw new ReferenceException('invalid-reference','Invalid reference expression.');
                $output=[];foreach($v as $key=>$child){$p=[...$path,$key];$output[$key]=$this->value($child,$owner,$p,$context,$stack,$this->eligible($owner,$p));}return $output;
            }
            return $v;
        });
    }
    private function expression(mixed $v,Node $owner,array $path,array $context,array $stack):mixed {
        if(is_array($v)&&count($v)===1) {
            $key=(string)array_key_first($v);$op=LogicOperators::normalize($key,true);
            if($op!==null||$key==='in') {
                $operand=$v[$key];$list=is_array($operand)&&array_is_list($operand);$args=[];
                foreach($list?$operand:[$operand] as $i=>$arg){$value=$this->expression($arg,$owner,[...$path,$key,...($list?[$i]:[])],$context,$stack);if($op==='and'&&!$this->logic()->truthy($value))return false;if($op==='or'&&$this->logic()->truthy($value))return true;$args[]=$value;}
                return $this->logic()->evaluate([$key=>$args],$context);
            }
        }
        return $this->value($v,$owner,$path,$context,$stack,true);
    }
    private function inScope(array $scope,callable $run):mixed { $old=$this->scope;$this->scope=$scope;try{$this->compile();return $run();}finally{$this->scope=$old;} }
    private function below(array $entry,Node $node,ReferenceIndex $index):bool {
        for($e=$entry;$e;$e=$e['parent']?($index->entries[$e['parent']]??null):null)if($e['node']===$node)return true;return false;
    }
    private function active(array $entry):void {
        for($e=$entry;$e;$e=$e['parent']?($this->scope['index']->entries[$e['parent']]??null):null) {
            $parent=$e['parent']?($this->scope['index']->entries[$e['parent']]['node']??null):null;
            if($parent?->kind===Node::RUNTIME&&$parent->name==='each'&&($this->scope['iteration']['producer']??null)!==$parent)throw new ReferenceException('ambiguous-instance','Select a loop result outside its iteration.');
            if($e['branch']){$test=$this->value($parent->props['test'],$parent,['props','test'],$this->scope['context'],$this->scope['stack'],true);if(($this->logic()->truthy($test)?'then':'else')!==$e['branch'])throw new ReferenceException('inactive-source','Source is in an inactive branch.');}
        }
    }
    private function read(CompiledReference $ref,array $context,array $stack):mixed {
        $pin=$this->state->pins[$ref->id]??null;
        if(($pin['stale']??false))throw new ReferenceException('stale-reference','Array item changed without identity evidence.');
        if($pin&&$pin['authored']===$ref->provenance['authored']) {
            $entry=$this->scope['index']->entries[$pin['node']]??null;if(!$entry)throw new ReferenceException('stale-reference','Referenced source was deleted.');
            $found=['entry'=>$entry,'path'=>$pin['path'],'result'=>$pin['result']??null];
        } else $found=$this->scope['index']->locate($ref->source);
        $scope=$this->scope;
        while($scope['parent']&&$scope['iteration']&&!$this->below($found['entry'],$scope['iteration']['producer'],$scope['index']))$scope=$scope['parent'];
        return $this->inScope($scope,function()use($ref,$found){
            $entry=$found['entry'];$node=$entry['node'];$path=$found['path'];$result=$found['result']??null;
            $this->active($entry);
            if($path&&!in_array($path[0],['props','value'],true))throw new ReferenceException('not-selectable','AST identity and metadata are not selectable.');
            $selectionPath=$result!==null?['result',...array_map(fn($s)=>$s['name']??$s['key']??$s['index']??$s['kind'],$result)]:$path;
            if(isset($this->options['canSelect'])&&($this->options['canSelect'])($node,$selectionPath)===false)throw new ReferenceException('not-selectable','Selection is not exposed.');
            $source=['node'=>$this->runtimeId($node),'location'=>$this->state->location($this->runtimeId($node),$selectionPath)];
            $edge=['reference'=>$ref->id,'source'=>$source,'target'=>$ref->target,'selection'=>!$path&&$result===null?'structure':'value'];$this->edges[$ref->id]=$edge;
            if(!$path&&$result===null){$scope=$this->scope;return new StructuralView($source,$this->revision,fn()=>$this->inScope($scope,fn()=>ReferenceTree::collapse($this->resolveToList($node,$scope['context'],null,$scope['stack']))));}
            if($result!==null)return $this->resultSelection($node,$result,$ref,$edge);
            if(($path[0]??null)==='props'&&$node->kind===Node::ELEMENT) {
                $name=$path[1];$value=$this->value($node->props[$name]??null,$node,['props',$name],$this->scope['context'],$this->scope['stack'],true);
                foreach($node->children as $m)if($m->kind===Node::RUNTIME&&in_array($m->name,['props','attributes'],true)){$payload=$m->value??$m->props;if(array_key_exists($name,$payload))$value=$this->value($payload[$name],$m,[$m->value!==null?'value':'props',$name],$this->scope['context'],$this->scope['stack'],true);}
                return $this->selectedValue(ReferenceTree::get($value,array_slice($path,2)),$edge);
            }
            $prefix=$path[0]==='props'?array_slice($path,0,2):array_slice($path,0,1);
            $base=ReferenceTree::get($node,$prefix);if($node->kind!==Node::VALUE)$base=$this->value($base,$node,$prefix,$this->scope['context'],$this->scope['stack'],true);
            return $this->selectedValue(ReferenceTree::get($base,array_slice($path,count($prefix))),$edge);
        });
    }
    private function selectedValue(mixed $value,array $edge):mixed {
        if (!$value instanceof StructuralView) return $value;
        $edge['selection']='structure';$this->edges[$edge['reference']]=$edge;
        return new StructuralView($edge['source'],$this->revision,fn()=>$value->materialize());
    }
    protected function resolveIteration(Node $node,array $context,array $stack,int|string $key,mixed $items):array {
        $parent=$this->scope;$producer=$this->runtimeId($node);$signature=json_encode($items);
        $previous=$this->state->versions[$producer]??null;$version=$previous&&$previous['signature']===$signature?$previous['version']:($previous['version']??0)+1;
        $this->state->versions[$producer]=['signature'=>$signature,'version'=>$version];
        $token=is_string($key)?['key',$key]:['index',$key,$version];
        $scope=[...$parent,'id'=>json_encode([$parent['id'],'each',$this->state->id($node),$token]),'context'=>$context,'stack'=>$stack,'parent'=>$parent,'iteration'=>['producer'=>$node,'key'=>$key]];
        $result=$this->inScope($scope,fn()=>parent::resolveIteration($node,$context,$stack,$key,$items));
        $this->groups[$producer][(string)$key]=$result;return $result;
    }
    protected function resolveImportedTree(Node $tree,array $context,array $stack,Node $owner):array {
        $id=json_encode([$this->scope['id'],'import',$this->state->id($owner)]);$signature=json_encode($tree->toArray());$old=$this->state->imports[$id]??null;
        if($old&&$old['signature']===$signature)$tree=$old['tree'];else $this->state->imports[$id]=['signature'=>$signature,'tree'=>$tree];
        $scope=['id'=>$id,'index'=>new ReferenceIndex($tree,$this->state),'context'=>$context,'stack'=>$stack,'parent'=>null,'iteration'=>null];
        return $this->inScope($scope,fn()=>parent::resolveImportedTree($tree,$context,$stack,$owner));
    }
    private function adapterResult(Node $node,array $context,array $stack):array {
        return $this->cell($this->runtimeId($node).':adapter',function()use($node,$context,$stack){
            $adapter=$this->options['resultAdapters']->get($node);$props=[];
            foreach($node->props as $name=>$v)$props[$name]=$this->value($v,$node,['props',$name],$context,$stack,true);
            $read=function(string $address)use($node,$context,$stack){$path=['result','read',$address];$this->compileValue([':ref'=>$address],$node,$path,['result'],true);return $this->read($this->references[$this->refId($node,$path)],$context,$stack);};
            $output=$adapter(['node'=>$node,'props'=>$props,'context'=>$context,'read'=>$read,'revision'=>$this->revision]);
            if(!is_array($output)||!in_array($output['kind']??null,['value','structure'],true))throw new ReferenceException('invalid-result','Adapter must return a material or structural result.');
            if($output['kind']==='structure'&&!($output['tree']??null) instanceof Node)throw new ReferenceException('invalid-result','Structural result requires a canonical tree.');
            $count=$output['kind']==='value'&&is_array($output['value']??null)&&array_is_list($output['value'])?count($output['value']):($output['kind']==='structure'&&$output['tree']->kind===Node::FRAGMENT?count($output['tree']->children):-1);
            if(isset($output['keys'])&&(count($output['keys'])!==$count||count(array_unique($output['keys']))!==$count||array_filter($output['keys'],fn($k)=>!is_string($k))))throw new ReferenceException('invalid-result-keys','Keys must uniquely identify each result item.');
            $this->results[$this->runtimeId($node)]=['producer'=>$this->runtimeId($node),'address'=>(string)$this->scope['index']->entries[$this->state->id($node)]['address'].'/result','output'=>$output];return $output;
        });
    }
    private function resultSelection(Node $node,array $segments,CompiledReference $ref,array $edge):mixed {
        $adapter=($this->options['resultAdapters']??null)?->get($node);
        $output=$adapter?$this->adapterResult($node,$this->scope['context'],$this->scope['stack']):['kind'=>'structure','tree'=>ReferenceTree::collapse($this->resolveToList($node,$this->scope['context'],null,$this->scope['stack']))];
        $value=$output['kind']==='structure'?$output['tree']:($output['value']??null);$structural=$output['kind']==='structure';$identity=$this->runtimeId($node);
        $first=$segments[0]??null; $unkeyed=[];
        if(($first['kind']??null)==='entry'&&!$adapter){$group=$this->groups[$identity][$first['key']]??null;if(!$group)throw new ReferenceException('missing-reference','Generated key is unavailable.');$value=ReferenceTree::collapse($group);$identity.='/entry:'.json_encode($first['key']);array_shift($segments);}
        elseif($first&&in_array($first['kind'],['entry','item','children'],true)) {
            $items=$structural?ReferenceTree::children($value):(is_array($value)?$value:[]);$index=$first['kind']==='entry'?array_search($first['key'],$output['keys']??[],true):$first['index'];
            if($index===false||!array_key_exists($index,$items))throw new ReferenceException('missing-reference','Generated item is unavailable.');
            if(isset($output['keys'])){$key=$output['keys'][$index];$this->state->pins[$ref->id]['result']=[['kind'=>'entry','key'=>$key],...array_slice($segments,1)];$identity.='/entry:'.json_encode($key);}
            else{$unkeyed[]=json_encode([$identity,$index,$output]);$identity.='/item:'.$index;}
            $value=$items[$index];array_shift($segments);
        }
        if (array_filter($segments,fn($s)=>in_array($s['kind'],['children','item'],true))) $unkeyed[]=json_encode([$identity,$segments,$value]);
        if ($unkeyed) {
            $fingerprint=json_encode($unkeyed);$prior=$this->state->generatedPins[$ref->id]??null;
            if($prior!==null&&$prior!==$fingerprint)throw new ReferenceException('stale-reference','Unkeyed result changed; rebind the selection.');
            $this->state->generatedPins[$ref->id]=$fingerprint;
        }
        foreach($segments as $s) {
            if($s['kind']==='child'||$s['kind']==='children') {
                if(!$value instanceof Node)throw new ReferenceException('selection-kind','Child requires structure.');$kids=ReferenceTree::children($value);
                if($s['kind']==='child'){$matches=array_values(array_filter($kids,fn($n)=>ReferenceTree::name($n)===$s['name']));if(count($matches)!==1)throw new ReferenceException('ambiguous-reference','Result child is missing or ambiguous.');$value=$matches[0];}else $value=$kids[$s['index']]??throw new ReferenceException('missing-reference','Result child missing.');$structural=true;
            }elseif($s['kind']==='props'){$value=ReferenceTree::get($value,['props',$s['name']]);$structural=false;}
            elseif($s['kind']==='value'){$value=ReferenceTree::get($value,['value']);$structural=false;}
            elseif(in_array($s['kind'],['member','item'],true)&&!$structural)$value=ReferenceTree::get($value,[$s['name']??$s['index']]);
            else throw new ReferenceException('selection-kind','Unsupported result selection.');
        }
        $edge['source']=['node'=>$identity,'location'=>$this->state->location($identity,['result',...$segments])];$edge['selection']=$structural?'structure':'value';$this->edges[$ref->id]=$edge;
        return $structural?new StructuralView($edge['source'],$this->revision,fn()=>$value):$value;
    }
}
