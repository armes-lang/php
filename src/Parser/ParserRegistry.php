<?php
declare(strict_types=1);
namespace Armes\Parser;
use Armes\Parser\Native\NativeTagParser;
use Armes\Parser\Markup\DomMarkupParser;
use Armes\Parser\Markup\MarkupParseOptions;
use Armes\Runtime\References\ReferenceException;
use Symfony\Component\Yaml\Yaml;
use Devium\Toml\Toml;
final readonly class ParserRegistry {
    private function __construct(private array $parsers=[],private array $suffixes=[]){}
    public static function defaults():self {
        $r=(new self())->with('json',fn($text)=>json_decode($text,false,256,JSON_THROW_ON_ERROR),['.json'])
            ->with('yaml',fn($text)=>Yaml::parse($text,Yaml::PARSE_OBJECT_FOR_MAP),['.yaml','.yml'])
            ->with('toml',fn($text)=>Toml::decode($text),['.toml'])
            ->with('armes',null,['.armes'],'document',fn($text,$source,$limits)=>(new DomMarkupParser())->parseArmesString($text,$source,new MarkupParseOptions(mode:MarkupParseOptions::MODE_ARMES,inlineDepth:$limits['inlineDepth']??64,inlineTokens:$limits['inlineTokens']??100000)));
        foreach(['json','yaml','toml'] as $f)$r=$r->with($f,$r->parsers[$f]['decode'],['.armes.'.$f,...($f==='yaml'?['.armes.yml']:[])],'document');return $r;
    }
    public function with(string $format,?callable $decode,array $extensions=[],string $mode='data',?callable $document=null):self {
        $parsers=$this->parsers;$parsers[$format]=['decode'=>$decode,'document'=>$document];$suffixes=$this->suffixes;foreach($extensions as $ext)$suffixes[strtolower($ext)]=['format'=>$format,'mode'=>$mode];return new self($parsers,$suffixes);
    }
    public function infer(string $uri,array $spec=[]):array {
        $path=strtolower(preg_match('/^[A-Za-z][\w+.-]*:\/\//',$uri)?preg_split('/[?#]/',$uri)[0]:$uri);$suffixes=array_keys($this->suffixes);usort($suffixes,fn($a,$b)=>strlen($b)-strlen($a));$inferred=[];foreach($suffixes as $suffix)if(str_ends_with($path,$suffix)){$inferred=$this->suffixes[$suffix];break;}
        $format=$spec['format']??$inferred['format']??null;if(!$format||!isset($this->parsers[$format]))throw new ReferenceException('invalid-format','Unknown resource format: '.$uri);return ['format'=>$format,'mode'=>$spec['mode']??$inferred['mode']??'data'];
    }
    public function parse(string $text,string $source,array $options,array $limits=[]):mixed {
        $parser=$this->parsers[$options['format']]??throw new ReferenceException('invalid-format','Unknown format.');
        if($options['mode']==='document'&&$parser['document'])return ($parser['document'])($text,$source,$limits);
        if(!$parser['decode'])throw new ReferenceException('invalid-mode','Format has no data decoder.');
        $value=($parser['decode'])($text);$work=0;$check=function($v,$depth)use(&$check,&$work){if(++$work>1000000||$depth>256)throw new ReferenceException('resource-limit','Parsed resource depth/work exceeded.');if(is_array($v)||$v instanceof \stdClass)foreach($v as $child)$check($child,$depth+1);};$check($value,0);
        return $options['mode']==='data'?$value:(new NativeTagParser())->parse($value,$source);
    }
}
