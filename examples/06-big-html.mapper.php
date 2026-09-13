<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;
use Armes\Parser\Markup\MarkupParseOptions;
use Armes\Tree\Node;

$sourcePath = example_path('big-html.html');
$compactJsonPath = example_output_path('06-big-html.compact.json');
$roundtripHtmlPath = example_output_path('06-big-html.roundtrip.html');
$reportPath = example_output_path('06-big-html.report.json');

$core = new Armes();
$timings = [];

$tree = measure_example('parse_html_ms', $timings, static fn (): Node => $core->parseHtmlFile(
    $sourcePath,
    new MarkupParseOptions(includeMeta: false),
));

$compactJson = measure_example('compact_json_ms', $timings, static fn (): string => $core->treeJson(
    $tree,
    pretty: false,
    mode: JsonEmitter::MODE_COMPACT,
));
example_write_output('06-big-html.compact.json', $compactJson);

$jsonTree = measure_example('json_reparse_ms', $timings, static fn (): Node => $core->parseJson($compactJson, $compactJsonPath));
$roundtripHtml = measure_example('html_emit_ms', $timings, static fn (): string => $core->renderHtml($jsonTree));
$roundtripTree = measure_example('roundtrip_reparse_ms', $timings, static fn (): Node => $core->parseHtml(
    $roundtripHtml,
    $roundtripHtmlPath,
    new MarkupParseOptions(includeMeta: false),
));

example_write_output('06-big-html.roundtrip.html', $roundtripHtml);

$sourceSize = filesize($sourcePath);
$sourceSize = $sourceSize === false ? 0 : $sourceSize;
$structuralMatch = fingerprint_example($tree) === fingerprint_example($roundtripTree);

$report = [
    'source' => $sourcePath,
    'outputs' => [
        'compact_json' => $compactJsonPath,
        'roundtrip_html' => $roundtripHtmlPath,
    ],
    'sizes_bytes' => [
        'source_html' => $sourceSize,
        'compact_json' => strlen($compactJson),
        'roundtrip_html' => strlen($roundtripHtml),
    ],
    'timings_ms' => $timings,
    'structural_match' => $structuralMatch,
    'memory_peak_bytes' => memory_get_peak_usage(true),
];

example_write_output('06-big-html.report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

example_print('Big HTML Report', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if (!$structuralMatch) {
    fwrite(STDERR, 'Big HTML structural roundtrip failed.' . PHP_EOL);
    exit(1);
}

/**
 * @template T
 * @param callable(): T $callback
 * @param array<string, float> $timings
 * @return T
 */
function measure_example(string $label, array &$timings, callable $callback): mixed
{
    $start = hrtime(true);
    $result = $callback();
    $timings[$label] = (hrtime(true) - $start) / 1_000_000;
    return $result;
}

function fingerprint_example(Node $node): mixed
{
    return match ($node->kind) {
        Node::FRAGMENT => [
            'kind' => Node::FRAGMENT,
            'children' => array_map(static fn (Node $child): mixed => fingerprint_example($child), $node->children),
        ],
        Node::ELEMENT => [
            'kind' => Node::ELEMENT,
            'name' => strtolower((string) $node->name),
            'props' => normalize_map_example($node->props),
            'children' => array_map(static fn (Node $child): mixed => fingerprint_example($child), $node->children),
        ],
        Node::RUNTIME => [
            'kind' => Node::RUNTIME,
            'name' => $node->name,
            'props' => normalize_map_example($node->props),
            'children' => array_map(static fn (Node $child): mixed => fingerprint_example($child), $node->children),
            'value' => normalize_value_example($node->value),
        ],
        Node::VALUE => [
            'kind' => Node::VALUE,
            'type' => $node->type,
            'value' => normalize_value_example($node->value),
        ],
        default => null,
    };
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function normalize_map_example(array $value): array
{
    ksort($value);
    foreach ($value as $key => $child) {
        $value[$key] = normalize_value_example($child);
    }
    return $value;
}

function normalize_value_example(mixed $value): mixed
{
    if ($value instanceof Node) {
        return fingerprint_example($value);
    }

    if (is_array($value)) {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = normalize_value_example($child);
        }
    }

    return $value;
}
