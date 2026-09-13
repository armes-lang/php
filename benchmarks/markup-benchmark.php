<?php

declare(strict_types=1);

use Armes\Emitter\HtmlEmitter;
use Armes\Emitter\JsonEmitter;
use Armes\Mapper\HtmlMapper;
use Armes\Parser\Json\JsonTagParser;
use Armes\Parser\Markup\DomMarkupParser;
use Armes\Parser\Markup\MarkupParseOptions;
use Armes\Tree\Node;

require __DIR__ . '/../vendor/autoload.php';

$inputPath = __DIR__ . '/big-html.html';
$outputDir = __DIR__ . '/output';
$htmlOutputPath = $outputDir . '/big-html.roundtrip.html';
$jsonOutputPath = $outputDir . '/big-html.compact.json';
$reportOutputPath = $outputDir . '/big-html.report.json';

if (!is_file($inputPath)) {
    fwrite(STDERR, "Missing benchmark input: {$inputPath}\n");
    exit(1);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create benchmark output directory: {$outputDir}\n");
    exit(1);
}

$source = file_get_contents($inputPath);
if ($source === false) {
    fwrite(STDERR, "Unable to read benchmark input: {$inputPath}\n");
    exit(1);
}

$options = new MarkupParseOptions(includeMeta: false);
$markupParser = new DomMarkupParser();
$jsonParser = new JsonTagParser();
$jsonEmitter = new JsonEmitter();
$htmlMapper = new HtmlMapper();
$htmlEmitter = new HtmlEmitter();

$timings = [];

measure('dom_parse_only_ms', $timings, static fn (): DOMDocument => domParseOnly($source, $options));

$tree = measure(
    'parse_normalize_ms',
    $timings,
    static fn (): Node => $markupParser->parseHtmlString($source, $inputPath, $options),
);

$compactJson = measure(
    'json_export_compact_ms',
    $timings,
    static fn (): string => $jsonEmitter->emitCompactTree($tree),
);
file_put_contents($jsonOutputPath, $compactJson);

$jsonTree = measure(
    'json_reparse_ms',
    $timings,
    static fn (): Node => $jsonParser->parseString($compactJson, $jsonOutputPath),
);

$roundtripHtml = measure(
    'html_emit_ms',
    $timings,
    static fn (): string => $htmlEmitter->emit($htmlMapper->map($jsonTree)),
);

file_put_contents($htmlOutputPath, $roundtripHtml);

$outputTree = measure(
    'roundtrip_reparse_ms',
    $timings,
    static fn (): Node => $markupParser->parseHtmlString($roundtripHtml, $htmlOutputPath, $options),
);

$inputFingerprint = fingerprint($tree);
$outputFingerprint = fingerprint($outputTree);
$fingerprintsMatch = $inputFingerprint === $outputFingerprint;

$report = [
    'input' => $inputPath,
    'outputs' => [
        'html' => $htmlOutputPath,
        'compact_json' => $jsonOutputPath,
        'report' => $reportOutputPath,
    ],
    'sizes_bytes' => [
        'source_html' => strlen($source),
        'compact_json' => strlen($compactJson),
        'roundtrip_html' => strlen($roundtripHtml),
    ],
    'timings_ms' => $timings,
    'structural_match' => $fingerprintsMatch,
    'memory_peak_bytes' => memory_get_peak_usage(true),
];

file_put_contents(
    $reportOutputPath,
    json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
);

printf("Markup benchmark: %s\n", basename($inputPath));
foreach ($timings as $label => $milliseconds) {
    printf("- %-24s %10.3f ms\n", $label . ':', $milliseconds);
}
printf("- %-24s %10s\n", 'structural_match:', $fingerprintsMatch ? 'yes' : 'no');
printf("- %-24s %10s\n", 'source_html:', number_format(strlen($source)) . ' B');
printf("- %-24s %10s\n", 'compact_json:', number_format(strlen($compactJson)) . ' B');
printf("- %-24s %10s\n", 'roundtrip_html:', number_format(strlen($roundtripHtml)) . ' B');
printf("Saved HTML: %s\n", $htmlOutputPath);
printf("Saved JSON: %s\n", $jsonOutputPath);
printf("Saved report: %s\n", $reportOutputPath);

if (!$fingerprintsMatch) {
    fwrite(STDERR, "Structural fingerprint mismatch between input and output.\n");
    exit(1);
}

/**
 * @template T
 * @param callable(): T $callback
 * @param array<string, float> $timings
 * @return T
 */
function measure(string $label, array &$timings, callable $callback): mixed
{
    $start = hrtime(true);
    $result = $callback();
    $timings[$label] = (hrtime(true) - $start) / 1_000_000;
    return $result;
}

function domParseOnly(string $source, MarkupParseOptions $options): DOMDocument
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $source, $options->htmlLibxmlOptions());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $document;
}

function fingerprint(Node $node): mixed
{
    return match ($node->kind) {
        Node::FRAGMENT => [
            'kind' => Node::FRAGMENT,
            'children' => array_map(static fn (Node $child): mixed => fingerprint($child), $node->children),
        ],
        Node::ELEMENT => [
            'kind' => Node::ELEMENT,
            'name' => strtolower((string) $node->name),
            'props' => normalizeMap($node->props),
            'children' => array_map(static fn (Node $child): mixed => fingerprint($child), $node->children),
        ],
        Node::RUNTIME => [
            'kind' => Node::RUNTIME,
            'name' => $node->name,
            'props' => normalizeMap($node->props),
            'children' => array_map(static fn (Node $child): mixed => fingerprint($child), $node->children),
            'value' => normalizeValue($node->value),
        ],
        Node::VALUE => [
            'kind' => Node::VALUE,
            'type' => $node->type,
            'value' => normalizeValue($node->value),
        ],
        default => null,
    };
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function normalizeMap(array $value): array
{
    ksort($value);
    foreach ($value as $key => $child) {
        $value[$key] = normalizeValue($child);
    }
    return $value;
}

function normalizeValue(mixed $value): mixed
{
    if ($value instanceof Node) {
        return fingerprint($value);
    }

    if (is_array($value)) {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = normalizeValue($child);
        }
    }

    return $value;
}
