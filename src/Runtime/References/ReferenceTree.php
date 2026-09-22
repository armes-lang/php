<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;
/** @internal Operations on the existing immutable canonical nodes. */
final class ReferenceTree
{
    public static function children(Node $n): array { return $n->kind===Node::LOGIC?$n->args:$n->children; }
    public static function name(Node $n): ?string { return $n->kind===Node::ELEMENT?$n->name:($n->kind===Node::RUNTIME?':'.$n->name:null); }
    public static function branch(Node $n,string $branch): array {
        if ($n->kind!==Node::RUNTIME || $n->name!=='if') throw new ReferenceException('invalid-location','Branch requires :if.');
        return $branch==='then'?$n->children:($n->props['else']??[]);
    }
    public static function get(mixed $value,array $path): mixed {
        foreach($path as $key) {
            if ($value instanceof Node) $value=get_object_vars($value);
            elseif ($value instanceof \stdClass) $value=get_object_vars($value);
            if (!is_array($value) || !array_key_exists($key,$value)) throw new ReferenceException('missing-reference','Selected location is unavailable.');
            $value=$value[$key];
        }
        return $value;
    }
    public static function set(mixed $value,array $path,mixed $replacement): mixed {
        if (!$path) return $replacement;
        $key=array_shift($path);
        if ($value instanceof Node) {
            $part=self::set(self::get($value,[$key]),$path,$replacement);
            return match($key) {
                'props'=>$value->withProps($part),'value'=>$value->withValue($part),'children'=>$value->withChildren($part),
                'args'=>Node::logic((string)$value->op,$part,$value->meta),
                default=>throw new ReferenceException('not-bindable','Node identity and metadata are not bindable.')
            };
        }
        if (!is_array($value)) throw new ReferenceException('invalid-location','Cannot update a missing container.');
        $value[$key]=self::set($value[$key]??null,$path,$replacement);return $value;
    }
    public static function map(Node $node,callable $child): Node {
        $mapValue=function(mixed $v)use(&$mapValue,$child):mixed {
            if ($v instanceof Node) return $child($v);
            if (is_array($v)) return array_map($mapValue,$v);
            if ($v instanceof \stdClass) return (object)array_map($mapValue,get_object_vars($v));
            return $v;
        };
        return match($node->kind) {
            Node::ELEMENT=>Node::element((string)$node->name,$mapValue($node->props),array_map($child,$node->children),$node->meta),
            Node::RUNTIME=>Node::runtime((string)$node->name,$mapValue($node->props),array_map($child,$node->children),$mapValue($node->value),$node->meta),
            Node::LOGIC=>Node::logic((string)$node->op,array_map($child,$node->args),$node->meta),
            Node::FRAGMENT=>Node::fragment(array_map($child,$node->children),$node->meta),
            default=>Node::value((string)$node->type,$mapValue($node->value),$node->meta)
        };
    }
    public static function collapse(array $nodes): Node {return count($nodes)===1?$nodes[0]:Node::fragment($nodes);}
}
