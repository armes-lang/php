<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;
use Armes\Parser\Markup\MarkupParseOptions;

$core = new Armes();
$tree = $core->parseHtmlFile(example_path('00-chaotic-markup-roundtrip.source.html'), new MarkupParseOptions(includeMeta: false));
$compactJson = $core->treeJson($tree, pretty: true, mode: JsonEmitter::MODE_COMPACT);

// file_put_contents(example_output_path('00-chaotic-markup-roundtrip.compact.json'), $compactJson);

$roundtripHtml = $core->renderHtml($core->parseJson(
    $compactJson,
    example_output_path('00-chaotic-markup-roundtrip.compact.json'),
));

// file_put_contents(example_output_path('00-chaotic-markup-roundtrip.html'), $roundtripHtml);

example_print('Compact JSON', $compactJson);
example_print('Roundtrip HTML', $roundtripHtml);
