<?php

declare(strict_types=1);

namespace Armes\Runtime;

final class LogicOperators
{
    /** @var list<string> */
    public const CANONICAL = [
        'eq',
        'ne',
        'gt',
        'gte',
        'lt',
        'lte',
        'and',
        'or',
        'not',
        'add',
        'sub',
        'mul',
        'div',
        'mod',
        'var',
    ];

    /** @var array<string, string> */
    private const SYMBOLS = [
        'eq' => '==',
        'ne' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'not' => '!',
        'add' => '+',
        'sub' => '-',
        'mul' => '*',
        'div' => '/',
        'mod' => '%',
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        '==' => 'eq',
        ':==' => 'eq',
        ':eq' => 'eq',
        'logic:==' => 'eq',
        'logic:eq' => 'eq',
        ':logic:==' => 'eq',
        ':logic:eq' => 'eq',
        '!=' => 'ne',
        ':!=' => 'ne',
        ':ne' => 'ne',
        'logic:!=' => 'ne',
        'logic:ne' => 'ne',
        ':logic:!=' => 'ne',
        ':logic:ne' => 'ne',
        '>' => 'gt',
        ':>' => 'gt',
        ':gt' => 'gt',
        'logic:>' => 'gt',
        'logic:gt' => 'gt',
        ':logic:>' => 'gt',
        ':logic:gt' => 'gt',
        '>=' => 'gte',
        ':>=' => 'gte',
        ':gte' => 'gte',
        'logic:>=' => 'gte',
        'logic:gte' => 'gte',
        ':logic:>=' => 'gte',
        ':logic:gte' => 'gte',
        '<' => 'lt',
        ':<' => 'lt',
        ':lt' => 'lt',
        'logic:<' => 'lt',
        'logic:lt' => 'lt',
        ':logic:<' => 'lt',
        ':logic:lt' => 'lt',
        '<=' => 'lte',
        ':<=' => 'lte',
        ':lte' => 'lte',
        'logic:<=' => 'lte',
        'logic:lte' => 'lte',
        ':logic:<=' => 'lte',
        ':logic:lte' => 'lte',
        ':and' => 'and',
        'logic:and' => 'and',
        ':logic:and' => 'and',
        'and' => 'and',
        ':or' => 'or',
        'logic:or' => 'or',
        ':logic:or' => 'or',
        'or' => 'or',
        '!' => 'not',
        ':!' => 'not',
        ':not' => 'not',
        'logic:!' => 'not',
        'logic:not' => 'not',
        ':logic:!' => 'not',
        ':logic:not' => 'not',
        'not' => 'not',
        '+' => 'add',
        ':+' => 'add',
        ':add' => 'add',
        'logic:+' => 'add',
        'logic:add' => 'add',
        ':logic:+' => 'add',
        ':logic:add' => 'add',
        '-' => 'sub',
        ':-' => 'sub',
        ':sub' => 'sub',
        'logic:-' => 'sub',
        'logic:sub' => 'sub',
        ':logic:-' => 'sub',
        ':logic:sub' => 'sub',
        '*' => 'mul',
        ':*' => 'mul',
        ':mul' => 'mul',
        'logic:*' => 'mul',
        'logic:mul' => 'mul',
        ':logic:*' => 'mul',
        ':logic:mul' => 'mul',
        '/' => 'div',
        ':/' => 'div',
        ':div' => 'div',
        'logic:/' => 'div',
        'logic:div' => 'div',
        ':logic:/' => 'div',
        ':logic:div' => 'div',
        '%' => 'mod',
        ':%' => 'mod',
        ':mod' => 'mod',
        'logic:%' => 'mod',
        'logic:mod' => 'mod',
        ':logic:%' => 'mod',
        ':logic:mod' => 'mod',
        ':var' => 'var',
        'logic:var' => 'var',
        ':logic:var' => 'var',
        'var' => 'var',
    ];

    /** @var array<string, string> */
    private const DIRECT_ALIASES = [
        ':==' => 'eq',
        ':eq' => 'eq',
        ':logic:==' => 'eq',
        ':logic:eq' => 'eq',
        ':!=' => 'ne',
        ':ne' => 'ne',
        ':logic:!=' => 'ne',
        ':logic:ne' => 'ne',
        ':>' => 'gt',
        ':gt' => 'gt',
        ':logic:>' => 'gt',
        ':logic:gt' => 'gt',
        ':>=' => 'gte',
        ':gte' => 'gte',
        ':logic:>=' => 'gte',
        ':logic:gte' => 'gte',
        ':<' => 'lt',
        ':lt' => 'lt',
        ':logic:<' => 'lt',
        ':logic:lt' => 'lt',
        ':<=' => 'lte',
        ':lte' => 'lte',
        ':logic:<=' => 'lte',
        ':logic:lte' => 'lte',
        ':and' => 'and',
        ':logic:and' => 'and',
        ':or' => 'or',
        ':logic:or' => 'or',
        ':!' => 'not',
        ':not' => 'not',
        ':logic:!' => 'not',
        ':logic:not' => 'not',
        ':+' => 'add',
        ':add' => 'add',
        ':logic:+' => 'add',
        ':logic:add' => 'add',
        ':-' => 'sub',
        ':sub' => 'sub',
        ':logic:-' => 'sub',
        ':logic:sub' => 'sub',
        ':*' => 'mul',
        ':mul' => 'mul',
        ':logic:*' => 'mul',
        ':logic:mul' => 'mul',
        ':/' => 'div',
        ':div' => 'div',
        ':logic:/' => 'div',
        ':logic:div' => 'div',
        ':%' => 'mod',
        ':mod' => 'mod',
        ':logic:%' => 'mod',
        ':logic:mod' => 'mod',
        ':var' => 'var',
        ':logic:var' => 'var',
    ];

    public static function normalize(string $alias, bool $allowBareReadable = false): ?string
    {
        if ($allowBareReadable && in_array($alias, self::CANONICAL, true)) {
            return $alias;
        }

        $normalized = self::ALIASES[$alias] ?? null;
        if ($normalized === null) {
            return null;
        }

        if ($allowBareReadable || isset(self::DIRECT_ALIASES[$alias])) {
            return $normalized;
        }

        return null;
    }

    public static function normalizeDirect(string $alias): ?string
    {
        return self::DIRECT_ALIASES[$alias] ?? null;
    }

    public static function symbol(string $op): string
    {
        return self::SYMBOLS[$op] ?? $op;
    }

    public static function tag(string $op, string $operatorStyle = 'readable'): string
    {
        return $operatorStyle === 'symbol' ? ':' . self::symbol($op) : ':logic:' . $op;
    }
}
