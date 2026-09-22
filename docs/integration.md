# API

[Language](README.md) · [Next: Compatibility](troubleshooting.md)

## On this page

- [Install](#install)
- [First render](#first-render)
- [Application data](#application-data)
- [Methods](#methods)
- [Imports](#imports)
- [Reference sessions](#reference-sessions)
- [Result adapters](#result-adapters)
- [Custom mapping](#custom-mapping)
- [Options](#options)
- [Editors](#editors)

## Install

The package requires PHP 8.2 or later and Composer. DOM/libxml is needed for markup. Pkl parsing additionally requires the `pkl` CLI.

From this package checkout:

```bash
composer install
```

The Composer package is `armes-lang/core`; the namespace is `Armes\`. Configure your application's Composer repository as appropriate for where you obtain the package. Examples below assume its autoloader is available as `vendor/autoload.php` relative to the script.

## First render

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$core = Armes::default();
$tree = $core->parseJson('{"button":{"@":{"class":"primary"},"#":["Save"]}}');
echo $core->renderHtml($tree);
// <button class="primary">Save</button>
```

PHP's `parseJson()` expects a JSON string. Encode application data with `json_encode(..., JSON_THROW_ON_ERROR)` when using this facade. PHP arrays can represent both lists and maps; use JSON objects deliberately for object boundaries, especially empty ones.

Resolution and rendering are synchronous. PHP does not adopt TypeScript's Promise scheduler or loader interface.

## Application data

Keep the button’s structure and get its label from application data. `:logic:var` reads the supplied context, with `Save` as a fallback:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$core = Armes::default();
$tree = $core->parseJson(<<<'JSON'
{"button":{"@":{"class":"primary"},"#":[{":logic:var":["labels.save","Save"]}]}}
JSON);
echo $core->renderHtml($tree, ['labels' => ['save' => 'Save changes']]);
// <button class="primary">Save changes</button>
```

To use server-stored structure, your application loads the source, parses it, and asks its target integration to present the result. The application owns fetching and refresh timing; see [server updates](architecture.md#server-updates).

## Methods

| Task | Facade methods |
| --- | --- |
| Create/configure core | `Armes::default()`, `Armes::fromConfig()`, `withConfig()`, `withRenderTarget()` |
| Parse structured formats | `parseJson()`, `parseYaml()`, `parseToml()`, `parsePkl()` |
| Parse markup | `parseArmes()`, `parseHtml()`, `parseXml()` |
| Read files | The corresponding `parse*File()` method for each format above |
| Resolve commands | `resolve()` |
| Render built-in targets | `renderHtml()`, `renderXml()`, `renderJsx()` |
| Render a registered target | `render($target, $tree, $context, $strict)` |
| Serialize evaluated tree data | `renderYaml()`, `renderToml()`, `renderPkl()` |
| Preserve editable source | `sourceJson()`, `sourceArmes()` |
| Inspect tree data | `treeJson()` |
| Enable references | `referenceSession()` |

`renderJsx()` emits JSX-like source. PHP has no core `map()` facade matching TypeScript's; use mapper objects directly or a session's `map()` method. There is no `resolveSync()` suffix because PHP resolution is already synchronous.

Canonical constructors are `Armes\Tree\Node::element`, `::value`, `::fragment`, `::runtime`, and `::logic`. The readonly node model also provides immutable replacement helpers. Constructing a Runtime node does not register a command handler.

## Imports

Load an entry document from a file so relative imports have source provenance:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$core = Armes::default();
$tree = $core->parseJsonFile(__DIR__ . '/Page.armes.json');
echo $core->renderHtml($tree);
```

`Page.armes.json` can contain `{":import":"./Card.armes.json"}`. See the [runtime chapter](runtime.md#import).

For source text loaded elsewhere, pass its source path as the second `parseJson()` argument. The filesystem importer resolves JSON files, checks import cycles, and caches parsed content by path/mtime/content hash. It is not a generic PHP loader extension point.

Your application controls which entry documents and filesystem paths it permits. Import arguments extend context; appended slot children retain the existing parent-context behavior.

## Reference sessions

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$core = Armes::default();
$tree = $core->parseJson(<<<'JSON'
{
  "section": {
    "#": [
      {"data": {"@": {"value": 3}}},
      {"p": {"@": {"title": {":ref": "$/child/section/child/data/props/value"}}, "#": "Count"}}
    ]
  }
}
JSON);
$session = $core->referenceSession($tree);
$snapshot = $session->resolve();
if ($snapshot['status'] !== 'ready') {
    throw new RuntimeException(implode("\n", array_column($snapshot['diagnostics'], 'message')));
}
$html = $core->renderHtml($session->materialize($snapshot));
$source = $session->source('json');
// $html has title="3"; $source keeps :ref without runtime IDs.
```

| Session operation | Purpose |
| --- | --- |
| `compile()` | Compile eligible authored references without running providers/imports |
| `resolve()` | Produce/cache a settled snapshot array |
| `snapshot()` / `authoredRevision()` | Read published state / authored revision |
| `subscribe($listener)` | Listen for settled snapshots; returns an unsubscribe closure |
| `nodeId($node)` / `addressOf($nodeOrId)` | Identity / typed structural address |
| `bind($targetAddress, $sourceAddress)` | Replace a supported destination plan with `:ref` |
| `unbind($targetAddress, $replacement)` | Replace a binding with an explicit value |
| `transaction($tree, $correspondence, $context)` | Submit a coherent edit |
| `duplicate($address)` / `fork()` | Copy with reference handling / independent editing branch |
| `sourceTree()` / `source($format)` | Normalized symbolic source |
| `materialize($snapshot)` | Concrete tree; reject error snapshots |
| `map($mapper, $target)` | Materialize and map through the session |

Correspondence is a list of `[$oldNode, $replacementNode]` pairs. Retained node objects keep their runtime identities. Copies get new identities. Pass context when it changes rather than mutating an old published snapshot.

Options include `context`, `resultAdapters`, `isExpressionLocation`, and `canSelect`. Nested expression recognition and source-read restrictions are separate contracts. These hooks do not enable binding into metadata or supply a full authorization system. PHP does not support TypeScript destination-scope configuration.

## Result adapters

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;
use Armes\Runtime\References\ResultAdapterRegistry;

$adapters = ResultAdapterRegistry::empty()->with(
    'element',
    'Double',
    static fn (array $input): array => [
        'kind' => 'value',
        'value' => (float) $input['props']['input'] * 2,
    ],
);
$core = Armes::default();
$tree = $core->parseJson('[{"Double":{"@":{"input":7}}},{"p":{":ref":"$/child/Double/result"}}]');
$session = $core->referenceSession($tree, ['resultAdapters' => $adapters]);
$snapshot = $session->resolve();
// The p child is 14. Double remains an ordinary authored element.
```

The callback receives `node`, resolved `props`, instance `context`, a tracked `read` callback, and `revision`. Unlike TypeScript's tagged read result, PHP's `read` returns a material value or `StructuralView` directly. Inspect a structural handle before requiring concrete structure.

Return an associative array shaped as `['kind' => 'value', 'value' => ...]` or `['kind' => 'structure', 'tree' => $node]`. Optional unique string `keys` identify every array item or direct Fragment child in that result.

Element adapters run when `/result` is demanded. Runtime adapters supply the registered runtime's resolved output. The registry is immutable: keep the returned registry from `with()`.

PHP providers are synchronous. They should be pure within a revision and must not retain read callbacks. Computation failures publish an error snapshot; no partial output tree is provided. Provider registration is trusted host code, not code loaded from an authored document.

## Custom mapping

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;
use Armes\Emitter\HtmlEmitter;
use Armes\Mapper\HtmlElementMapping;
use Armes\Mapper\HtmlMapper;
use Armes\Render\RenderTarget;

$core = Armes::default()->withRenderTarget(
    'html',
    RenderTarget::make(
        HtmlMapper::make()->element('Notice', HtmlElementMapping::tag('section')),
        new HtmlEmitter(),
    ),
);
echo $core->renderHtml($core->parseJson('{"Notice":"Saved"}'));
// <section>Saved</section>
```

For another representation, implement `MapperInterface::map(Node $node, ?MappingContext $context = null): mixed` and pair the mapper with an emitter as needed. Resolve first for ordinary content; deliberately retain raw nodes only for inspection tools that understand them.

`withRenderTarget()` returns a configured core instance. It does not mutate an earlier core. Target mapping does not install a new parser command or take over dependency scheduling.

## Options

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Armes\Armes;

$core = Armes::default();
$diagnostics = [];
$tree = $core->parseJson(
    '{":logic:xor":[true,false]}',
    strict: false,
    diagnostics: $diagnostics,
);
// Preserved for editing with a parse diagnostic; no xor evaluator is installed.
```

Native parse diagnostics use level/message entries. Runtime resolver diagnostics are available when using the resolver object; reference-session diagnostics are in the settled snapshot. Keep exceptions visible when strict rendering fails.

`sourceJson()` and `sourceArmes()` accept named arguments `pretty`, `operatorStyle`, and `explicitTypedValues`. `treeJson()` supports canonical, compact, and tagged modes. Markup parsing has a `MarkupParseOptions` object rather than TypeScript's options-record signature.

Opaque object/array Value wrappers are retained regardless of the scalar typed-output preference. Consult [Formats](formats.md) before relying on expression-rich markup round trips.

## Editors

Keep authored trees and settled output separate. Batch a change into `transaction()` and then resolve. Supply correspondence for immutable replacements rather than assigning identity from a child index. Use session forks when keeping independent history branches.

Save through `source()` after identity-preserving edits. A broken pin should result in a diagnosable save failure, not a silently retargeted dependency. Layout, selection, resource identity, and persistence policy remain application concerns.
