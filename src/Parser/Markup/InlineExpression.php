<?php
declare(strict_types=1);
namespace Armes\Parser\Markup;
use Armes\Exception\ParseException;
use Armes\Parser\Native\NativeTagParser;
use Armes\Runtime\{LogicOperators,ValueTypes};
use Armes\Tree\Node;
/** Recursive source sugar that lowers to the existing native directive parser. */
final class InlineExpression {
    private int $position=0,$tokens=0;
    private function __construct(private string $text,private ?string $source,private int $maxDepth,private int $maxTokens){}
    private function fail(string $message):never {$prefix=substr($this->text,0,$this->position);$line=substr_count($prefix,"\n")+1;$last=strrpos($prefix,"\n");$column=$this->position-($last===false?-1:$last);throw new ParseException(($this->source??'inline attribute').':'.$line.':'.$column.' (offset '.$this->position.'): '.$message);}
    private function whitespace():void {while($this->position<strlen($this->text)&&ctype_space($this->text[$this->position]))$this->position++;}
    private function take(string $c):void {$this->whitespace();if(($this->text[$this->position++]??null)!==$c)$this->fail('Expected '.$c);}
    private function string():string {$start=$this->position++;$escape=false;while($this->position<strlen($this->text)){$c=$this->text[$this->position++];if(!$escape&&$c==='"'){try{return json_decode(substr($this->text,$start,$this->position-$start),true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->fail('Invalid quoted string');}}$escape=!$escape&&$c==='\\';}$this->fail('Unclosed quoted string');}
    private function value(int $depth,bool $reference=false):mixed {
        if($depth>$this->maxDepth||++$this->tokens>$this->maxTokens)$this->fail('Inline depth/token limit exceeded');$this->whitespace();$c=$this->text[$this->position]??null;
        if(substr($this->text,$this->position,2)==='{:')return $this->directive($depth+1);
        if($c==='"')return $this->string();
        if($c==='['){$this->position++;$values=[];$this->whitespace();if(($this->text[$this->position]??null)===']'){$this->position++;return $values;}for(;;){$values[]=$this->value($depth+1);$this->whitespace();if(($this->text[$this->position]??null)===']'){$this->position++;return $values;}$this->take(',');}}
        if($c==='{'){$this->position++;$values=[];$this->whitespace();if(($this->text[$this->position]??null)==='}'){$this->position++;return (object)$values;}for(;;){$this->whitespace();if(($this->text[$this->position]??null)!=='"')$this->fail('Object keys require double quotes');$key=$this->string();if(in_array($key,['__proto__','prototype','constructor'],true))$this->fail('Unsafe object key');if(array_key_exists($key,$values))$this->fail('Duplicate object key '.$key);$this->take(':');$values[$key]=$this->value($depth+1);$this->whitespace();if(($this->text[$this->position]??null)==='}'){$this->position++;return (object)$values;}$this->take(',');}}
        if($reference){$start=$this->position;$brackets=0;$quote=false;$escape=false;while($this->position<strlen($this->text)){$c=$this->text[$this->position];if($quote){if(!$escape&&$c==='"')$quote=false;$escape=!$escape&&$c==='\\';}elseif($c==='"')$quote=true;elseif($c==='[')$brackets++;elseif($c===']'){if(!$brackets)break;$brackets--;}elseif(!$brackets&&(ctype_space($c)||$c==='}'||$c===','))break;$this->position++;}if($this->position===$start||$quote||$brackets)$this->fail('Malformed unquoted reference');return substr($this->text,$start,$this->position-$start);}
        if(!preg_match('/^(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s|[,\]}]|$)/',substr($this->text,$this->position),$m))$this->fail('Expected directive, container or primitive');$this->position+=strlen($m[0]);$value=json_decode($m[0],true,512,JSON_THROW_ON_ERROR);if(is_float($value)&&!is_finite($value))$this->fail('Nonfinite number');return $value;
    }
    private function directive(int $depth):mixed {
        $this->position++;$start=$this->position;while($this->position<strlen($this->text)&&!ctype_space($this->text[$this->position])&&$this->text[$this->position]!=='}')$this->position++;$name=substr($this->text,$start,$this->position-$start);$args=[];
        for(;;){$this->whitespace();if($this->position>=strlen($this->text))$this->fail('Unclosed '.$name.' expression');if($this->text[$this->position]==='}'){$this->position++;break;}$args[]=$this->value($depth,$name===':ref');}
        $op=LogicOperators::normalizeDirect($name);if($op!==null){if(in_array($op,['not','var'],true)&&count($args)!==1||in_array($op,['eq','ne','gt','gte','lt','lte','div','mod'],true)&&count($args)!==2||!$args)$this->fail('Invalid operand count for '.$name);return [':logic:'.$op=>$op==='var'?$args[0]:$args];}
        $type=ValueTypes::normalize($name);if($type!==null){if(count($args)!==1)$this->fail($name.' requires one argument');if($type==='array'&&(!is_array($args[0])||!array_is_list($args[0]))||$type==='object'&&!$args[0] instanceof \stdClass)$this->fail('Invalid '.$type.' argument');$source=[ValueTypes::key($type)=>$args[0]];return in_array($type,['array','object'],true)&&self::hasDirective($args[0])?[':expr'=>$source]:$source;}
        if(!in_array($name,[':ref',':expr',':props',':attributes',':import',':include',':if',':each',':php',':js',':ts',':code'],true))$this->fail('Unknown directive '.$name);if(count($args)!==1)$this->fail($name.' requires one payload');return [$name=>$args[0]];
    }
    private static function hasDirective(mixed $value):bool {if(!is_array($value)&&!$value instanceof \stdClass)return false;$record=(array)$value;if(count($record)===1&&str_starts_with((string)array_key_first($record),':'))return true;foreach($record as $v)if(self::hasDirective($v))return true;return false;}
    public static function source(string $text,?string $source=null,int $maxDepth=64,int $maxTokens=100000):mixed {$r=new self($text,$source,$maxDepth,$maxTokens);$r->whitespace();if(substr($text,$r->position,2)!=='{:')$r->fail('Expected whole-attribute directive');$value=$r->value(0);$r->whitespace();if($r->position!==strlen($text))$r->fail('Trailing expression text');return $value;}
    public static function parse(string $text,?string $source=null,int $maxDepth=64,int $maxTokens=100000):Node {return (new NativeTagParser())->parse(self::source($text,$source,$maxDepth,$maxTokens),$source);}
    public static function emit(Node $node):string {
        if($node->kind===Node::VALUE)return '{'.ValueTypes::key((string)$node->type).' '.json_encode($node->value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).'}';
        if($node->kind===Node::LOGIC)return '{:logic:'.$node->op.' '.implode(' ',array_map(self::emit(...),$node->args)).'}';
        if($node->kind===Node::RUNTIME)return '{:'.$node->name.' '.self::emitPayload($node->value??($node->props?:($node->children[0]->value??null))).'}';throw new ParseException('Only value, logic or runtime nodes are inline expressions.');
    }
    private static function emitPayload(mixed $value):string {
        if($value instanceof Node)return self::emit($value);
        if(is_array($value)||$value instanceof \stdClass){$record=(array)$value;if(count($record)===1&&str_starts_with((string)array_key_first($record),':')){$key=array_key_first($record);$v=$record[$key];$op=LogicOperators::normalizeDirect($key);return '{'.$key.' '.($op&&is_array($v)&&array_is_list($v)?implode(' ',array_map(self::emitPayload(...),$v)):self::emitPayload($v)).'}';}
            if(is_array($value)&&array_is_list($value))return '['.implode(',',array_map(self::emitPayload(...),$value)).']';$parts=[];foreach($record as $key=>$v)$parts[]=json_encode((string)$key).':'.self::emitPayload($v);return '{'.implode(',',$parts).'}';}
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
