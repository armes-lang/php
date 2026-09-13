<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;
use Armes\Emitter\JsxEmitter;
use Armes\Mapper\ReactMapper;

$core = new Armes();
$tree = $core->parseJsonFile(example_path('02-react-jsx.source.json'));
$resolved = $core->resolve($tree);

$jsx = (new JsxEmitter())->emit((new ReactMapper())->map($resolved));

example_print('React/JSX-like Output', $jsx);
