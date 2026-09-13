<?php

declare(strict_types=1);

namespace Armes\Parser\Native;

use Armes\Exception\ParseException;
use Armes\Runtime\LogicOperators;
use Armes\Runtime\ValueTypes;
use Armes\Tree\Node;
use stdClass;

final class NativeTagParser
{
    private const RUNTIME_PREFIX = ':';

    private bool $strict = true;

    /** @var list<array{level: string, message: string}> */
    private array $diagnostics = [];

    /** @var array<string, true> */
    private const STRUCTURAL_VALUE_RUNTIME = [
        'comment' => true,
        'doctype' => true,
        'cdata' => true,
        'raw' => true,
        'text' => true,
    ];

    /**
     * @param list<array{level: string, message: string}>|null $diagnostics
     */
    public function parse(mixed $value, ?string $source = null, bool $strict = true, ?array &$diagnostics = null): Node
    {
        $previousStrict = $this->strict;
        $previousDiagnostics = $this->diagnostics;
        $this->strict = $strict;
        $this->diagnostics = [];

        try {
            return $this->parseValue($value, $this->meta($source, ''));
        } finally {
            if ($diagnostics !== null) {
                array_push($diagnostics, ...$this->diagnostics);
            }

            $this->strict = $previousStrict;
            $this->diagnostics = $previousDiagnostics;
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseValue(mixed $value, array $meta): Node
    {
        if ($this->isMap($value)) {
            return $this->parseMap($this->mapEntries($value), $meta);
        }

        if (is_array($value)) {
            return Node::fragment(
                array_map(
                    fn (mixed $child, int|string $index): Node => $this->parseValue($child, $this->appendPointer($meta, (string) $index)),
                    $value,
                    array_keys($value),
                ),
                $meta,
            );
        }

        return $this->inferValueNode($value, $meta);
    }

    /**
     * @param array<string, mixed> $entries
     * @param array<string, mixed> $meta
     */
    private function parseMap(array $entries, array $meta): Node
    {
        if ($entries === []) {
            return Node::value('object', [], $meta);
        }

        $nodes = [];
        foreach ($entries as $key => $value) {
            if ($key === '') {
                $this->fail('ARMES object keys must be non-empty strings.', $meta);
            }
            $nodes[] = $this->parseKeyedNode($key, $value, $this->appendPointer($meta, $key));
        }

        return count($nodes) === 1 ? $nodes[0] : Node::fragment($nodes, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseKeyedNode(string $key, mixed $value, array $meta): Node
    {
        $directLogicOp = LogicOperators::normalizeDirect($key);
        if ($directLogicOp !== null) {
            return $this->parseLogic($directLogicOp, $value, $meta);
        }

        if (str_starts_with($key, ':logic:')) {
            return $this->handleUnknownLogicOperator($key, $value, $meta, 'runtime-key');
        }

        if (str_starts_with($key, self::RUNTIME_PREFIX)) {
            $name = substr($key, 1);
            if ($name === '') {
                $this->fail(sprintf('Runtime node names must not be empty for key "%s".', $key), $meta);
            }
            return $this->parseRuntime($name, $value, $meta);
        }

        return $this->parseElement($key, $value, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseElement(string $name, mixed $body, array $meta): Node
    {
        $props = [];
        $children = [];

        if ($this->isMap($body)) {
            $entries = $this->mapEntries($body);
            if (array_key_exists('@', $entries) || array_key_exists('#', $entries)) {
                $props = array_key_exists('@', $entries)
                    ? $this->parseProps($entries['@'], $this->appendPointer($meta, '@'))
                    : [];

                if (array_key_exists('#', $entries)) {
                    $children = $this->parseChildren($entries['#'], $this->appendPointer($meta, '#'));
                }

                foreach ($entries as $childKey => $childValue) {
                    if ($childKey === '@' || $childKey === '#') {
                        continue;
                    }
                    $children[] = $this->parseKeyedNode($childKey, $childValue, $this->appendPointer($meta, $childKey));
                }
            } else {
                $children = $this->parseChildren($body, $meta);
            }
        } elseif (is_array($body)) {
            $children = $this->parseChildren($body, $meta);
        } else {
            $children = [$this->inferValueNode($body, $meta)];
        }

        return Node::element($name, $props, $children, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseRuntime(string $name, mixed $body, array $meta): Node
    {
        if ($name === 'logic') {
            return $this->parseLogicWrapper($body, $meta);
        }

        $typedName = ValueTypes::normalize(self::RUNTIME_PREFIX . $name);
        if ($typedName !== null) {
            return $this->explicitValueNode($typedName, $this->explicitRuntimeBodyValue($typedName, $body, $meta), $meta);
        }

        if (str_starts_with($name, 'type:')) {
            return $this->handleUnknownTypeCommand(self::RUNTIME_PREFIX . $name, $body, $meta);
        }

        $canonicalName = $name;

        if (isset(self::STRUCTURAL_VALUE_RUNTIME[$canonicalName])) {
            return $this->explicitStructuralValueNode($canonicalName, $body, $meta);
        }

        if ($canonicalName === 'expr') {
            return Node::runtime('expr', [], [], $this->parseExpressionPayload($this->toPlainData($body), $meta), $meta);
        }

        if ($canonicalName === 'if') {
            return $this->parseIfRuntime($body, $meta);
        }

        if (in_array($canonicalName, ['props', 'attributes'], true) && $this->isMap($body)) {
            return Node::runtime($canonicalName, [], [], $this->parseProps($body, $meta), $meta);
        }

        if ($this->isMap($body)) {
            $entries = $this->mapEntries($body);
            if (array_key_exists('@', $entries) || array_key_exists('#', $entries)) {
                $props = array_key_exists('@', $entries)
                    ? $this->parseProps($entries['@'], $this->appendPointer($meta, '@'))
                    : [];
                $children = array_key_exists('#', $entries)
                    ? $this->parseChildren($entries['#'], $this->appendPointer($meta, '#'))
                    : [];

                foreach ($entries as $childKey => $childValue) {
                    if ($childKey === '@' || $childKey === '#') {
                        continue;
                    }
                    $children[] = $this->parseKeyedNode($childKey, $childValue, $this->appendPointer($meta, $childKey));
                }

                return Node::runtime($canonicalName, $props, $children, null, $meta);
            }
        }

        return Node::runtime($canonicalName, [], [], $this->toPlainData($body), $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseLogicWrapper(mixed $body, array $meta): Node
    {
        if (!$this->isMap($body)) {
            $this->fail(':logic requires an object/map payload.', $meta);
        }

        $entries = $this->mapEntries($body);
        if (count($entries) !== 1) {
            $this->fail(':logic requires exactly one operator.', $meta);
        }

        $key = (string) array_key_first($entries);
        $op = str_starts_with($key, self::RUNTIME_PREFIX) ? LogicOperators::normalize($key) : null;
        if ($op === null) {
            return $this->handleUnknownLogicOperator($key, $entries[$key], $this->appendPointer($meta, $key), 'runtime-wrapper');
        }

        return $this->parseLogic($op, $entries[$key], $this->appendPointer($meta, $key));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseExpressionPayload(mixed $body, array $meta): mixed
    {
        if (!$this->isMap($body)) {
            return $body;
        }

        $entries = $this->mapEntries($body);
        if (count($entries) !== 1) {
            return $this->toPlainData($body);
        }

        $key = (string) array_key_first($entries);
        $op = LogicOperators::normalize($key, true);
        if ($op === null) {
            return $this->toPlainData($body);
        }

        return $this->parseLogic($op, $entries[$key], $this->appendPointer($meta, $key), true);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseLogic(string $op, mixed $body, array $meta, bool $allowBareReadableArgs = false): Node
    {
        return Node::logic($op, $this->parseLogicArgs($body, $meta, $allowBareReadableArgs), $meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<Node>
     */
    private function parseLogicArgs(mixed $body, array $meta, bool $allowBareReadable = false): array
    {
        if (is_array($body) && !$this->isAssocArray($body)) {
            return array_map(
                fn (mixed $child, int|string $index): Node => $this->parseLogicArg($child, $this->appendPointer($meta, (string) $index), $allowBareReadable),
                $body,
                array_keys($body),
            );
        }

        return [$this->parseLogicArg($body, $this->appendPointer($meta, '#'), $allowBareReadable)];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseLogicArg(mixed $value, array $meta, bool $allowBareReadable = false): Node
    {
        if ($this->isMap($value)) {
            $entries = $this->mapEntries($value);
            if (count($entries) === 1) {
                $key = (string) array_key_first($entries);
                $op = LogicOperators::normalize($key, $allowBareReadable);
                if ($op !== null) {
                    return $this->parseLogic($op, $entries[$key], $this->appendPointer($meta, $key), $allowBareReadable);
                }
            }
        }

        return $this->parseValue($value, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function handleUnknownLogicOperator(string $key, mixed $body, array $meta, string $preserveAs = 'runtime-key'): Node
    {
        $message = sprintf('Unknown ARMES Logic operator "%s".', $key);
        if ($this->strict) {
            $this->fail($message, $meta);
        }

        $this->warn($message, $meta);
        if ($preserveAs === 'runtime-wrapper') {
            return Node::runtime('logic', [], [], [$key => $this->toPlainData($body)], $meta);
        }

        return Node::runtime(substr($key, 1), [], [], $this->toPlainData($body), $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function handleUnknownTypeCommand(string $key, mixed $body, array $meta): Node
    {
        $message = sprintf('Unknown ARMES Type "%s".', $key);
        if ($this->strict) {
            $this->fail($message, $meta);
        }

        $this->warn($message, $meta);
        return Node::runtime(substr($key, 1), [], [], $this->toPlainData($body), $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function parseIfRuntime(mixed $body, array $meta): Node
    {
        if (!$this->isMap($body)) {
            return Node::runtime('if', [], $this->parseChildren($body, $meta), null, $meta);
        }

        $entries = $this->mapEntries($body);
        $props = array_key_exists('@', $entries)
            ? $this->parseProps($entries['@'], $this->appendPointer($meta, '@'))
            : [];
        $children = array_key_exists('#', $entries)
            ? $this->parseChildren($entries['#'], $this->appendPointer($meta, '#'))
            : [];

        foreach ($entries as $key => $value) {
            if ($key === '@' || $key === '#') {
                continue;
            }

            if ($key === ':else') {
                $props['else'] = $this->parseChildren($value, $this->appendPointer($meta, ':else'));
                continue;
            }

            $children[] = $this->parseKeyedNode($key, $value, $this->appendPointer($meta, $key));
        }

        return Node::runtime('if', $props, $children, null, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<Node>
     */
    private function parseChildren(mixed $value, array $meta): array
    {
        if (is_array($value) && !$this->isAssocArray($value)) {
            return array_map(
                fn (mixed $child, int|string $index): Node => $this->parseValue($child, $this->appendPointer($meta, (string) $index)),
                $value,
                array_keys($value),
            );
        }

        if ($this->isMap($value)) {
            $entries = $this->mapEntries($value);
            if ($entries === []) {
                return [Node::value('object', [], $meta)];
            }

            $children = [];
            foreach ($entries as $key => $childValue) {
                $children[] = $this->parseKeyedNode($key, $childValue, $this->appendPointer($meta, $key));
            }
            return $children;
        }

        return [$this->inferValueNode($value, $meta)];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function parseProps(mixed $value, array $meta): array
    {
        if (!$this->isMap($value)) {
            $this->fail('Props must be an object/map.', $meta);
        }

        $props = [];
        foreach ($this->mapEntries($value) as $key => $propValue) {
            $props[$key] = $this->parsePropValue($propValue, $this->appendPointer($meta, $key));
        }
        return $props;
    }

    /**
     * Runtime nodes are significant inside props, but plain objects remain data.
     *
     * @param array<string, mixed> $meta
     */
    private function parsePropValue(mixed $value, array $meta): mixed
    {
        if ($this->isMap($value)) {
            $entries = $this->mapEntries($value);
            if (count($entries) === 1) {
                $key = array_key_first($entries);
                if (is_string($key) && str_starts_with($key, self::RUNTIME_PREFIX)) {
                    $node = $this->parseKeyedNode($key, $entries[$key], $this->appendPointer($meta, $key));
                    if (in_array($node->kind, [Node::RUNTIME, Node::VALUE, Node::LOGIC], true)) {
                        return $node;
                    }
                }
            }

            $result = [];
            foreach ($entries as $key => $childValue) {
                $result[$key] = $this->parsePropValue($childValue, $this->appendPointer($meta, $key));
            }
            return $result;
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $child, int|string $index): mixed => $this->parsePropValue($child, $this->appendPointer($meta, (string) $index)),
                $value,
                array_keys($value),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function inferValueNode(mixed $value, array $meta): Node
    {
        return Node::value($this->inferType($value), $this->toPlainData($value), $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function explicitValueNode(string $type, mixed $value, array $meta): Node
    {
        return match ($type) {
            'string' => Node::value('string', is_scalar($value) || $value === null ? (string) $value : json_encode($this->toPlainData($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $meta),
            'int' => Node::value('int', (int) (is_scalar($value) || $value === null ? $value : 0), $meta),
            'float' => Node::value('float', (float) (is_scalar($value) || $value === null ? $value : 0), $meta),
            'bool' => Node::value('bool', filter_var($value, FILTER_VALIDATE_BOOLEAN), $meta),
            'null' => Node::value('null', null, $meta),
            'array' => Node::value('array', array_values((array) $this->toPlainData($value)), $meta),
            'object' => Node::value('object', (array) $this->toPlainData($value), $meta),
            default => Node::value($type, $this->toPlainData($value), $meta),
        };
    }

    /**
     * Markup compact JSON can represent typed tags as {":int":{"#":["42"]}}.
     *
     * @param array<string, mixed> $meta
     */
    private function explicitRuntimeBodyValue(string $type, mixed $body, array $meta): mixed
    {
        if (!$this->isMap($body)) {
            return $body;
        }

        $entries = $this->mapEntries($body);
        if (!array_key_exists('#', $entries)) {
            return $body;
        }

        $children = $this->parseChildren($entries['#'], $this->appendPointer($meta, '#'));
        $values = [];
        foreach ($children as $child) {
            if ($child->kind === Node::VALUE) {
                $values[] = $child->value;
                continue;
            }

            $values[] = $child->toArray();
        }

        if (in_array($type, ['string', 'int', 'float', 'bool'], true)) {
            return implode('', array_map(static fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $values));
        }

        if ($type === 'array') {
            return $values;
        }

        return count($values) === 1 ? $values[0] : $values;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function explicitStructuralValueNode(string $type, mixed $value, array $meta): Node
    {
        $nodeType = $type === 'text' ? 'string' : $type;
        if ($nodeType === 'string' && !is_scalar($value) && $value !== null) {
            return Node::value('string', json_encode($this->toPlainData($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', $meta);
        }

        return Node::value($nodeType, is_scalar($value) || $value === null ? (string) $value : $this->toPlainData($value), $meta);
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            is_array($value) => 'array',
            $value instanceof stdClass => 'object',
            default => get_debug_type($value),
        };
    }

    private function toPlainData(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $childValue) {
                $result[$key] = $this->toPlainData($childValue);
            }
            return $result;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $childValue) {
                $result[$key] = $this->toPlainData($childValue);
            }
            return $this->isAssocArray($value) ? $result : array_values($result);
        }

        return $value;
    }

    private function isMap(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && $this->isAssocArray($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEntries(mixed $value): array
    {
        $entries = $value instanceof stdClass ? get_object_vars($value) : $value;
        $result = [];
        foreach ($entries as $key => $childValue) {
            if (!is_string($key) && !is_int($key)) {
                $this->fail('ARMES object keys must be strings.', $this->meta(null, ''));
            }
            $result[(string) $key] = $childValue;
        }
        return $result;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssocArray(array $value): bool
    {
        return !array_is_list($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(?string $source, string $pointer): array
    {
        return array_filter([
            'source' => $source,
            'pointer' => $pointer === '' ? '/' : $pointer,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function appendPointer(array $meta, string $segment): array
    {
        $meta['pointer'] = ($meta['pointer'] ?? '/') === '/'
            ? '/' . $this->escapePointer($segment)
            : $meta['pointer'] . '/' . $this->escapePointer($segment);
        return $meta;
    }

    private function escapePointer(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function fail(string $message, array $meta): never
    {
        throw new ParseException($message . ' Source: ' . $this->formatLocation($meta) . '.');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function warn(string $message, array $meta): void
    {
        $this->diagnostics[] = [
            'level' => 'warning',
            'message' => $message . ' Source: ' . $this->formatLocation($meta) . '.',
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function formatLocation(array $meta): string
    {
        $source = isset($meta['source']) && is_string($meta['source']) && $meta['source'] !== ''
            ? $meta['source']
            : 'native source';
        $pointer = isset($meta['pointer']) && is_string($meta['pointer']) && $meta['pointer'] !== ''
            ? $meta['pointer']
            : '/';

        return $source . ' at ' . $pointer;
    }
}
