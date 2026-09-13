# Engineering Report

## Repository Findings

The old repository was a sequence of drafts rather than a maintainable package:

- 2023 history started as `Compiler`/`Processor` ARMES experiments that mapped XML-like markup into PHP method/class access and HTML output.
- Later history pivoted into a large mutable mutable tree/reference/value model with DOM/markup experiments.
- The most recent commit bolted on JSON Logic classes, including duplicate `JsonLogic copy` folders.
- No PHPUnit/Pest config existed.
- `tests/` contained manual scripts, fixtures, timing probes, and old experiments rather than assertions.
- `README.md` described broad features that were only partly implemented.
- `composer.lock` was stale against `composer.json`.
- Existing code parsed under PHP 8.4 but emitted nullable-type deprecations.
- Markup tests failed at runtime because constructors and call sites no longer matched.
- The bundled XAMPP `phpunit` binary was too old for PHP 8.4 and failed on removed PHP functions.

## Salvaged Intent

The useful ideas were kept:

- source syntax should map to a tree
- attributes/props and children should be distinct
- runtime/control nodes should not render literally
- text/comment/typed data need explicit representation
- compiler-style phases are the right shape
- JSON Logic is the right inspiration for safe expressions
- import/file splitting belongs in the core architecture
- native DOM parsing is the right direction for markup performance

## Replaced Code

The old tracked `src/` implementation and manual `tests/` scripts were replaced with a clean v0 core. The old code remains available in git history.

Major replacements:

- legacy mutable tree model -> current `Armes\Tree\Node`
- reflection-heavy parser/resolver/factory draft -> explicit parser/runtime/mapper contracts
- unsafe execution-oriented compiler intent -> safe data-based runtime
- manual probe scripts -> PHPUnit suite
- broad README claims -> spec/architecture/report/performance docs
- old dependency set -> PHP >=8.2 plus PHPUnit dev dependency

## Design Decisions

- The public PHP namespace is `Armes`, with the main facade at `Armes\Armes`.
- JSON is the reference v0 authoring syntax, with HTML/XML markup parsers and YAML/TOML/Pkl data parsers now normalizing to the same tree model.
- HTML markup parsing now uses a fresh DOMDocument adapter, not the old draft `MarkupParser`.
- JSON, YAML, TOML, and Pkl now share one native tag-key normalizer so future implementations can target the same fixtures and behavior.
- Text-only HTML markup bypasses DOMDocument so libxml cannot inject an implicit `<p>`.
- Explicit typed nodes normalize directly to value nodes.
- `:props` and `:attributes` are resolved as parent modifiers, not rendered children.
- Strict mode is the default.
- Loose mode only drops runtime nodes when doing so is safe.
- `:php`, `:js`, `:ts`, and `:code` are recognized but rejected by strict default runtime.
- Import slot children are appended to the imported root element or fragment for v0.
- React/JSX mapping converts `class` to `className` only when `className` is absent.
- The core logic evaluator is implemented locally to avoid a dependency and to keep the initial operator set small and auditable.
- Compact JSON is the storage-oriented export format. Canonical JSON remains the full internal tree format.
- Markup roundtrip validation is structural rather than byte-for-byte because DOMDocument and emitters normalize formatting and void tags.
- `Armes` is now a configurable facade over render targets; React component mapping and HTML tag mapping are target-specific mapper behavior, not special facade logic.

## Implementation Summary

Implemented:

- canonical node model
- JSON tag-key parser
- type inference and explicit typed nodes
- props and children syntax
- shorthand child maps and arrays
- runtime resolver
- expression evaluator
- `:if`, `:else`, `:each`
- `:props`, `:attributes`
- `:import`, `:include`
- import cache and circular import detection
- DOMDocument-backed HTML markup parser
- DOMDocument-backed XML parser entrypoints and XML emitter
- shared native-data normalizer used by JSON, YAML, TOML, and Pkl
- YAML parser and emitter through `symfony/yaml`
- TOML parser and emitter through `devium/toml`
- Pkl parser through `pkl eval --format=json` and a simple Pkl module emitter
- text-only HTML parsing as string content
- compact/tagged/canonical JSON export modes
- comment, doctype, cdata, and raw text value handling
- raw `script`/`style` HTML emission
- large HTML roundtrip benchmark with structural fingerprint comparison
- configurable `RenderTarget` and `RenderTargetRegistry`
- generic `Armes::render()` plus convenience wrappers
- `MappingContext` for strict mode, target name, runtime context, and future options
- target-aware custom JSX component mapping with import deduplication
- target-aware custom HTML tag replacement
- HTML mapper/emitter
- React/JSX mapper/emitter
- JSON tree emitter
- shared fixtures
- PHPUnit tests
- benchmark script
- README, SPEC, ARCHITECTURE, REPORT, PERFORMANCE docs

## Test Summary

Command:

```bash
./vendor/bin/phpunit --configuration phpunit.xml
```

Result:

```text
OK (53 tests, 87 assertions)
```

The old global XAMPP `phpunit` was not used because it is incompatible with PHP 8.4.

## Known Limitations

- YAML/TOML/Pkl parser behavior shares JSON tag-key semantics, but portable cross-language fixtures for all new formats should be expanded next.
- XML parser entrypoints exist through DOMDocument but still need deeper XML-specific fixtures.
- Source spans are JSON-pointer based, not byte/line-column based.
- `:elseif` is reserved but not implemented.
- Import slot handling is simple append behavior.
- Payload code nodes are not emitted by a specialized code emitter yet.
- JSX output is JSX-like string output, not an AST.
- HTML custom mapping currently supports simple tag replacement; callback/prop/children transforms are future extension points.
- Config-driven customization currently covers simple JSX imported components and HTML tag replacement.
- No static-analysis tool is configured yet.
- Compact JSON keeps whitespace text nodes by default, so it can be larger than minified HTML.
- TOML and Pkl rendering require object/map roots; scalar/list roots fail clearly.
- Pkl parsing depends on a local `pkl` CLI and should be used only for trusted local modules.

## Next Steps

- Add a formal extension/plugin registry for runtime nodes.
- Add `:elseif` and richer component slot semantics.
- Add deeper XML fixtures and decide XML-specific emitter behavior.
- Add shared fixtures for YAML/TOML/Pkl across PHP and future TypeScript implementations.
- Add optional whitespace compaction for storage use cases that do not need exact text nodes.
- Add schema emitter and PHP object mapper.
- Add persistent import cache options.
- Add static analysis, likely PHPStan or Psalm.
- Build a future TypeScript runner against the same fixtures.
