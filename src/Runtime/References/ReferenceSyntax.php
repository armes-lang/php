<?php
declare(strict_types=1);
namespace Armes\Runtime\References;
use Armes\Tree\Node;

/** Portable selector tokenizer. Query decoding is intentionally outside this grammar. */
final class ReferenceSyntax
{
    private int $position=0;
    private const LEGACY=['child','children','props','member','item','args','branch','value','result','entry'];
    private function __construct(private string $text) {}
    private function fail(string $message):never {throw new ReferenceException('invalid-address',$message.' at column '.($this->position+1).': '.$this->text);}
    public static function safeKey(string $key):void {if(in_array($key,['__proto__','prototype','constructor'],true))throw new ReferenceException('unsafe-member','Unsupported member '.$key);}
    private static function integer(string $key):bool {return (bool)preg_match('/^(0|[1-9][0-9]*)$/D',$key)&&strlen($key)<=16&&(strlen($key)<16||strcmp($key,'9007199254740991')<=0);}
    private function quoted():string {
        $start=$this->position++;$escaped=false;
        while($this->position<strlen($this->text)){$c=$this->text[$this->position++];if(!$escaped&&$c==='"'){try{return json_decode(substr($this->text,$start,$this->position-$start),true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->fail('Invalid quoted name');}}$escaped=!$escaped&&$c==='\\';}
        $this->fail('Unclosed quoted name');
    }
    private function bare():string {
        $start=$this->position;
        while($this->position<strlen($this->text)&&!str_contains(" \t\r\n[]/{}@#\"'\\",$this->text[$this->position])&&substr($this->text,$this->position,2)!=='::')$this->position++;
        if($start===$this->position)$this->fail('Expected name');return substr($this->text,$start,$this->position-$start);
    }
    private function bracket():?array {
        $this->position++;if(($this->text[$this->position]??null)===']'){$this->position++;return null;}
        $quoted=($this->text[$this->position]??null)==='"';$key=$quoted?$this->quoted():$this->bare();
        if(($this->text[$this->position++]??null)!==']')$this->fail('Expected ]');
        if(!$quoted&&preg_match('/^-\d+$/D',$key))$this->fail('Negative indexes require quoted object keys');self::safeKey($key);
        return ['key'=>$key,...($quoted?['quoted'=>true]:[])];
    }
    private function name():string {if(($this->text[$this->position]??null)!=='[')return $this->bare();$part=$this->bracket();if(!($part['quoted']??false))$this->fail('Literal names require quoted strings');return $part['key'];}
    public static function selector(string $text,?string $syntax=null):array {
        if(strlen($text)>16384)throw new ReferenceException('invalid-address','Selector length limit exceeded.');
        $head=str_starts_with($text,'$/')?explode('/',substr($text,2))[0]:'';
        if($syntax==='legacy'||$syntax!=='compact'&&in_array($head,self::LEGACY,true))return ['anchor'=>'document','syntax'=>'legacy','address'=>StructuralAddress::parse($text)];
        $r=new self($text);$up=0;$id=null;
        if(str_starts_with($text,'$')){$r->position=1;$anchor=($text[1]??null)==='['?'data':'document';}
        elseif(str_starts_with($text,'#')){$r->position=1;$anchor='id';$id=$r->name();}
        elseif($text==='..'||str_starts_with($text,'../')){$r->position=2;$anchor='relative';$up=1;}
        elseif($text==='.'||str_starts_with($text,'./')){$r->position=1;$anchor='relative';}
        else $r->fail('Expected $, ., .., or #stable-id');
        $segments=[];$data=$anchor==='data';
        while($r->position<strlen($text)){
            if(count($segments)>=256)throw new ReferenceException('reference-limit','Selector step limit exceeded.');
            if($data){if($text[$r->position]!=='[')$r->fail('Only brackets allowed after data');$part=$r->bracket();$segments[]=$part?['kind'=>'data',...$part]:['kind'=>'all'];continue;}
            if($text[$r->position++]!=='/')$r->fail('Expected /');$marker=$text[$r->position]??null;
            if($marker==='@'||$marker==='#'){$r->position++;$name=$r->name();self::safeKey($name);$segments[]=$marker==='@'?['kind'=>'props','name'=>$name]:['kind'=>'result',...($name==='result'?[]:['output'=>$name])];$data=true;}
            elseif($marker==='['){$part=$r->bracket();if(!$part)$r->fail('Empty structural child');if($part['quoted']??false){$name=$part['key'];if(($text[$r->position]??null)==='['){$occ=$r->bracket();if(!$occ||($occ['quoted']??false)||!self::integer($occ['key']))$r->fail('Invalid child occurrence');$segments[]=['kind'=>'occurrence','name'=>$name,'index'=>(int)$occ['key']];}else $segments[]=['kind'=>'child','name'=>$name];}else{if(!self::integer($part['key']))$r->fail('Invalid child index');$segments[]=['kind'=>'children','index'=>(int)$part['key']];}}
            else{$name=$r->name();if($name==='.'||$name==='..'){if($name==='..'){if($segments)array_pop($segments);elseif($anchor==='relative')$up++;else $r->fail('Cannot escape root');}}
                elseif(($text[$r->position]??null)==='['){$part=$r->bracket();if(!$part||($part['quoted']??false)||!self::integer($part['key']))$r->fail('Invalid child occurrence');$segments[]=['kind'=>'occurrence','name'=>$name,'index'=>(int)$part['key']];}else $segments[]=['kind'=>'child','name'=>$name];}
        }
        return ['anchor'=>$anchor,'syntax'=>'compact','address'=>new StructuralAddress($segments),'id'=>$id,'up'=>$up];
    }
    public static function split(string $text):array {
        $quote=false;$escape=false;$delimiter=null;
        for($i=0;$i<strlen($text);$i++){$c=$text[$i];if($quote){if(!$escape&&$c==='"')$quote=false;$escape=!$escape&&$c==='\\';continue;}if($c==='"'){$quote=true;continue;}if(substr($text,$i,2)==='::'){if($delimiter!==null)throw new ReferenceException('invalid-resource','Quote literal :: in locators.');$delimiter=$i;$i++;}}
        if($quote)throw new ReferenceException('invalid-resource','Unclosed quoted reference.');if($delimiter===null)return ['path'=>$text];
        $src=substr($text,0,$delimiter);if(str_starts_with($src,'"')){try{$src=json_decode($src,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new ReferenceException('invalid-resource','Invalid quoted locator.');}}
        elseif($src===''||preg_match('/[\s\[\]#?\\\\{}"\']/', $src))throw new ReferenceException('invalid-resource','Quote delimiter-bearing locators.');
        return ['src'=>$src,'path'=>substr($text,$delimiter+2)];
    }
    public static function parse(mixed $input):array {
        $spec=is_string($input)?self::split($input):($input instanceof \stdClass?get_object_vars($input):$input);
        if(!is_array($spec))throw new ReferenceException('invalid-reference','Reference requires a selector or options.');
        foreach($spec as $key=>$value)if(!in_array($key,['src','format','mode','syntax','path'],true))throw new ReferenceException('invalid-reference','Unknown option '.$key);
        foreach(['src','format'] as $key)if(array_key_exists($key,$spec)&&(!is_string($spec[$key])||$spec[$key]===''))throw new ReferenceException('invalid-reference','Invalid '.$key);
        foreach(['mode'=>['data','document'],'syntax'=>['legacy','compact']] as $key=>$values)if(array_key_exists($key,$spec)&&!in_array($spec[$key],$values,true))throw new ReferenceException('invalid-reference','Invalid '.$key);
        $path=array_key_exists('path',$spec)?$spec['path']:'$';$shape=is_string($path)?'single':(is_array($path)&&array_is_list($path)?'list':'named');
        if(!is_string($path)&&!is_array($path)&&!$path instanceof \stdClass)throw new ReferenceException('invalid-reference','Invalid path shape.');
        $entries=is_string($path)?[$path]:(array)$path;if(count($entries)>10000)throw new ReferenceException('selection-limit','Too many selectors.');$selections=[];
        foreach($entries as $key=>$value){if(!is_string($value))throw new ReferenceException('invalid-reference','Every selector must be a string.');if($shape==='named')self::safeKey((string)$key);$selector=self::selector($value,$spec['syntax']??null);if(isset($spec['src'])&&$selector['anchor']==='relative')throw new ReferenceException('invalid-address','External selectors require $ or ID.');$selections[]=['name'=>$shape==='named'?(string)$key:null,'selector'=>$selector];}
        return ['spec'=>$spec,'shape'=>$shape,'selections'=>$selections];
    }
    public static function source(mixed $value):mixed {
        if(is_array($value)&&count($value)===1&&array_key_exists(':ref',$value))return $value[':ref'];
        if(!$value instanceof Node||$value->kind!==Node::RUNTIME||$value->name!=='ref')return null;
        if($value->value!==null)return $value->value;
        $text=count($value->children)===1&&$value->children[0]->kind===Node::VALUE&&$value->children[0]->type==='string'?trim($value->children[0]->value):null;
        if($value->props){if($text!==null&&array_key_exists('path',$value->props))throw new ReferenceException('invalid-reference','Specify path as attribute or body, not both.');return [...$value->props,...($text!==null?['path'=>$text]:[])];}return $text;
    }
    public static function compact(StructuralAddress $address):string {
        $name=fn(string $key):string=>$key!==''&&!preg_match('/[\s\[\]\/{}@#"\'\\\\]/',$key)&&!str_contains($key,'::')&&!in_array($key,['.','..'],true)?$key:'['.json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).']';
        $source='$';$data=false;foreach($address->segments as $i=>$s){$kind=$s['kind'];
            if($kind==='child'){if($data)throw new ReferenceException('legacy-required','Structural result traversal remains legacy.');$source.='/'.($i===0&&in_array($s['name'],self::LEGACY,true)?'['.json_encode($s['name']).']':$name($s['name']));}
            elseif($kind==='children'){$source.='/['.$s['index'].']';}
            elseif($kind==='occurrence')$source.='/'.$name($s['name']).'['.$s['index'].']';
            elseif($kind==='props'||$kind==='result'){$source.='/'.($kind==='props'?'@'.$name($s['name']):'#'.$name($s['output']??'result'));$data=true;}
            elseif(in_array($kind,['member','entry'],true))$source.='['.json_encode($s['name']??$s['key'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).']';
            elseif($kind==='item')$source.='['.$s['index'].']';
            elseif($kind==='data')$source.='['.(($s['quoted']??false)?json_encode($s['key'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):$s['key']).']';
            elseif($kind==='all')$source.='[]';else throw new ReferenceException('legacy-required','Segment requires legacy syntax.');
        }return $source;
    }
}
