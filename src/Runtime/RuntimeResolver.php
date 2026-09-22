<?php

declare(strict_types=1);

namespace Armes\Runtime;

use Armes\Exception\ImportException;
use Armes\Exception\RuntimeResolutionException;
use Armes\Parser\Json\JsonTagParser;
use Armes\Tree\Node;

class RuntimeResolver
{
    /** @var list<array{level: string, message: string}> */
    private array $diagnostics = [];

    /** @var array<string, array{mtime: int, hash: string, tree: Node}> */
    private array $importCache = [];

    public function __construct(
        private readonly bool $strict = true,
        private readonly ?JsonTagParser $parser = null,
        private readonly ?LogicEvaluator $logic = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(Node $node, array $context = []): Node
    {
        $resolved = $this->resolveToList($node, $context, null, []);
        return count($resolved) === 1 ? $resolved[0] : Node::fragment($resolved, $node->meta);
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    protected function resolveToList(Node $node, array $context, ?string $parentKind, array $importStack): array
    {
        return match ($node->kind) {
            Node::FRAGMENT => $this->resolveChildren($node->children, $context, null, $importStack),
            Node::ELEMENT => [$this->resolveElement($node, $context, $importStack)],
            Node::VALUE => [$node],
            Node::LOGIC => [Node::value($this->inferType($value = $this->logic()->evaluate($node, $context)), $value, $node->meta)],
            Node::RUNTIME => $this->resolveRuntimeNode($node, $context, $parentKind, $importStack),
            default => $this->failOrSkip(sprintf('Cannot resolve unknown node kind "%s".', $node->kind)),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     */
    private function resolveElement(Node $node, array $context, array $importStack): Node
    {
        $props = $this->resolveProps($node->props, $context, $importStack);
        $children = [];

        foreach ($node->children as $child) {
            if ($child->kind === Node::RUNTIME && in_array($child->name, ['props', 'attributes'], true)) {
                $props = array_replace($props, $this->resolveModifierProps($child, $context, $importStack));
                continue;
            }

            array_push($children, ...$this->resolveToList($child, $context, Node::ELEMENT, $importStack));
        }

        return Node::element((string) $node->name, $props, $children, $node->meta);
    }

    /**
     * @param list<Node> $children
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    private function resolveChildren(array $children, array $context, ?string $parentKind, array $importStack): array
    {
        $resolved = [];
        foreach ($children as $child) {
            array_push($resolved, ...$this->resolveToList($child, $context, $parentKind, $importStack));
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    private function resolveRuntimeNode(Node $node, array $context, ?string $parentKind, array $importStack): array
    {
        return match ($node->name) {
            'expr' => [Node::value($this->inferType($value = $this->expressionValue($node, $context)), $value, $node->meta)],
            'if' => $this->resolveIf($node, $context, $importStack),
            'else', 'elseif' => $this->failOrSkip(sprintf('Runtime node ":%s" is only valid inside ":if".', $node->name)),
            'each' => $this->resolveEach($node, $context, $importStack),
            'import', 'include' => $this->resolveImport($node, $context, $importStack),
            'props', 'attributes' => $parentKind === Node::ELEMENT
                ? []
                : $this->failOrSkip(sprintf('Runtime node ":%s" must be a child of an element.', $node->name)),
            'php', 'js', 'ts', 'code' => $this->failOrSkip(sprintf('Payload node ":%s" is recognized but not executable or renderable by the default runtime.', $node->name)),
            default => $this->failOrSkip(sprintf('Unknown runtime node ":%s".', $node->name)),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    private function resolveIf(Node $node, array $context, array $importStack): array
    {
        if (!array_key_exists('test', $node->props)) {
            return $this->failOrSkip('Runtime node ":if" requires a "test" prop.');
        }

        $test = $this->resolvePropValue($node->props['test'], $context, $importStack);
        $branch = $this->logic()->truthy($test) ? $node->children : ($node->props['else'] ?? []);

        if (!is_array($branch)) {
            return $this->failOrSkip('Runtime node ":if" has invalid else branch.');
        }

        return $this->resolveChildren($branch, $context, null, $importStack);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    private function resolveEach(Node $node, array $context, array $importStack): array
    {
        if (!array_key_exists('items', $node->props)) {
            return $this->failOrSkip('Runtime node ":each" requires an "items" prop.');
        }

        $items = $this->resolvePropValue($node->props['items'], $context, $importStack);
        if (!is_iterable($items)) {
            return $this->failOrSkip('Runtime node ":each" items prop must resolve to an iterable value.');
        }

        $as = isset($node->props['as']) && is_string($node->props['as']) ? $node->props['as'] : 'item';
        $indexName = isset($node->props['index']) && is_string($node->props['index']) ? $node->props['index'] : 'index';
        $resolved = [];
        $index = 0;
        foreach ($items as $key => $item) {
            $loopContext = [
                ...$context,
                $as => $item,
                $indexName => $index,
                'key' => $key,
            ];
            array_push($resolved, ...$this->resolveIteration($node, $loopContext, $importStack, $key, $items));
            $index++;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return list<Node>
     */
    private function resolveImport(Node $node, array $context, array $importStack): array
    {
        $src = is_string($node->value) ? $node->value : ($node->props['src'] ?? null);
        if (!is_string($src) || $src === '') {
            return $this->failOrSkip('Runtime node ":import" requires a string path or "src" prop.');
        }

        $source = isset($node->meta['source']) && is_string($node->meta['source']) ? $node->meta['source'] : null;
        $path = $this->resolveImportPath($src, $source);
        if (in_array($path, $importStack, true)) {
            throw new ImportException('Circular ARMES import detected: ' . implode(' -> ', [...$importStack, $path]));
        }

        $importProps = isset($node->props['props']) && is_array($node->props['props'])
            ? $this->resolveProps($node->props['props'], $context, $importStack)
            : [];
        $importContext = [
            ...$context,
            ...$importProps,
            'props' => $importProps,
        ];

        $tree = $this->loadImport($path);
        $resolvedList = $this->resolveImportedTree($tree, $importContext, [...$importStack, $path], $node);
        $resolved = count($resolvedList) === 1 ? $resolvedList[0] : Node::fragment($resolvedList, $tree->meta);
        $slotChildren = $node->children === []
            ? []
            : $this->resolveChildren($node->children, $context, null, $importStack);

        if ($slotChildren !== []) {
            $resolved = $this->appendSlotChildren($resolved, $slotChildren);
        }

        return $resolved->kind === Node::FRAGMENT ? $resolved->children : [$resolved];
    }

    /** Existing loop behavior with a hook for instance-local session evaluation. */
    protected function resolveIteration(Node $node, array $context, array $stack, int|string $key, mixed $items): array
    {
        return $this->resolveChildren($node->children, $context, null, $stack);
    }

    protected function resolveImportedTree(Node $tree, array $context, array $stack, Node $owner): array
    {
        return $this->resolveToList($tree, $context, null, $stack);
    }

    /** @param list<Node> $slotChildren */
    private function appendSlotChildren(Node $node, array $slotChildren): Node
    {
        if ($node->kind === Node::ELEMENT) {
            return $node->withChildren([...$node->children, ...$slotChildren]);
        }

        if ($node->kind === Node::FRAGMENT) {
            return $node->withChildren([...$node->children, ...$slotChildren]);
        }

        return Node::fragment([$node, ...$slotChildren], $node->meta);
    }

    private function resolveImportPath(string $src, ?string $source): string
    {
        $candidate = str_starts_with($src, DIRECTORY_SEPARATOR)
            ? $src
            : (($source ? dirname($source) : getcwd()) . DIRECTORY_SEPARATOR . $src);

        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            throw new ImportException(sprintf('ARMES import "%s" could not be resolved from "%s".', $src, $source ?? getcwd()));
        }

        return $real;
    }

    private function loadImport(string $path): Node
    {
        $mtime = filemtime($path);
        $content = file_get_contents($path);
        if ($mtime === false || $content === false) {
            throw new ImportException(sprintf('Unable to read ARMES import "%s".', $path));
        }

        $hash = hash('sha256', $content);
        if (isset($this->importCache[$path]) && $this->importCache[$path]['mtime'] === $mtime && $this->importCache[$path]['hash'] === $hash) {
            return $this->importCache[$path]['tree'];
        }

        $tree = $this->parser()->parseString($content, $path);
        $this->importCache[$path] = ['mtime' => $mtime, 'hash' => $hash, 'tree' => $tree];
        return $tree;
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return array<string, mixed>
     */
    protected function resolveProps(array $props, array $context, array $importStack): array
    {
        $resolved = [];
        foreach ($props as $key => $value) {
            if ($key === 'else' && is_array($value)) {
                $resolved[$key] = $value;
                continue;
            }
            $resolved[$key] = $this->resolvePropValue($value, $context, $importStack);
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     * @return array<string, mixed>
     */
    protected function resolveModifierProps(Node $node, array $context, array $importStack): array
    {
        $value = $node->value;
        if ($value === null && $node->props !== []) {
            $value = $node->props;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeResolutionException(sprintf('Runtime node ":%s" must resolve to an object/map.', $node->name));
        }
        return $this->resolveProps($value, $context, $importStack);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $importStack
     */
    protected function resolvePropValue(mixed $value, array $context, array $importStack): mixed
    {
        if ($value instanceof Node) {
            if ($value->kind === Node::VALUE) {
                return $value->value;
            }

            if ($value->kind === Node::RUNTIME && $value->name === 'expr') {
                return $this->expressionValue($value, $context);
            }

            if ($value->kind === Node::LOGIC) {
                return $this->logic()->evaluate($value, $context);
            }

            $resolved = $this->resolveToList($value, $context, null, $importStack);
            if (count($resolved) === 1 && $resolved[0]->kind === Node::VALUE) {
                return $resolved[0]->value;
            }

            throw new RuntimeResolutionException('Only value-producing runtime nodes may be used as prop values.');
        }

        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $child) {
                $resolved[$key] = $this->resolvePropValue($child, $context, $importStack);
            }
            return $resolved;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function expressionValue(Node $node, array $context): mixed
    {
        return $this->logic()->evaluate($node->value, $context);
    }

    /**
     * @return list<Node>
     */
    private function failOrSkip(string $message): array
    {
        if ($this->strict) {
            throw new RuntimeResolutionException($message);
        }

        $this->diagnostics[] = ['level' => 'warning', 'message' => $message];
        return [];
    }

    protected function inferType(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => get_debug_type($value),
        };
    }

    private function parser(): JsonTagParser
    {
        return $this->parser ?? new JsonTagParser();
    }

    protected function logic(): LogicEvaluator
    {
        return $this->logic ?? new LogicEvaluator();
    }
}
