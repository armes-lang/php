<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Armes\Emitter\HtmlEmitter;
use Armes\Mapper\HtmlMapper;
use Armes\Parser\Json\JsonTagParser;
use Armes\Runtime\RuntimeResolver;

$parser = new JsonTagParser();

$sample = json_encode([
    'main' => [
        '@' => ['class' => 'layout'],
        '#' => [
            ['h1' => 'Benchmark'],
            [
                ':if' => [
                    '@' => [
                        'test' => [
                            ':expr' => ['var' => 'show'],
                        ],
                    ],
                    '#' => [
                        ['p' => 'Visible'],
                    ],
                    ':else' => [
                        ['p' => 'Hidden'],
                    ],
                ],
            ],
            [
                ':each' => [
                    '@' => [
                        'items' => [
                            ':expr' => ['var' => 'items'],
                        ],
                        'as' => 'item',
                    ],
                    '#' => [
                        ['span' => [':expr' => ['var' => 'item']]],
                    ],
                ],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);

$context = ['show' => true, 'items' => range(1, 20)];
$iterations = 1000;

$results = [
    'environment' => [
        'php' => PHP_VERSION,
        'iterations' => $iterations,
        'date' => date(DATE_ATOM),
    ],
    'benchmarks' => [],
];

$results['benchmarks'][] = bench('json_decode only', $iterations, static function () use ($sample): void {
    json_decode($sample, false, 512, JSON_THROW_ON_ERROR);
});

$results['benchmarks'][] = bench('parse + normalize', $iterations, static function () use ($parser, $sample): void {
    $parser->parseString($sample);
});

$results['benchmarks'][] = bench('parse + normalize + resolve runtime', $iterations, static function () use ($parser, $sample, $context): void {
    $tree = $parser->parseString($sample);
    (new RuntimeResolver(true, $parser))->resolve($tree, $context);
});

$results['benchmarks'][] = bench('parse + normalize + resolve + map to HTML', $iterations, static function () use ($parser, $sample, $context): void {
    $tree = $parser->parseString($sample);
    $resolved = (new RuntimeResolver(true, $parser))->resolve($tree, $context);
    (new HtmlEmitter())->emit((new HtmlMapper())->map($resolved));
});

$importTree = $parser->parseFile(dirname(__DIR__) . '/fixtures/import/page.input.json');
$resolver = new RuntimeResolver(true, $parser);
$results['benchmarks'][] = bench('import resolve cold cache', 1, static function () use ($resolver, $importTree): void {
    $resolver->resolve($importTree);
});
$results['benchmarks'][] = bench('import resolve warm cache', 100, static function () use ($resolver, $importTree): void {
    $resolver->resolve($importTree);
});

echo json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/**
 * @return array<string, int|float|string>
 */
function bench(string $name, int $iterations, callable $callback): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $callback();
    }
    $elapsedNs = hrtime(true) - $start;

    return [
        'name' => $name,
        'iterations' => $iterations,
        'total_ms' => round($elapsedNs / 1_000_000, 3),
        'mean_us' => round($elapsedNs / $iterations / 1_000, 3),
        'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 3),
    ];
}
