# Explicit references and inline expressions

`:ref` is a runtime read. Ordinary strings and names without `:` remain data/userland. The wrapper never renders. Existing default resolve APIs remain synchronous/asynchronous as before; reference evaluation is an opt-in ReferenceSession. `:import` instantiates composition; `:ref` reads.

## Selector grammar

```
selector := anchor child* (prop | output)? data*
anchor   := '$' | '.' | '..' ('/..')* | '#' name
child    := '/' name ('[' uint ']')? | '/[' uint ']'
prop     := '/@' name
output   := '/#' name
data     := '[' key ']' | '[]'
name     := bare | '[' JSON-string ']'
key      := bare | JSON-string
```

`$` is the authored document/instance root. `$[users][ada][name]` is an ordinary data resource root. `.` is the containing authored element/runtime scope. Reference, expression, logic and prop-modifier wrappers are transparent; a top-level transparent directive uses the document root. `..` ascends authored parents, never generated props/data objects. Imports establish their own root. Repetitions use the current instance for template descendants and the document for nodes outside the template. Crossing an instance boundary from outside requires a declared keyed result. No ancestor traversal escapes a resource.

Child names must match once. `/Row[1]` selects the second sibling named Row; `/[2]` selects the third structural child. `/["child"]` forces compact syntax and a literal name. Enter data with `/@config` or `/#result`; afterward use brackets only. `/#result` is the default host computation, `/#count` a separately registered output. Component results are independent of rendering/mapping; selected target modifiers may affect evaluated props. `@` reads effective evaluated props. Ordinary HTML `id` is unrelated.

A leading `#source-value` selects a persistent semantic ID. Later `/#result` selects output. Declare IDs with `{"data":{"@":{":id":"source-value","value":7}}}` or `<data :id="source-value" value="{:type:int 7}"/>`. IDs must be nonempty, unique within each authored resource/instance; duplicate IDs fail. Declare them on structural nodes (including logic operands), not embedded prop expressions; embedded IDs report `invalid-id`. Synthetic document fragments cannot declare IDs. Rename/reorder/reparent preserves IDs. Copies generate new IDs and remap internal ID reads. Deleted identities remain errors.

Quoted names/keys use JSON string escapes: `$/["a/b"]/@["x@y"]["literal[key]"]`. Percent escapes are literal in selectors; markup entities decode once before inline and reference parsing. Unsafe prototype keys are rejected.

Legacy selectors beginning `$/child`, `$/children`, `$/props`, `$/value`, `$/result`, `$/member`, `$/item`, `$/args`, `$/branch` or `$/entry` always use the old typed grammar. Selection existence never changes dialect. Structured `syntax:"compact"` overrides reserved first names; quoted first names also disambiguate. Invalid examples: `$/data/@config/theme`, `$[rows][-1]`, `$/Row[]`, external `./@value`. A literal markup `value="7"` stays a string; use `{:type:int 7}` for numeric inputs. References never coerce values. Existing arithmetic handlers retain their operator-level conversions (TS Number conversion and PHP numeric casts); this change does not make them strict-number validators. Studio ExampleDouble explicitly requires a finite number. Use typed numeric inputs for portable arithmetic; nonnumeric coercion follows the existing host behavior.

## Shapes and typed data

`{":ref": ...}` (without the space) accepts a string or `{src?,format?,mode?,syntax?,path?}`. A single path preserves the selected type. A `path` list returns an ordered array, preserving duplicates and nested collections. A named `path` map returns an object with the authored names. Empty lists/maps return empty shapes. Missing selections fail the whole read; null/false/zero/empty strings are valid values.

`[]` reads present array slots in ascending index order, projects remaining steps over each, and returns an array even for zero/one match. Multiple `[]` flatten one projection level each. Objects are not wildcard collections. `[ada]` reads an object key or an adapter's declared result key; it never searches item.id. Numeric brackets address array indexes or the exact object key. Quoted numeric brackets address object keys or declared result keys, never array positions. Arrays are never compacted by lookup.

## Resources

```
{":ref":{"src":"./data/users.yaml","path":"$[users][ada][name]"}}
<:ref src="./data/users.yaml" path="$[users][ada][name]"/>
<:ref src="./component.armes">#source-value/@value</:ref>
{:ref ./data.yaml::$[value]}
```

`resource::selector` is recognized only within reference strings. Quote a locator with JSON quoting when it contains spaces, `::`, brackets, `#`, query strings or Windows backslashes; alternatively use the structured form. Example string: `"./data with spaces.yaml"::$[value]`. Do not URL-decode a locator or split at every `::`.

`.armes` and `.armes.json/.armes.yaml/.armes.yml/.armes.toml` default to document mode. Generic JSON/YAML/TOML default to data; explicit format/mode override. `.aml` has no default registration after the ARMES rename; a host may explicitly register it. Data decoding never executes directive-looking keys. Omitted path returns the root, preserving data versus a structural document view. A returned document is not automatically imported. Props retain structural views; child consumers explicitly materialize a view. Collections are typed values, not implicit child splices.

Relative resources resolve from the declaring node's source provenance or explicit baseUri. In-memory relative references without a base fail. External selectors require `$` or semantic ID roots. External document reads evaluate only demanded props/results; result adapters require host `referenceReadable` permission. Read contexts do not instantiate `:import`/`:include`, execute `:code`, or invoke other custom runtime handlers by default; demanded materialization reports `handler-denied`. External prop expressions allow the built-in pure control/logic/reference directives. Hosts expose additional computation through an explicitly readable structural result adapter rather than implicit composition. External context is empty unless the host supplies resourceContext.

