<?php
declare(strict_types=1);
namespace Armes\Loader;
use Armes\Runtime\References\ReferenceException;
final class MemorySourceLoader implements SourceLoader {
    public function __construct(private array $files){}
    public function resolve(?string $from,string $specifier):string {
        $path=str_starts_with($specifier,'/')?$specifier:($from?dirname($from):'/').'/'.$specifier;$parts=[];
        foreach(explode('/',str_replace('\\','/',$path)) as $part){if($part===''||$part==='.')continue;if($part==='..')array_pop($parts);else $parts[]=$part;}return '/'.implode('/',$parts);
    }
    public function readText(string $uri,int $maxBytes=8388608):string {$text=$this->files[$uri]??throw new ReferenceException('missing-resource','Source not found: '.$uri);if(strlen($text)>$maxBytes)throw new ReferenceException('source-limit','Resource size exceeded.');return $text;}
}
