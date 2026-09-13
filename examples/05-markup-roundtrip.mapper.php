<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;
use Armes\Parser\Markup\MarkupParseOptions;

$core = new Armes();
$tree = $core->parseHtmlFile(example_path('05-markup-roundtrip.source.html'), new MarkupParseOptions(includeMeta: false));
$compactJson = $core->treeJson($tree, pretty: true, mode: JsonEmitter::MODE_COMPACT);

$compactJsonPath = example_write_output('05-markup-roundtrip.compact.json', $compactJson);

$roundtripHtml = $core->renderHtml($core->parseJson(
    $compactJson,
    $compactJsonPath ?? '05-markup-roundtrip.compact.json',
));

example_write_output('05-markup-roundtrip.html', $roundtripHtml);

example_print('Compact JSON', $compactJson);
example_print('Roundtrip HTML', $roundtripHtml);
