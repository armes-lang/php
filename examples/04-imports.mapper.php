<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;

$core = new Armes();
$tree = $core->parseJsonFile(example_path('04-imports.source.json'));

example_print('HTML With Imports', $core->renderHtml($tree));
