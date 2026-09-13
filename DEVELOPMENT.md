# Developer Guide

This guide is for people changing ARMES PHP itself. If you only want to use the library, start with `README.md`. If you want the portable language rules, read `SPEC.md`.

## Project Mental Model

ARMES is a compiler-style tree processor:

```text
source -> parser/normalizer -> ARMES Tree -> runtime resolver -> mapper -> emitter
```

Keep those stages separate:

- Parsers read a source syntax and normalize it into `Armes\Tree\Node`.
- The runtime resolver consumes first-class Logic nodes and Runtime nodes such as `:if`, `:each`, `:props`, and `:import`; Type domain commands normalize to Value nodes, and `:expr` is compatibility syntax.
- Mappers decide target meaning, such as HTML or React/JSX target nodes.
- Emitters serialize mapped nodes or tree data into strings.

Internal source commands must not render as literal output tags. Strict mode is the correctness target.

## Setup

```bash
composer install
```

Pkl examples and tests need the `pkl` CLI on `PATH`:

```bash
pkl --version
```

The current tested PHP line is PHP 8.4 through XAMPP, with Composer requiring PHP `>=8.2`.

## Common Commands

```bash
./vendor/bin/phpunit --configuration phpunit.xml
php benchmarks/core-benchmark.php
php benchmarks/markup-benchmark.php
find src examples tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --strict
```

Run the markup benchmark when changing `DomMarkupParser`, `HtmlEmitter`, `XmlEmitter`, raw text handling, void tags, comments, doctype handling, or JSON compact roundtrip behavior.

## Repository Map

```text
src/
  Armes.php        facade for parse, resolve, render helpers
  Tree/                   canonical ARMES Tree node model
  Parser/Native/          shared tag-key normalizer for decoded native data
  Parser/Json/            JSON decode + native normalizer
  Parser/Markup/          HTML/XML DOMDocument parser
  Parser/Yaml/            Symfony YAML decode + native normalizer
  Parser/Toml/            devium/toml decode + native normalizer
  Parser/Pkl/             pkl CLI JSON bridge + native normalizer
  Runtime/                runtime node resolver and expression evaluator
  Mapper/                 target model mapping
  Render/                 render target registry and target facade objects
  Emitter/                output serializers
  Exception/              project exception types

fixtures/                 portable inputs and expected outputs
tests/                    PHPUnit coverage
examples/                 runnable browser/CLI examples
benchmarks/               performance scripts and large HTML input
```

Docs:

- `README.md`: user overview and quick examples
- `SPEC.md`: portable ARMES language contract
- `ARCHITECTURE.md`: implementation architecture
- `DEVELOPMENT.md`: this contributor guide
- `PERFORMANCE.md`: benchmark method and latest results
- `REPORT.md`: historical rescue report and decisions
- `AGENTS.md`: instructions for coding agents

## ARMES Tree

The canonical tree lives in `Armes\Tree\Node`. Supported node kinds:

- `element`: named renderable/component node with props and children
- `runtime`: processing node with a runtime name, props, children, and optional value
- `value`: typed scalar/array/object/markup value
- `fragment`: ordered child list

Do not put render behavior on `Node`. Keep nodes plain, serializable, and boring.

## Parser Rules

`NativeTagParser` owns tag-key semantics for JSON, YAML, TOML, and Pkl:

- normal map keys become element names
- `:` keys become runtime nodes
- `@` means props
- `#` means children
- primitive values infer typed `value` nodes
- explicit typed nodes such as `:string` and `:int` override inference
- objects without `@` or `#` are shorthand child maps

When adding or changing tag-key behavior, update `NativeTagParser` first, then add fixtures and tests for every affected source format.

`JsonTagParser`, `YamlTagParser`, `TomlTagParser`, and `PklTagParser` should stay thin. They decode source into native data and delegate to `NativeTagParser`.

`DomMarkupParser` is separate because HTML/XML are tag-based markup, not native data formats. It converts DOM nodes directly into the same ARMES Tree model.

## Adding A Parser

Use this checklist:

1. Decode the source with a reliable native/library parser where possible.
2. Convert decoded maps/lists/scalars through `NativeTagParser`, unless the source is real markup.
3. Add `parseX` and `parseXFile` methods to `Armes`.
4. Add examples under `examples/`.
5. Add shared fixtures under `fixtures/` when the syntax is portable.
6. Add PHPUnit coverage for scalar roots, map roots, runtime nodes, props, children, and error cases.
7. Document limitations in `SPEC.md` and `README.md`.