## Inline expressions and emission

Whole attributes beginning `{:...}` normalize to existing directives. Recursive directives, JSON-style arrays/objects/strings and finite numbers, booleans/null are tokenized without eval. Logic aliases share the existing operators. Logic operators take operands; ref/type/expr directives take one payload. `{:logic:add 1 2}` means two arguments; `{:logic:add [1,2]}` means one array argument and does not silently spread it. Existing fixed-arity operators validate their operand counts. Unquoted reference tokens end at whitespace/comma/closing directive brace, with balanced brackets/quoted keys. Quote delimiter-bearing paths. No text interpolation is inferred.

Typed literal containers remain opaque. An inline typed container containing nested directive expressions lowers to explicit `:expr` around the typed source payload. JSON source remains valid JSON using structured directive objects; ordinary JSON strings never execute. Escape literal `{:` as `\{:`. Decode markup attributes once, parse inline syntax, then parse references. Emit in reverse order with correct markup escaping. Source emitters preserve symbolic directives and user data fields, never evaluate them. Formatting whitespace between block logic operands is syntax; explicit typed string operands retain their whitespace. Persistent IDs on scalar and structural values emit explicit directive wrappers when literal markup cannot carry the ID.

```armes
<ExampleDouble input="{:ref $/section/data/@value}" />
<p title="{:logic:add
  {:ref $/section/ExampleDouble/#result}
  {:ref $/section/data/@value}
}">Doubled: <:ref>$/section/ExampleDouble/#result</:ref></p>
<ExampleRows rows='{:type:array [{"id":"ada","name":"Ada"}]}' />
<Widget config='{:type:object {
  "name":"Demo", "value":{:ref "$/section/data/@value"},
  "double":{:logic:mul {:ref "$/section/data/@value"} 2}
}}' />
<Widget config='{:ref {"src":"./config.txt","format":"yaml","mode":"data","path":"$[editor]"}}' />
```

`{":ref":"$/section/data/@value"}`, `<:ref>$/section/data/@value</:ref>` and `{:ref $/section/data/@value}` lower to the same runtime read. Inline objects containing directives are ARMES syntax, not JSON. Their JSON source emission is valid JSON with explicit `:expr`/typed envelopes and structured directives. Attribute emission serializes these envelopes symbolically, then escapes markup entities once; canonical `kind/props/meta` implementation objects are not printed as the directive representation. Legitimate user data fields with those names remain data.

The normalized selector is `{anchor,id?,up?,syntax,address:{root:"document",segments}}`; segments distinguish structural child/ordinal/occurrence, prop, result with optional output name, data key with a quoted flag, and all-values expansion. `ParsedReference` adds `spec`, `shape` and ordered named selections. Compilation adds the consuming plan owner, source provenance and diagnostic operand path. None of these ephemeral fields are author syntax or persistent node IDs.

## Evaluation and host boundaries

ReferenceSession compiles references per authored revision and indexes IDs/children/declared keys. Forward dependencies evaluate on demand; only actual evaluation cycles fail. Parsed-resource caching is distinct from instance/context/target-specific values. The retained parsed cache evicts least-recently-used entries to the configured resource count and total-source-byte budgets; current evaluation pins remain valid after eviction. Hosts retaining historical snapshots retain their own values separately. Each evaluation pins one content string per canonical URI and validates it before publication; an observed change retries at most twice before reporting `resource-changed`. Hosts call refreshResources after resource edits/watch events. TS values are frozen; PHP reads detach mutable data objects from cached resources. Async loaders accept AbortSignal; synchronous resource loading reports async-required rather than starting asynchronous I/O. Transactions cancel superseded TS evaluations. Browser/workspace loaders contain no filesystem imports; Node file loading lives in the separate node entry.

Filesystem references require explicit allowed roots, checked after realpath normalization (including symlinks). Browser core has no Node fs imports. Studio exposes only selected workspace files. Network loading is disabled unless explicitly configured by a host. Only local paths and `file:`, `memory:`, `workspace:` schemes avoid the network permission gate; other schemes require `allowNetwork`, even when a loader resolves a local-looking locator to one. A custom loader cannot bypass the core source-size check. Limits default to 8 MiB/resource, 32 MiB/evaluation, 128 resources, 10,000 selected values/read, 100,000 total selected values, 1,000,000 work steps, inline depth 64 and 100,000 inline tokens. Limit/lookup errors are diagnostics, never normal values.

Migration is structural and previewable. `previewReferenceMigration()` rewrites compilable explicit legacy references: `$/child/section/child/data/props/value` → `$/section/data/@value`; `/result/entry/ada/member/name` → `/#result["ada"]["name"]`. Unsupported structural result selections stay legacy with a reported reason. No blind string replacement and no automatic document migration.

See [qs compatibility](reference-qs.md), [migration and move rules](reference-migration.md), and [measured benchmarks](reference-benchmarks.md). `:import` retains the existing composition parser and instantiation behavior; this resource registry extends explicit reads, not arbitrary imports or executable plugins. TS target-scope modifiers retain their existing semantics; PHP does not gain the unrelated TS target-scope feature through this change. PHP retains its existing `:text` alias normalization to a string value. PHP also remains synchronous, with its existing DOM parser diagnostics reporting element/attribute location rather than TS's exact attribute offsets.
