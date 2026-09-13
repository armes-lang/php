<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;

$core = new Armes();
$tree = $core->parsePklFile(example_path('10-pkl-runtime.source.pkl'));
$context = ['items' => ['fast', 'typed', 'portable']];

$html = $core->renderHtml($tree, $context);
$pkl = $core->renderPkl($core->resolve($tree, $context));
$compactJson = $core->treeJson($core->resolve($tree, $context), pretty: true, mode: JsonEmitter::MODE_COMPACT);

example_write_output('10-pkl-runtime.html', $html);
example_write_output('10-pkl-runtime.pkl', $pkl);
example_write_output('10-pkl-runtime.compact.json', $compactJson);

example_print('HTML', $html);
example_print('Pkl', $pkl);
example_print('Resolved Compact JSON', $compactJson);