Avoid custom parsers unless a native/library parser cannot preserve the semantics ARMES needs.

## Changing A Built-In Runtime Node

This section is for core contributors. It does not describe a public runtime plugin API; the built-in resolver handler set is closed to library consumers.

Runtime behavior lives in `RuntimeResolver`.

Checklist:

1. Decide whether the node resolves to a value, children, fragment, props, code payload, or nothing.
2. Add strict-mode behavior first.
3. Add loose-mode behavior only when it is safe and diagnosable.
4. Make sure the runtime node is consumed and cannot render literally.
5. Add tests for strict success, strict failure, and loose behavior if supported.
6. Update `SPEC.md`.

Never add raw PHP/JS eval to the core runtime. Code-like nodes such as `:php`, `:js`, `:ts`, and `:code` are inert payload concepts unless a future explicit plugin owns execution.

## Adding A Mapper Or Emitter

Mapper and emitter are different jobs:

- Mapper: decides target meaning.
- Emitter: serializes a target model or tree data.

For tag-like output, prefer:

```text
Node -> HtmlMapper/ReactMapper -> TargetNode -> Emitter
```

For storage/config output, use `JsonEmitter::toData()` to turn a resolved tree into `canonical`, `compact`, or `tagged` data, then serialize that data.

Strict mappers should reject unresolved runtime nodes. Loose mappers may drop them only when safe.

Register mapper/emitter pairs through `RenderTarget` when they should be available from `Armes::render()` or a convenience method. Keep target-specific behavior inside target-specific mappers:

- HTML tag replacement belongs in `HtmlMapper`.
- React component/import mapping belongs in `ReactMapper` and `JsxEmitter`.
- `Armes` should only resolve runtime nodes and dispatch to the configured target.

YAML, TOML, Pkl, and `treeJson()` currently serialize resolved tree data directly. Do not add fake mappers for them unless the target starts needing target-specific meaning.

## Fixtures And Tests

Fixtures should stay portable for future TypeScript/JavaScript implementations.

Use this pattern:

```text
fixtures/
  json/
  runtime/
  import/
  markup/
  formats/
```

When changing syntax, add or update:

- source fixture
- expected compact/canonical output where useful
- expected rendered output
- PHPUnit assertions in `tests/ArmesCoreTest.php`

Current broad coverage includes JSON syntax, runtime logic, imports, markup parsing, compact JSON reparse, XML/YAML/TOML/Pkl parsing, and renderer behavior.

## Examples

Examples are runnable from CLI and browser. They should print useful output even when `examples/output/` is not writable by the web server.

Use `example_write_output()` instead of `file_put_contents()` in example scripts. It skips saving when the output directory is read-only.

## Benchmarks

Benchmarks are simple by design:

- `core-benchmark.php`: JSON parse/normalize/resolve/map and import cache cost
- `markup-benchmark.php`: big HTML parse, compact JSON export, reparse, HTML emit, and structural roundtrip comparison

Do not make benchmarks assert tiny timing thresholds. They should reveal regressions without becoming noisy.

## Error Handling

Use project exceptions:

- `ParseException` for invalid source or parser failures
- `RuntimeResolutionException` for runtime failures
- `MappingException` for mapper/emitter failures
- `ImportException` for import-specific failures

Errors should include source paths or pointers when possible.

## Security Rules

- No unsafe code execution by default.
- Do not use raw `eval`.
- Pkl parsing is explicit and for trusted local modules.
- Pkl CLI calls must use argument arrays, not shell string concatenation.
- Unknown runtime nodes must never silently pass in strict mode.

## Before Opening A PR

Run:

```bash
find src examples tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --strict
./vendor/bin/phpunit --configuration phpunit.xml
php benchmarks/core-benchmark.php
```

Also run:

```bash
php benchmarks/markup-benchmark.php
```

when markup, HTML/XML output, compact JSON roundtrip, or DOM conversion changes.

Update docs when behavior changes. Update `PERFORMANCE.md` when benchmark results or benchmark method change.

## Common Pitfalls

- Do not duplicate tag-key logic in every parser. Use `NativeTagParser`.
- Do not let runtime nodes render as real tags.
- Do not assume HTML and XML emit rules are the same.
- Do not make TOML/Pkl scalar roots look roundtrippable when their document syntax is property-oriented.
- Do not make examples depend on writable web-server directories.
- Do not treat compact JSON as the full internal tree. Use canonical JSON for debugging internals.
