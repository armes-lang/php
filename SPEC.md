# Abstract PHP Specification Notes

This file records the PHP implementation's formal behavior and language-specific details. The canonical shared documentation is:

- [Core Concepts](../../../docs/CORE-CONCEPTS.md) for the processing model and five node kinds.
- [Source Commands](../../../docs/SOURCE-COMMANDS.md) for the complete portable command catalog.
- [Extending Abstract](../../../docs/EXTENDING.md) for supported and closed public extension boundaries.
- [Feature Parity](../../../FEATURES.md) for differences between PHP and TypeScript.

When this file and the shared command catalog overlap, the shared catalog defines portable source syntax and this file defines PHP implementation behavior.

## Canonical Model

All PHP parsers normalize into `Abstract\Tree\Node`. The five public kinds are:

| Kind | Required meaning |
| --- | --- |
| `element` | named target content with `name`, `props`, and `children` |
| `value` | typed data with `type` and `value` |
| `fragment` | ordered `children` without a wrapper |
| `runtime` | contextual processing with `name`, `props`, optional `value`, and `children` |
| `logic` | canonical operation with `op` and child-like `args` |

Source metadata is optional. PHP records source paths and JSON-pointer-like locations when available.

Canonical node kinds are closed. Applications cannot register another kind through the public API.

## Native Tag-Key Normalization

JSON is the reference native-data syntax. YAML, TOML, and Pkl decode into native PHP values and then use the same `NativeTagParser`.

```json
{
  "article": {
    "@": { "class": "card" },
    "#": [
      { "h1": "Title" },
      { "p": "Body" }
    ]
  }
}
```

Rules:

1. A normal map key becomes an Element name.
2. `@` contains props for the enclosing Element or Runtime node.
3. `#` contains ordered children.
4. A colon-prefixed key is an internal source command.
5. A primitive becomes an inferred Value node.
6. A list or multiple map roots becomes a Fragment.
7. An object without `@` or `#` is a shorthand child map.

Repeated Elements use arrays because JSON object keys cannot repeat.

Bare names stay user-owned. PHP does not interpret `eq`, `and`, `int`, `logic:eq`, or `type:int` as internal native-data source. New built-in domain source uses `:logic:eq` and `:type:int`.

## Internal Commands

The portable command set and aliases are defined in [Source Commands](../../../docs/SOURCE-COMMANDS.md).

The source-to-node boundary is significant:

- `:type:int` normalizes immediately to `Node::VALUE` with type `int`.
- `:logic:eq` normalizes immediately to `Node::LOGIC` with op `eq`.
- `:if`, `:each`, and `:import` normalize to `Node::RUNTIME`.
- `:comment`, `:doctype`, `:cdata`, `:raw`, and `:text` normalize to structural Value nodes.

`Runtime node` is therefore not the umbrella term for all internal commands.

Unknown explicit Type or Logic commands throw `ParseException` in strict parsing. Loose parsing records a diagnostic and preserves a safe Runtime-shaped node. There is no public type, logic-operator, or runtime-handler registry.

## Logic Evaluation

`LogicEvaluator` evaluates canonical Logic nodes against a context map. Supported shared operators are:

```text
eq ne gt gte lt lte and or not add sub mul div mod var
```

Symbol source aliases normalize before evaluation. No source alias appears in canonical `op` values.

```json
{
  ":logic:eq": [
    { ":logic:var": "user.role" },
    { ":type:string": "admin" }
  ]
}
```

`var` reads a dotted context path and accepts an optional fallback argument. A root Logic node resolves to a Value node. A Logic node in a prop resolves to its plain PHP value.

Legacy `:expr` remains accepted and may contain old bare readable operators. It is compatibility syntax and is not emitted for canonical Logic nodes.

No raw PHP or JavaScript `eval` is used.

## Runtime Resolution

`RuntimeResolver` handles canonical Runtime nodes after parsing.

### Conditions

`:if` requires a `test` prop. A truthy test selects normal children; a false test selects the contextual `:else` branch.

```json
{
  ":if": {
    "@": { "test": { ":logic:var": "user.isLoggedIn" } },
    "#": [{ "Dashboard": [] }],
    ":else": [{ "Login": [] }]
  }
}
```

`:else` is invalid outside `:if`. `:elseif` is not a supported public command; use a nested `:if` inside `:else`.

### Loops

`:each` requires an iterable `items` prop. `as` defaults to `item`, `index` defaults to `index`, and `key` is provided for the source iterable key.

```json
{
  ":each": {
    "@": {
      "items": { ":logic:var": "users" },
      "as": "user"
    },
    "#": [
      { "UserCard": { "@": { "name": { ":logic:var": "user.name" } } } }
    ]
  }
}
```

