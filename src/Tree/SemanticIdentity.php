<?php
declare(strict_types=1);
namespace Armes\Tree;
use Armes\Runtime\References\{ReferenceTree,ReferenceSyntax};
final class SemanticIdentity {
    public static function makeId():string {return 'node-'.bin2hex(random_bytes(16));}
    public static function copy(Node $root):Node {
        $ids=[];$collect=function(Node $n)use(&$collect,&$ids){if($n->semanticId!==null)$ids[$n->semanticId]=self::makeId();foreach(ReferenceTree::children($n) as $c)$collect($c);if($n->kind===Node::RUNTIME&&is_array($n->props['else']??null))foreach($n->props['else'] as $c)$collect($c);};$collect($root);
        $selector=function(string $path)use($ids):string{$parsed=ReferenceSyntax::parse($path);$s=$parsed['selections'][0]['selector']??null;if(isset($parsed['spec']['src'])||!$s||$s['anchor']!=='id'||!isset($ids[$s['id']]))return $path;$suffix=ReferenceSyntax::compact($s['address']);return '#'.$ids[$s['id']].substr($suffix,1);};
        $source=function($value)use($selector){if(is_string($value))return $selector($value);$value=(array)$value;if(isset($value['src'])||!isset($value['path']))return $value;$value['path']=is_string($value['path'])?$selector($value['path']):array_map($selector,(array)$value['path']);return $value;};
        $expression=function($v,bool $deep=false)use(&$expression,$source){
            if($v instanceof Node){if($v->kind===Node::VALUE)return $v;if($v->kind===Node::RUNTIME&&$v->name==='ref')return Node::runtime('ref',[],[],$source(ReferenceSyntax::source($v)),$v->meta)->withSemanticId($v->semanticId);if($v->kind===Node::RUNTIME&&$v->name==='expr')return $v->withValue($expression($v->value,true));if($v->kind===Node::LOGIC)return ReferenceTree::map($v,fn($child)=>$expression($child,true));return $v;}
            if($v instanceof \stdClass)return $deep?(object)array_map(fn($child)=>$expression($child,true),get_object_vars($v)):$v;
            if(!is_array($v))return $v;if(count($v)===1){$key=array_key_first($v);if($key===':ref')return [':ref'=>$source($v[$key])];if($key===':expr')return [':expr'=>$expression($v[$key],true)];if(str_starts_with((string)$key,':type:')&&!$deep)return $v;}
            return $deep?array_map(fn($v)=>$expression($v,true),$v):$v;
        };
        $copy=function(Node $n)use(&$copy,$ids,$expression,$source):Node{
            $next=ReferenceTree::map($n,$copy)->withSemanticId($n->semanticId!==null?$ids[$n->semanticId]:null);
            if($n->kind===Node::ELEMENT||$n->kind===Node::RUNTIME)$next=$next->withProps(array_map($expression,$next->props));
            if($n->kind===Node::RUNTIME&&$n->name==='ref')$next=Node::runtime('ref',[],[],$source(ReferenceSyntax::source($n)),$n->meta)->withSemanticId($next->semanticId);
            elseif($n->kind===Node::RUNTIME&&$n->name==='expr')$next=$next->withValue($expression($next->value,true));
            elseif($n->kind===Node::RUNTIME&&in_array($n->name,['props','attributes'],true)&&is_array($next->value))$next=$next->withValue(array_map($expression,$next->value));
            return $next;
        };return $copy($root);
    }
}
