<?php
declare(strict_types=1);
namespace Armes\Loader;
/** PHP hosts provide synchronous files, workspaces, or memory resources. */
interface SourceLoader {public function resolve(?string $from,string $specifier):string;public function readText(string $uri,int $maxBytes=8388608):string;}
