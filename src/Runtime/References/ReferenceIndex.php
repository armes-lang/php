<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
/** @internal Addressing view; indexes are positions, never runtime identity. */
final class ReferenceIndex
{
    public array $entries=[];
    public \WeakMap $nodes;
    public array $roots;
    public function __construct(public readonly Node $root,private readonly ReferenceState $state) {
        $this->nodes=new \WeakMap(); $this->roots=$root->kind===Node::FRAGMENT?$root->children:[$root];
        if ($root->kind===Node::FRAGMENT) $this->entry($root,[]);
        $this->list($this->roots,[],null,null);
    }
    private function entry(Node $n,array $address,?string $parent=null,?string $branch=null): string {
        $id=$this->state->id($n);$this->nodes[$n]=$id;
        $this->entries[$id]=['node'=>$n,'id'=>$id,'address'=>new StructuralAddress($address),'parent'=>$parent,'branch'=>$branch];return $id;
    }
    private function list(array $nodes,array $prefix,?string $parent,?string $branch): void {
        foreach($nodes as $i=>$node) {
            $name=ReferenceTree::name($node);$matches=array_filter($nodes,fn($n)=>ReferenceTree::name($n)===$name);
            $address=[...$prefix,$name!==null&&count($matches)===1?['kind'=>'child','name'=>$name]:['kind'=>'children','index'=>$i]];
            $this->visit($node,$address,$parent,$branch);
        }
    }
    private function visit(Node $node,array $address,?string $parent,?string $branch):void {
        $id=$this->entry($node,$address,$parent,$branch);
        if($node->kind===Node::LOGIC) foreach($node->args as $i=>$arg) $this->visit($arg,[...$address,['kind'=>'args','index'=>$i]],$id,null);
        elseif($node->kind===Node::RUNTIME&&$node->name==='if') foreach(['then','else'] as $b) $this->list(ReferenceTree::branch($node,$b),[...$address,['kind'=>'branch','name'=>$b]],$id,$b);
        else $this->list($node->children,$address,$id,null);
    }
    public function locate(StructuralAddress $address):array {
        $current=$this->roots;$entry=$this->entries[$this->nodes[$this->root]];$path=[];
        foreach($address->segments as $i=>$s) {
            $kind=$s['kind'];
            if($kind==='result') {if($path)throw new ReferenceException('invalid-location','Result requires a producer.');return ['entry'=>$entry,'path'=>[],'result'=>array_slice($address->segments,$i+1)];}
            if(in_array($kind,['child','children'],true)) {
                $list=$current instanceof Node?ReferenceTree::children($current):(is_array($current)?$current:[]);
                if($kind==='child') {
                    $matches=array_values(array_filter($list,fn($n)=>$n instanceof Node&&ReferenceTree::name($n)===$s['name']));
                    if(count($matches)!==1)throw new ReferenceException(count($matches)?'ambiguous-reference':'missing-reference','Expected one child '.$s['name'].'.');
                    $current=$matches[0];
                } else $current=$list[$s['index']]??null;
                if(!$current instanceof Node||!isset($this->nodes[$current]))throw new ReferenceException('missing-reference','Child is unavailable.');
                $entry=$this->entries[$this->nodes[$current]];$path=[];
            } elseif($kind==='branch') {
                if(!$current instanceof Node || $i===count($address->segments)-1)throw new ReferenceException('unsupported-selection','Select a branch descendant.');
                $current=ReferenceTree::branch($current,$s['name']);
            } elseif($kind==='args') {
                $current=$current instanceof Node?($current->args[$s['index']]??null):null;
                if(!$current instanceof Node)throw new ReferenceException('missing-reference','Missing operand.');
                $entry=$this->entries[$this->nodes[$current]];$path=[];
            } else {
                $append=match($kind){'props'=>['props',$s['name']],'value'=>['value'],'member'=>[$s['name']],'item'=>[$s['index']],default=>throw new ReferenceException('invalid-location','Entry requires a result.')};
                $path=[...$path,...$append];
                try{$current=ReferenceTree::get($entry['node'],$path);}catch(ReferenceException $e){if(in_array($kind,['props','value'],true))throw $e;$current=null;}
            }
        }
        return ['entry'=>$entry,'path'=>$path];
    }
    public function address(array $entry,array $path,?array $result=null):StructuralAddress {
        $segments=$entry['address']->segments;
        if(($path[0]??null)==='props') {$segments[]=['kind'=>'props','name'=>$path[1]];$path=array_slice($path,2);}
        elseif(($path[0]??null)==='value'){$segments[]=['kind'=>'value'];array_shift($path);}
        foreach($path as $p)$segments[]=is_int($p)?['kind'=>'item','index'=>$p]:['kind'=>'member','name'=>$p];
        if($result!==null)$segments=[...$segments,['kind'=>'result'],...$result];
        return new StructuralAddress($segments);
    }
}
