<?php
declare(strict_types=1);
namespace Armes\Loader;
use Armes\Parser\ParserRegistry;
use Armes\Runtime\References\ReferenceException;
final class ResourceStore {
    public array $resources=[];
    private int $bytes=0;
    private array $cache;
    private array $texts=[];
    public readonly array $limits;
    public function __construct(private ?SourceLoader $loader,private ParserRegistry $registry,private array $policy=[],array $limits=[],array &$cache=[]){$this->cache=&$cache;$this->limits=[...['sourceBytes'=>8388608,'totalSourceBytes'=>33554432,'resources'=>128,'selectionSize'=>10000,'totalSelections'=>100000,'work'=>1000000,'inlineDepth'=>64,'inlineTokens'=>100000],...$limits];foreach($this->limits as $limit)if(!is_int($limit)||$limit<1)throw new ReferenceException('invalid-limit','Limits require positive integers.');}
    public function read(array $spec,?string $from=null):array {
        if(!$this->loader)throw new ReferenceException('missing-loader','External references require a SourceLoader.');
        $src=$spec['src'];$absolute=str_starts_with($src,'/')||preg_match('/^[A-Za-z][\w+.-]*:/',$src);if(!$absolute&&!$from)throw new ReferenceException('missing-base','Relative resources require a declaring source or baseUri.');
        if(self::requiresNetworkPermission($src)&&!($this->policy['allowNetwork']??false))throw new ReferenceException('resource-denied','Network resources disabled.');
        $uri=$this->loader->resolve($from,$src);$roots=$this->policy['roots']??($this->loader instanceof FileSourceLoader?$this->loader->referenceRoots:null);
        if(self::requiresNetworkPermission($uri)&&!($this->policy['allowNetwork']??false))throw new ReferenceException('resource-denied','Network resources disabled.');
        if($this->loader instanceof FileSourceLoader){if(!$roots)throw new ReferenceException('resource-denied','Filesystem references require roots.');$uri=$this->loader->canonical($uri);$roots=array_map($this->loader->canonical(...),$roots);}
        if($roots&&!array_filter($roots,fn($root)=>$uri===rtrim($root,'/\\')||str_starts_with($uri,rtrim($root,'/\\').'/')||str_starts_with($uri,rtrim($root,'/\\').'\\')))throw new ReferenceException('resource-denied','Resource outside allowed roots.');
        if(isset($this->policy['canRead'])&&($this->policy['canRead'])($uri)===false)throw new ReferenceException('resource-denied','Resource denied.');
        $options=$this->registry->infer($uri,$spec);$key=json_encode([$uri,...$options,$this->limits['inlineDepth'],$this->limits['inlineTokens']]);if(isset($this->resources[$key]))return $this->resources[$key];if(count($this->resources)>=$this->limits['resources'])throw new ReferenceException('resource-limit','Resource count exceeded.');
        $text=$this->texts[$uri]??=$this->loader->readText($uri,$this->limits['sourceBytes']);$this->bytes+=strlen($text);if(strlen($text)>$this->limits['sourceBytes']||$this->bytes>$this->limits['totalSourceBytes'])throw new ReferenceException('source-limit','Resource size exceeded.');
        $cached=$this->cache[$key]??null;if(($cached['text']??null)!==$text)$cached=['uri'=>$uri,'text'=>$text,'byteLength'=>strlen($text),...$options,'value'=>$this->registry->parse($text,$uri,$options,$this->limits)];unset($this->cache[$key]);$this->cache[$key]=$cached;
        $retainedBytes=array_sum(array_column($this->cache,'byteLength'));while(count($this->cache)>$this->limits['resources']||$retainedBytes>$this->limits['totalSourceBytes']){$oldest=array_key_first($this->cache);$retainedBytes-=$this->cache[$oldest]['byteLength'];unset($this->cache[$oldest]);}
        return $this->resources[$key]=$cached;
    }
    public function validate():void {foreach($this->texts as $uri=>$text)if($this->loader->readText($uri,$this->limits['sourceBytes'])!==$text)throw new ReferenceException('resource-changed','Resource changed during evaluation: '.$uri);}
    private static function requiresNetworkPermission(string $locator):bool {
        if(strlen($locator)>=3&&ctype_alpha($locator[0])&&$locator[1]===':'&&in_array($locator[2],['/','\\'],true))return false;
        return preg_match('/^([A-Za-z][\w+.-]*):/',$locator,$match)&&!in_array(strtolower($match[1]),['file','memory','workspace'],true);
    }
}
