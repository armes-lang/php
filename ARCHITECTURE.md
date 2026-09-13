# Architecture

## Pipeline

ARMES PHP v0 uses a compiler-style pipeline:

```text
source -> parser/normalizer -> ARMES Tree -> runtime resolver -> mapper -> emitter
```

Each stage has one job:

- parser/normalizer reads syntax and builds boring canonical nodes
- runtime resolver consumes canonical Runtime and Logic nodes using deterministic handlers
- mapper converts resolved nodes to target meaning
- emitter serializes mapped target nodes

## Package Layout

```text
src/
  Armes.php
  Tree/
  Parser/Json/
  Parser/Markup/
  Parser/Native/
  Parser/Yaml/
  Parser/Toml/
  Parser/Pkl/
  Runtime/
  Mapper/
  Render/
  Emitter/
  Exception/
```

The public PHP namespace is `Armes\...`, with the main facade at `Armes\Armes`.

## Tree Model

`Armes\Tree\Node` is the canonical tree value object. It supports `element`, `runtime`, `value`, `fragment`, and `logic` node creation plus array serialization for shared fixtures.

The tree is intentionally strict and plain. Render behavior does not live on the node model.

## Native Tag Parser

`Parser/Native/NativeTagParser` owns the tag-key normalization rules for decoded native data. JSON, YAML, TOML, and Pkl all delegate here after their source format is decoded.

Important parser decisions:

- Normal keys become elements.
- Internal source command keys start with `:`.
- Type domain commands become `value` nodes during normalization.
- Logic domain commands become `logic` nodes during normalization.
- `@` props are data maps, but explicit runtime values inside props are preserved as nodes.
- `#` is ordered child content.
- Objects without `@` or `#` become shorthand child maps.
- Source metadata is optional and lightweight.

`JsonTagParser` now only handles `json_decode(..., JSON_THROW_ON_ERROR)` plus file IO, then delegates to `NativeTagParser`. This keeps JSON as the portable reference syntax without duplicating the rules in every parser.

## Data Parsers

YAML uses `symfony/yaml` to decode scalars, lists, and maps. TOML uses `devium/toml`; because TOML documents are table-oriented, scalar document roots are invalid at parse time and scalar/list roots are invalid at emit time.

Pkl uses the installed `pkl` CLI:

```text
pkl eval --format=json --no-project --root-dir=<source-dir> --working-dir=<source-dir> <file>
```

The PHP bridge uses `proc_open` with an argument array, a timeout, and a restricted root directory. Pkl is treated as an explicit trusted-local parser/evaluator, not as a hidden runtime execution path.

## Markup Parser

`Parser/Markup/DomMarkupParser` is the v0 HTML/XML-style parser. It is a new implementation that uses DOMDocument/libxml as the parsing engine and normalizes DOM nodes into the same ARMES Tree model as JSON.

HTML parser decisions:

- DOMDocument is the fast default path.
- parser options control HTML/XML mode, fragment parsing, whitespace/comments/doctype preservation, metadata, and libxml flags.
- unsupported DOMDocument names are temporarily replaced with safe placeholders, then restored after DOM conversion.
- a UTF-8 hint is injected before parsing and ignored during DOM conversion.
- DOM comments, doctypes, CDATA, and raw text are represented as value node types.
- `script` and `style` children map to raw text so output does not escape executable/source payload text.
- HTML void elements are normalized as childless even when DOMDocument nests following nodes under them.
- text-only HTML source bypasses DOMDocument and becomes a single string value, preventing libxml's implicit `<p>` insertion.

The old draft markup parser is not a dependency. Its useful direction was native parser integration; its API and armesions were replaced.

## Runtime

`RuntimeResolver` consumes runtime nodes. It is handler-map oriented through explicit `match` branches for v0 core nodes.

Runtime behavior:

- first-class `logic` nodes from preferred `:logic:<op>` source delegate to `LogicEvaluator`; legacy `:expr` remains compatible.
- preferred `:type:<name>` source normalizes directly to canonical value nodes.
- `:if` resolves one branch.
- `:each` expands children with a loop context.
- `:props` and `:attributes` patch only the direct parent element.
- `:import` and `:include` parse and resolve another ARMES JSON file.
- code payload nodes are rejected in strict mode and dropped with a warning in loose mode.

`LogicEvaluator` implements a small JSON-Logic-inspired expression system without raw `eval`.

## Imports And Cache

Imports resolve relative to the current source file. The resolver caches imported parse trees by absolute path, mtime, and SHA-256 content hash.

Circular imports are detected with an import stack and reported as strict errors.

## Render Targets, Mappers, And Emitters

`RenderTarget` pairs a mapper with an emitter. `RenderTargetRegistry` stores target names such as `html`, `jsx`, and `xml`. `Armes::render()` resolves runtime nodes, fetches the target, creates a `MappingContext`, maps the tree, then emits the mapped result.

`renderHtml()`, `renderJsx()`, and `renderXml()` are convenience wrappers over registered targets. Built-in targets can be replaced with `withRenderTarget()`, and simple HTML/JSX mapping can be configured with `fromConfig()` or `withConfig()`.

`HtmlMapper` and `ReactMapper` produce target nodes. `HtmlEmitter`, `XmlEmitter`, and `JsxEmitter` serialize those target nodes. JSON/YAML/TOML/Pkl emitters serialize the resolved ARMES Tree through compact/tagged/canonical data modes.

HTML output:

- element nodes become tags
- custom `HtmlElementMapping` entries can replace tags, for example `input` -> `x-input`
- value nodes become escaped text
- `comment`, `doctype`, and raw values emit through dedicated target nodes
- false/null attributes are omitted
- true attributes become boolean attributes
- void tags emit without closing tags
- unresolved runtime nodes fail in strict mode

JSX output:

- default native JSX mapping works without configuration
- custom `ReactComponent` mappings can replace ARMES element names with local or imported components
- imports are collected into a `JsxDocument` and deduplicated before emission
- `class` maps to `className` when `className` is not already present
- scalar props use JSX-safe output
- text escapes JSX-sensitive characters
- unresolved runtime nodes fail in strict mode

XML output:

- element nodes always emit explicit closing tags
- text and attributes use XML escaping
- HTML void tag behavior is not applied

YAML/TOML/Pkl output:

- runtime resolution runs first through `Armes`
- compact mode is the default serialized data shape
- TOML and Pkl require object/map roots
- these tree serializers are not forced through fake mappers in v0

## Performance Strategy

The v0 implementation keeps the hot path straightforward:

- native JSON parsing
- native DOMDocument/libxml parsing for markup
- native YAML/TOML library decoders
- Pkl CLI JSON emission for Pkl modules
- single parser/normalizer pass
- explicit runtime dispatch
- import parse cache
- optional metadata
- no reflection or eval in the core

Large markup benchmarks disable metadata and compare structural fingerprints after reparsing the emitted HTML. This catches node/data loss without pretending serializer formatting must be byte-identical.

Future optimizations can add compiled mapper plans, persistent import caches, and source-span toggles.
