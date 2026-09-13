<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsonEmitter;

$core = new Armes();
$tree = $core->parseTomlFile(example_path('09-toml-storage.source.toml'));

$html = $core->renderHtml($tree);
$toml = $core->renderToml($tree);
$compactJson = $core->treeJson($tree, pretty: true, mode: JsonEmitter::MODE_COMPACT);

example_write_output('09-toml-storage.html', $html);
example_write_output('09-toml-storage.toml', $toml);
example_write_output('09-toml-storage.compact.json', $compactJson);

example_print('HTML', $html);
example_print('TOML', $toml);
example_print('Compact JSON', $compactJson);
