<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;

$core = new Armes();
$tree = $core->parseYamlFile(example_path('08-yaml-runtime.source.yaml'));
$context = ['showDetails' => true];

$html = $core->renderHtml($tree, $context);
$yaml = $core->renderYaml($tree, $context);
$compactJson = $core->treeJson($core->resolve($tree, $context), pretty: true, mode: JsonEmitter::MODE_COMPACT);

example_write_output('08-yaml-runtime.html', $html);
example_write_output('08-yaml-runtime.yaml', $yaml);
example_write_output('08-yaml-runtime.compact.json', $compactJson);

example_print('HTML', $html);
example_print('YAML', $yaml);
example_print('Resolved Compact JSON', $compactJson);
