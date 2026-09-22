<?php
declare(strict_types=1);
namespace Armes\Runtime\References;

final readonly class StructuralAddress
{
    /** @param list<array{kind:string,name?:string,index?:int,key?:string}> $segments */
    public function __construct(public array $segments = []) {}
    public static function parse(string $source): self
    {
        if ($source !== '$' && !str_starts_with($source, '$/')) throw new ReferenceException('invalid-address', 'Expected document root $.');
        $parts = $source === '$' ? [] : explode('/', substr($source, 2)); $segments = [];
        for ($i = 0; $i < count($parts); $i++) {
            $kind = $parts[$i];
            if (in_array($kind, ['value', 'result'], true)) { $segments[] = ['kind'=>$kind]; continue; }
            $raw = $parts[++$i] ?? null;
            if ($raw === null || preg_match('/~(?![01])/', $raw)) throw new ReferenceException('invalid-address', 'Invalid address escape or segment.');
            $value = str_replace(['~1','~0'], ['/','~'], $raw);
            if (in_array($kind, ['children','item','args'], true)) {
                if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || strlen($value) > 16 || (strlen($value) === 16 && strcmp($value, '9007199254740991') > 0)) throw new ReferenceException('invalid-index', 'Invalid structural index.');
                $segments[] = ['kind'=>$kind,'index'=>(int)$value];
            } elseif (in_array($kind, ['child','props','member'], true)) {
                if ($kind !== 'child' && in_array($value,['__proto__','prototype','constructor'],true)) throw new ReferenceException('unsafe-member', 'Unsupported member.');
                $segments[] = ['kind'=>$kind,'name'=>$value];
            } elseif ($kind === 'branch' && in_array($value,['then','else'],true)) $segments[]=['kind'=>$kind,'name'=>$value];
            elseif ($kind === 'entry') $segments[]=['kind'=>$kind,'key'=>$value];
            else throw new ReferenceException('invalid-address', 'Unknown address segment '.$kind);
        }
        return new self($segments);
    }
    public function __toString(): string
    {
        $source='$';
        foreach ($this->segments as $s) $source.='/'.$s['kind'].(count($s)>1?'/'.str_replace(['~','/'],['~0','~1'],(string)($s['name']??$s['index']??$s['key'])):'');
        return $source;
    }
}
