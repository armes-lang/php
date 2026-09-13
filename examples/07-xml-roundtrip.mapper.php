<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;
use Armes\Parser\Markup\MarkupParseOptions;

$core = new Armes();
$tree = $core->parseXmlFile(
    example_path('07-xml-roundtrip.source.xml'),
    new MarkupParseOptions(mode: MarkupParseOptions::MODE_XML, includeMeta: false),
);

$compactJson = $core->treeJson($tree, pretty: true, mode: JsonEmitter::MODE_COMPACT);
$xml = $core->renderXml($tree);

example_write_output('07-xml-roundtrip.compact.json', $compactJson);
example_write_output('07-xml-roundtrip.xml', $xml);

example_print('Compact JSON', $compactJson);
example_print('XML', $xml);
