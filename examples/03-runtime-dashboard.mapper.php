<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Armes\Armes;

$core = new Armes();
$tree = $core->parseJsonFile(example_path('03-runtime-dashboard.source.json'));
$context = json_decode((string) file_get_contents(example_path('03-runtime-dashboard.context.json')), true, flags: JSON_THROW_ON_ERROR);

example_print('Resolved HTML', $core->renderHtml($tree, $context));
