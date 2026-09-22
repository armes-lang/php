# Compatibility

[Language](README.md) · [Next: Keys](syntax.md)

## On this page

- [Common questions](#common-questions)
- [Errors and diagnostics](#errors-and-diagnostics)
- [PHP and TypeScript compatibility](#php-and-typescript-compatibility)
- [Portable source](#portable-source)
- [Markup](#markup)

## Common questions

### Does changing the format change the mapper?

No. JSON, YAML, and TOML describe ARMES structure. The application chooses the mapper separately. Check [format limits](formats.md) when moving between formats.

### Can a server update my application without a rebuild?

Yes, for structures the installed application already understands and loads again. Its runtime, mappings, and components must support the updated document. New native features or components may still require an application update. Fetching, caching, and refreshing the document belong to the application; see [server updates](architecture.md#server-updates).

### Why did `class` become a child element?

It was outside `@`. Use `{"div":{"@":{"class":"card"}}}`. A normal key in the element body describes a child, not a prop.

### Do dynamic properties have to use `:props`?

No. `@` accepts direct supported expressions too. Use `:props` for an intentional parent patch and its override order. `:attributes` has the same parent-patch semantics.

### Why does a conditional property patch fail?

The patch's direct parent is probably `:if`, not an Element. Patches do not walk upward. Put an expression in a direct patch or make the conditional choose between complete elements.

### Why is `"false"` treated as true?

It is text. Use native boolean `false` in JSON/YAML, or a suitable typed expression. Markup attributes are commonly strings. Cross-host coercion also differs for values such as `"0"`.

### Why did my saved source look different?

Source serializers normalize aliases and structural forms. A scalar child may become a one-item array. Type wrappers needed to preserve literal containers remain. Whitespace and source-format comments are not lossless editing contracts.

### Why did a `:ref` fail, or remain plain data?

A reference session must be enabled, the destination must be expression-capable, and the source must be a valid selectable address. A nested ordinary data object is intentionally not executable just because it contains `":ref"`. Typed literal boundaries are always opaque.

### Why did a reference stop working after a text edit?

Structural addresses describe positions in a snapshot. A session can preserve identity through a declared edit, but a new text parse has no such history. Update positional references when manually reordering arrays. Duplicate named siblings require indexed structural steps.

### Why does my custom name not run or render as a component?

An element name is not a registered component or computation. Configure the mapper for output, or a session result adapter for computation. An unknown colon-prefixed name is not automatically executable.

### Why are commands visible in JSON output?

`sourceJson()` intentionally keeps them. `treeJson()` inspects the tree without evaluating it. Use a render operation for final output, or materialize a ready reference snapshot before rendering. Internal command tags should not appear in strict final rendered output.

### Does `:raw` run code?

Core treats it as a raw output value, not an evaluator. A consuming platform may execute or interpret emitted content, so only supply raw content appropriate for that target. `:js` and `:php` remain inert in the default resolver.

## Errors and diagnostics

Strict mode is the default correctness path. Parsing and resolving are separate stages.

| Situation | Result / action |
| --- | --- |
| Malformed JSON or invalid `@` payload | Parse failure; fix source structure |
| Unknown `:type:money` / `:logic:xor` | Strict parse failure; these do not register extensions |
| Unknown Runtime command | May parse; strict ordinary resolution fails |
| `:else` / `:props` without the correct parent | Strict resolution failure |
| Missing or circular import | Import error; check source path/loader and inclusion chain |
| Ambiguous named reference | Use a unique name or explicit structural index |
| Deleted/stale reference source | Repair the binding; do not silently retarget it |
| Dependency cycle | Break the value cycle or avoid materializing recursive structure |
| Async adapter used synchronously in TypeScript | Use `await session.resolve()`; providers declare `async: true` |
| TOML rejects output | Check root shape and unsupported values such as null |
| Scoped directive unknown | Configure TypeScript namespaces/profile; PHP has no equivalent feature |

Loose parsing can record diagnostics and preserve unknown explicit Type/Logic commands as safe Runtime-shaped nodes. Loose ordinary resolution can warn and drop unsupported commands. It is useful for editing, not a promise to recover every malformed input. Some validation failures still throw.

Reference sessions return settled snapshots with `ready` or `error` status and structured diagnostics. Check the status before using results. Materialization rejects failed snapshots. An unresolved authored expression can remain editable even when output generation fails.

## PHP and TypeScript compatibility

| Feature / edge case | PHP | TypeScript |
| --- | --- | --- |
| Core tag-key source, five kinds, Type/Logic commands | Supported | Supported |
| Direct `@` and direct-map parent patches | Supported | Supported |
| `:props` with an extra internal `@` wrapper | Not equivalent to a direct patch | Unwrapped by native parser |
| Object-loop `index` | Numeric iteration position | Object entry key |
| Object-loop `key` | Source key | Source key |
| Import source access | Filesystem | Explicit loader |
| Import format | JSON | JSON |
| Ordinary import `src` | Literal string | May resolve an expression |
| Appended import child context | Parent context | Imported context |
| Destination scopes | Not implemented | Opt-in experimental |
| Reference sessions | Synchronous | Sync and async |
| Result adapters | Synchronous callbacks | Sync or declared async providers |
| Ordinary nested prop expressions | Recursively interpreted in more positions | Direct native envelopes; nested data generally retained |
| Session nested `:ref` recognition | Location-gated | Location-gated |
| `:text` canonical type | `string` | `text` |
| Legacy bare `in` expression | Unsupported | Legacy evaluator only; not `:logic:in` |
| Pkl | CLI integration | Not implemented |
| JSON-shaped markup attributes | Generally strings | Additional decoding/recognition |
| Empty native object / some child-map fragments | May normalize differently | May normalize differently |

Other host-language edges include missing context values (`null` versus `undefined` without a fallback), string/number conversion, equality, modulo, truthiness, and numeric precision. Prefer explicitly typed inputs and fallback values. Typed containers protect nested data, but a top-level `#` key at a typed command's body is a reserved payload edge case; see [Values](values.md#literal-data).

These differences describe current behavior. They are not recommendations to change existing document meaning silently. When exact AST shape matters for references, test the chosen source on both implementations.

## Portable source

1. Use JSON or YAML for rich executable source.
2. Put properties in `@` and repeated children in a `#` array, one named node per entry.
3. Use direct map payloads for `:props` and `:attributes`.
4. Prefer `:logic:*` and `:type:*` over compatibility aliases.
5. Keep conditions boolean and numeric operations numeric.
6. Use body-level `:else`; use `key` for object iteration identity.
7. Keep literal directive-looking objects inside opaque typed boundaries where needed.
8. Opt into sessions explicitly for references; keep `$` document-instance local.
9. Save symbolic source before evaluation consumes commands.
10. Verify round trips in the formats and implementations your application actually uses.

## Markup

- PHP leaves ordinary attribute values as strings apart from boolean attribute handling. TypeScript additionally recognizes some JSON-shaped attribute values and expression envelopes.
- An attribute containing `'{":logic:var":"user.name"}'` is therefore not a portable dynamic prop. Prefer native JSON/YAML for portable expression-rich documents.
- A markup `test="false"` is text, not a native boolean `false`. It can be truthy. Use typed native source for conditions whose types matter.
- PHP's markup `:else` handling is not equivalent to the recommended body-level JSON `:else` form. Do not assume a conditional source round trip through markup preserves branch behavior across both cores.
- Some Runtime payloads, including direct reference and import payloads, have different native and markup shapes. `sourceArmes()` is not a guarantee that every reference-rich JSON document reloads with identical semantics in both cores.
- Source markup can omit false/null attributes and normalize boolean attributes. Scalar typing and exact property presence therefore need checking when converting formats.

These limits are reasons to choose the right authoring format, not instructions to embed executable code in attributes.

