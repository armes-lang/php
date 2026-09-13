<?php

declare(strict_types=1);

namespace Armes\Runtime;

final class ValueTypes
{
    /** @var array<string, true> */
    private const CANONICAL = [
        'string' => true,
        'int' => true,
        'float' => true,
        'bool' => true,
        'null' => true,
        'array' => true,
        'object' => true,
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'string' => 'string',
        ':string' => 'string',
        'type:string' => 'string',
        ':type:string' => 'string',
        'int' => 'int',
        'integer' => 'int',
        ':int' => 'int',
        ':integer' => 'int',
        'type:int' => 'int',
        'type:integer' => 'int',
        ':type:int' => 'int',
        ':type:integer' => 'int',
        'float' => 'float',
        ':float' => 'float',
        'type:float' => 'float',
        ':type:float' => 'float',
        'bool' => 'bool',
        'boolean' => 'bool',
        ':bool' => 'bool',
        ':boolean' => 'bool',
        'type:bool' => 'bool',
        'type:boolean' => 'bool',
        ':type:bool' => 'bool',
        ':type:boolean' => 'bool',
        'null' => 'null',
        ':null' => 'null',
        'type:null' => 'null',
        ':type:null' => 'null',
        'array' => 'array',
        ':array' => 'array',
        'type:array' => 'array',
        ':type:array' => 'array',
        'object' => 'object',
        ':object' => 'object',
        'type:object' => 'object',
        ':type:object' => 'object',
    ];

    public static function normalize(string $alias): ?string
    {
        return self::ALIASES[$alias] ?? null;
    }

    public static function isCanonical(string $type): bool
    {
        return isset(self::CANONICAL[$type]);
    }

    public static function key(string $type): string
    {
        return ':type:' . $type;
    }

    public static function tag(string $type): string
    {
        return ':type:' . $type;
    }
}
