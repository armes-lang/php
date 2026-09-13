<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Armes\Armes;

$core = new Armes();
$tree = $core->parseJson('{
  "div": {
    "@": {
      "class": "card"
    },
    "#": [
      { "h1": "Hello ARMES" },
      { "p": "JSON tag-key syntax rendered as HTML." }
    ]
  }
}');

echo $core->renderHtml($tree) . PHP_EOL;