### Parent Prop Modifiers

`:props` and `:attributes` patch only their direct parent Element. Merge order is:

1. Start with static `@` props.
2. Apply modifiers in child order.
3. Later values replace earlier values.

A modifier outside an Element is a strict resolution error.

### Imports

`:import` and `:include` resolve another Abstract JSON file relative to the importing source path.

```json
{
  ":import": {
    "@": {
      "src": "./components/Card.abstract.json",
      "props": { "title": "Welcome" }
    },
    "#": [{ "p": "Slot content" }]
  }
}
```

Import props extend context and are also available as `props`. Slot children append to the imported root Element or Fragment. Imports are cached by absolute path, modification time, and content hash. Missing and circular imports are strict errors.

### Inert And Unknown Runtime Nodes

`:php`, `:js`, `:ts`, and `:code` parse as inert payload Runtime nodes. Strict resolution rejects them; loose resolution warns and drops them. They never execute code.

Other unknown Runtime names behave the same way. Parsing `:vendor.operation` does not register a handler.

## Markup Parsing

PHP provides distinct HTML, AML, and XML modes through `DomMarkupParser` and `MarkupParseOptions`.

- Normal tags become Elements.
- Known internal command tags normalize to Value, Logic, or Runtime nodes according to the same source rules.
- Attributes become props.
- Text becomes string Values.
- Comments, doctypes, CDATA, and raw script/style text use structural Value types.
- Nonstandard names pass through a DOM-safe placeholder layer.
- HTML void Elements are childless and following parsed descendants are lifted back to sibling position.
- Text-only HTML becomes one string Value rather than an implicit `<p>`.

Preferred AML command tags include `<:logic:eq>`, `<:type:bool>`, and `<:if>`. Compatibility forms are listed in the shared command catalog.

Markup round-trip correctness is structural, not byte-for-byte. Formatting and attribute quote style are not stable contracts.

## Data Formats

### YAML

YAML supports scalar, list, and map roots. It shares tag-key meaning with JSON.

### TOML

TOML is table-oriented. Parsing scalar documents and emitting scalar/list roots fail with format-specific errors.

### Pkl

Pkl is PHP-only. Parsing uses:

```text
pkl eval --format=json --no-project --root-dir=<source-dir> --working-dir=<source-dir> <file>
```

The bridge uses an argument array, timeout, and restricted root. Pkl parsing is an explicit operation for trusted local modules, not an Abstract runtime code command. Emission requires an object/map root.

## Source And Tree Emission

- `sourceJson()` emits editable tag-key source and keeps internal commands.
- `sourceAml()` emits editable AML and keeps internal commands.
- readable source defaults to `:logic:<op>` / `<:logic:<op>>`.
- `operatorStyle: 'symbol'` emits aliases such as `:==` / `<:==>`.
- `explicitTypedValues: true` requests `:type:*` wrappers where supported.
- `treeJson()` emits canonical, compact, or tagged tree data and does not resolve nodes.

Canonical Logic JSON uses `kind`, canonical `op`, and `args`. Canonical typed Value JSON uses `kind`, canonical `type`, and `value`.

## Render Targets, Mappers, And Emitters

`AbstractCore::render()` performs:

```text
resolve -> target lookup -> MappingContext -> mapper -> emitter
```

Built-in registered targets are HTML, JSX, and XML. YAML, TOML, and Pkl helpers resolve and then serialize tree data through their format emitters.

`RenderTarget` pairs `MapperInterface` and `EmitterInterface`. `withRenderTarget()` replaces or adds a named target immutably. Element mapping is target-specific: changing an HTML tag does not change JSX output.

Public target extensions are documented in [Extending Abstract](../../../docs/EXTENDING.md). They do not add source commands or runtime evaluation.

## Strict And Loose Contracts

Strict mode is the default correctness path.

Strict parsing rejects malformed source and unknown explicit Type/Logic domain commands. Strict resolution rejects unknown or misplaced Runtime nodes, inert directives, invalid imports, and mapper/runtime mismatches.

Loose parsing preserves unknown explicit domain source safely and reports diagnostics. Loose resolution may drop an unresolved Runtime node only when dropping is safe and diagnosable. Loose mode never turns unknown source into executable behavior.

## Portability Notes

- PHP supports Pkl; TypeScript does not.
- PHP imports are built-in filesystem operations; TypeScript imports use loaders.
- TypeScript legacy raw expressions support `in`; PHP does not. `:logic:in` is not portable or supported.
- React runtime output belongs to TypeScript's external React mapper; PHP emits JSX-like source only.
- XML parser backends differ, so portable documents should use the documented Abstract command forms and depend on structural rather than byte-identical output.
