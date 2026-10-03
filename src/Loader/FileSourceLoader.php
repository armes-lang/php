<?php
declare(strict_types=1);
namespace Armes\Loader;
use Armes\Runtime\References\ReferenceException;
final class FileSourceLoader implements SourceLoader {
    public function __construct(public readonly array $referenceRoots){}
    public function resolve(?string $from,string $specifier):string {return str_starts_with($specifier,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$specifier)?$specifier:($from?dirname($from):getcwd()).'/'.$specifier;}
    public function canonical(string $uri):string {return realpath($uri)?:throw new ReferenceException('missing-resource','Resource unavailable: '.$uri);}
    public function readText(string $uri,int $maxBytes=8388608):string {
        $handle=fopen($uri,'rb');if(!$handle)throw new ReferenceException('missing-resource','Resource unavailable: '.$uri);
        try {$text=stream_get_contents($handle,$maxBytes+1);if($text===false)throw new ReferenceException('missing-resource','Resource read failed.');if(strlen($text)>$maxBytes)throw new ReferenceException('source-limit','Resource size exceeded.');return $text;}finally{fclose($handle);}
    }
}
