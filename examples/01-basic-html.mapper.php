<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;

$core = new Armes();
$tree = $core->parseJsonFile(example_path('01-basic-html.source.json'));

example_print('HTML', $core->renderHtml($tree));
example_print('Compact JSON', $core->treeJson($tree, pretty: true, mode: JsonEmitter::MODE_COMPACT));
example_print('Canonical Tree JSON', $core->treeJson($tree));
