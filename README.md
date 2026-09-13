# ARMES Core For PHP

This package is the PHP implementation of ARMES. It parses ARMES source into the shared canonical tree, resolves safe data-driven commands, maps the tree for a target, and emits source or rendered output.

Start with the shared documentation if ARMES is new to you:

- [Core Concepts](../../../docs/CORE-CONCEPTS.md): the parser, node, resolver, mapper, and emitter pipeline.
- [Source Commands](../../../docs/SOURCE-COMMANDS.md): every built-in command, preferred spelling, alias, result, and constraint.
- [Extending ARMES](../../../docs/EXTENDING.md): supported custom mappers, emitters, render targets, and external formats.
- [Feature Parity](../../../FEATURES.md): PHP and TypeScript support compared.

## Index

- [Requirements And Installation](#requirements-and-installation)
- [Quick Start](#quick-start)
- [Source, Tree, Resolution, And Rendering](#source-tree-resolution-and-rendering)
- [Parsing](#parsing)
- [Source Emission](#source-emission)
- [Rendering](#rendering)
- [Strict And Loose Modes](#strict-and-loose-modes)
- [Imports](#imports)
- [Custom Targets](#custom-targets)
- [Examples And Development](#examples-and-development)

## Requirements And Installation

- PHP 8.2 or later
- Composer
- PHP DOM/libxml for HTML, ARMES, and XML parsing
- the `pkl` CLI only when using Pkl parsing

From this package checkout:

```bash
composer install
```

The package namespace is `Armes\` and Composer package name is `armes-lang/core`.

## Quick Start

This is the same walkthrough used in the TypeScript guide.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$source = <<<'JSON'
{
  ":if": {
    "@": {
      "test": {
        ":logic:gte": [
          { ":logic:var": "order.total" },
          { ":type:int": 100 }
        ]
      }
    },
    "#": [{ "p": "Free shipping" }],
    ":else": [{ "p": "Shipping calculated at checkout" }]
  }
}
JSON;

$core = Armes::default();
$tree = $core->parseJson($source);

echo $core->renderHtml($tree, [
    'order' => ['total' => 125],
]);
```

Output:

```html
<p>Free shipping</p>
```

The parser creates canonical nodes. `renderHtml()` then resolves `:logic:gte`, `:logic:var`, and `:if`, maps the selected branch to HTML, and emits the string.

## Source, Tree, Resolution, And Rendering

Use the operation that matches the job:

```php
$tree = $core->parseJson($source);

// Editable ARMES source. Commands are preserved.
$jsonSource = $core->sourceJson($tree);
$armesSource = $core->sourceArmes($tree);

// Canonical kind/name/type/op tree for inspection or interchange.
$canonical = $core->treeJson($tree);

// Evaluated canonical tree, before target mapping.
$resolved = $core->resolve($tree, ['order' => ['total' => 125]]);

// Final resolve -> map -> emit path.
$html = $core->renderHtml($tree, ['order' => ['total' => 125]]);
```

`sourceJson()` and `sourceArmes()` do not evaluate conditions or loops. `treeJson()` does not convert back to tag-key source. See [Source Emission And Rendering](../../../docs/CORE-CONCEPTS.md#source-emission-and-rendering) for the conceptual difference.

## Parsing

`Armes` exposes these parser methods:

| Format | String method | File method | Notes |
| --- | --- | --- | --- |
| JSON tag-key | `parseJson()` | `parseJsonFile()` | reference native-data syntax |
| ARMES | `parseArmes()` | `parseArmesFile()` | ARMES command tags such as `<:if>` and `<:logic:eq>` |
| HTML | `parseHtml()` | `parseHtmlFile()` | DOMDocument-backed markup parsing |
| XML | `parseXml()` | `parseXmlFile()` | XML parsing with ARMES-aware command preprocessing |
| YAML | `parseYaml()` | `parseYamlFile()` | normalizes through the native tag-key parser |
| TOML | `parseToml()` | `parseTomlFile()` | object/table-oriented source |
| Pkl | `parsePkl()` | `parsePklFile()` | PHP-only; trusted local modules through the `pkl` CLI |

Normal keys become Elements. Primitive values become inferred Values. Internal commands begin with `:`. Use `:type:*` and `:logic:*` for new typed and logic source:

```json
{
  ":logic:eq": [
    { ":logic:var": "user.role" },
    { ":type:string": "admin" }
  ]
}
```

The complete grammar is maintained in the [Source Commands](../../../docs/SOURCE-COMMANDS.md), not duplicated here.

### Markup Options

`MarkupParseOptions` controls mode-specific behavior such as fragments, whitespace, comments, doctypes, source metadata, strictness, runtime tags, nonstandard names, boolean attributes, and libxml flags.

```php
use Armes\Parser\Markup\MarkupParseOptions;

$tree = $core->parseHtml(
    '<section><h1>Hello</h1></section>',
    options: new MarkupParseOptions(includeMeta: false),
);
```

Text-only markup remains text and does not gain an implicit paragraph.

## Source Emission

Readable domain-qualified commands are the default:

```php
$logic = $core->parseJson('{":==":[true,1]}');

echo $core->sourceJson($logic, pretty: false);
// {":logic:eq":[true,1]}

echo $core->sourceArmes($logic, pretty: false);
// <:logic:eq><:type:bool>true</:type:bool><:type:int>1</:type:int></:logic:eq>
```

Symbol operator output is optional:

```php
echo $core->sourceJson($logic, pretty: false, operatorStyle: 'symbol');
// {":==":[true,1]}
```

Primitive JSON shorthand remains the default. Request explicit `:type:*` wrappers when source must carry declared types:

```php
echo $core->sourceJson($logic, explicitTypedValues: true);
```

Canonical tree output is independent of source spelling:

```php
echo $core->treeJson($logic, pretty: true);
```

It contains `kind: "logic"`, canonical `op: "eq"`, and `args`.

## Rendering

Built-in output operations include:

| Method | Output |
| --- | --- |
| `renderHtml()` | resolved HTML |
| `renderXml()` | resolved XML with explicit closing tags |
| `renderJsx()` | resolved JSX-like source through PHP's React mapper |
| `renderYaml()` | resolved tree data as YAML |
| `renderToml()` | resolved tree data as TOML |
| `renderPkl()` | resolved tree data as Pkl |
| `render($target, ...)` | any registered Render Target |

TOML and Pkl output require object/map roots because their document models are property-oriented. YAML supports scalar, list, and map roots.

## Strict And Loose Modes

Strict mode is the default.

```php
$diagnostics = [];
$tree = $core->parseJson(
    '{":logic:xor":[true,false]}',
    strict: false,
    diagnostics: $diagnostics,
);

$resolved = $core->resolve($tree, strict: false);
```

- Strict parsing rejects unknown explicit `:logic:*` and `:type:*` commands.
- Loose parsing records diagnostics and preserves a safe unresolved Runtime form.
- Strict resolution rejects unknown Runtime commands, misplaced contextual commands, and inert directives.
- Loose resolution records warnings and drops nodes it cannot safely resolve.

Loose mode supports editing and diagnostics. It does not execute or register unknown commands.

## Imports

`:import` and `:include` resolve relative to the importing source path, then cache parsed files by path, modification time, and content hash.

```json
{
  ":import": {
    "@": {
      "src": "./components/Card.armes.json",
      "props": { "title": "Welcome" }
    },
    "#": [{ "p": "Slot content" }]
  }
}
```

Import props extend the imported resolution context. Slot children append to the imported root Element or Fragment. Missing and circular imports are errors in strict mode.

The PHP core currently owns filesystem imports directly; there is no public PHP import-loader interface.

## Custom Targets

Target customization is intentionally separate from source commands. A custom HTML mapping changes output without changing the canonical tree:

```php
<?php

use Armes\Armes;
use Armes\Emitter\HtmlEmitter;
use Armes\Mapper\HtmlElementMapping;
use Armes\Mapper\HtmlMapper;
use Armes\Render\RenderTarget;

$core = Armes::default()->withRenderTarget(
    'html',
    RenderTarget::make(
        HtmlMapper::make()->element('input', HtmlElementMapping::tag('x-input')),
        new HtmlEmitter(),
    ),
);
```

For complete custom mapper/emitter examples and closed extension boundaries, use [Extending ARMES](../../../docs/EXTENDING.md).

## Examples And Development

```bash
composer test
php benchmarks/core-benchmark.php
php benchmarks/markup-benchmark.php
```

Runnable examples are in [`examples/`](./examples/), including logic, imports, ARMES, XML, YAML, TOML, Pkl, mappings, and large HTML round trips.

Additional PHP references:

- [SPEC.md](./SPEC.md): PHP-specific formal behavior and implementation notes.
- [ARCHITECTURE.md](./ARCHITECTURE.md): PHP package structure and ownership.
- [DEVELOPMENT.md](./DEVELOPMENT.md): change and verification workflow.
- [PERFORMANCE.md](./PERFORMANCE.md): benchmark method and recorded results.
- [REPORT.md](./REPORT.md): historical rescue and design record.

The root [README](../../../README.md), [Core Concepts](../../../docs/CORE-CONCEPTS.md), and [Source Commands](../../../docs/SOURCE-COMMANDS.md) are the canonical shared documentation.
