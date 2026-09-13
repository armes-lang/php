<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Exception\MappingException;
use Armes\Tree\Node;

final class PklEmitter
{
    public function emitTree(Node $node, string $mode = JsonEmitter::MODE_COMPACT): string
    {
        return $this->emitData((new JsonEmitter())->toData($node, $mode));
    }

    public function emitData(mixed $data): string
    {
        if (!is_array($data) || array_is_list($data)) {
            throw new MappingException('Pkl output requires an object/map root.');
        }

        return $this->moduleProperties($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function moduleProperties(array $data): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $this->property((string) $key, $value, 0, true);
        }
        return implode("\n", $lines) . "\n";
    }

    private function property(string $key, mixed $value, int $indent, bool $moduleProperty = false): string
    {
        $prefix = str_repeat('  ', $indent) . ($moduleProperty ? $this->moduleKey($key) : '[' . $this->string($key) . ']');

        if (is_array($value) && !array_is_list($value)) {
            return $prefix . ' = ' . $this->mapping($value, $indent);
        }

        return $prefix . ' = ' . $this->value($value, $indent);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapping(array $data, int $indent): string
    {
        if ($data === []) {
            return 'new Mapping {}';
        }

        $lines = ['new Mapping {'];
        foreach ($data as $key => $value) {
            $lines[] = $this->property((string) $key, $value, $indent + 1);
        }
        $lines[] = str_repeat('  ', $indent) . '}';
        return implode("\n", $lines);
    }

    private function value(mixed $value, int $indent): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return $this->mapping($value, $indent);
            }

            if ($value === []) {
                return 'List()';
            }

            $items = array_map(fn (mixed $item): string => $this->value($item, $indent + 1), $value);
            return 'List(' . implode(', ', $items) . ')';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->string((string) $value);
    }

    private function moduleKey(string $key): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return $key;
        }

        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $key) . '`';
    }

    private function string(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
