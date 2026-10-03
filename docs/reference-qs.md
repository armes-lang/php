# qs conventions and typed ARMES lookup

ARMES uses qs-style brackets for addressing **existing typed values**. It does not parse a reference as a query string, and is not a fully qs-compatible query parser or serializer. `qs@6.16.0` is an exact development dependency of TS Core, used as a test oracle; it is not bundled into the runtime. PHP uses the shared typed selection fixtures, not PHP `parse_str`.

The [official qs documentation](https://github.com/ljharb/qs/tree/v6.16.0#readme) defines the query behavior. Applications that accept or emit query strings should use qs explicitly at that transport boundary, with their chosen options. ARMES receives the resulting objects/arrays and reads them without string conversion. Numbers, booleans, null, arrays and objects loaded from JSON/YAML/TOML retain their source types.

| Capability | ARMES interpretation | Reuse or custom logic | Options and regression coverage |
| --- | --- | --- | --- |
| Nested objects `a[b][c]` | `$[a][b][c]` reads own keys | Custom typed traversal; qs parse oracle | Nested selector fixture, `a[b][c]=x` |
| Indexed arrays | `[0]` reads an array position; indexes must be canonical nonnegative integers | Custom; no compaction | qs indexed-array ordering oracle |
| Unindexed arrays `a[]` | `[]` expands existing present array slots, ascending by index | Custom read semantics, distinct from query insertion | Empty/single/multiple projection fixtures |
| Arrays of objects | `$[a][][b]` projects b from each item | Custom typed projection | `a[][b]=c`, fixture arrays of objects |
| Repeated keys and multiple values | Read the object/array already produced by transport parsing | qs at the boundary; no repeated-key parser in ARMES | `duplicates: combine` (default), `first`, `last` oracle tests |
| Sparse arrays | Preserve positions; `[]` skips absent slots and includes explicit nulls | Custom; no `filter(Boolean)` or compaction | `allowSparse:true` TS in-memory test; portable JSON has no holes |
| Mixed numeric/named notation | A map stays a map; `[]` does not expand objects | Custom shape checks | qs mixed notation and `strictMerge:false` oracles |
| Arrays above qs's default index limit | If qs produced an object, `[100]` is its exact key | Custom lookup; qs defaults are not ARMES array limits | `arrayLimit` default 20 and error oracle |
| `parseArrays:false` | Read its resulting numeric-keyed object as an object | Boundary option only | `a[]=b` → `$[a][0]` oracle |
| Dots | Dots in bracket keys are literal; no implicit dot traversal | qs boundary options only | `allowDots:true`, `decodeDotInKeys:true` oracle; literal-key fixtures |
| Comma lists | Read the parsed list; no automatic comma splitting in selectors or values | qs `comma` at query boundary | Comma-array oracle |
| Empty arrays | An existing empty array expands to `[]` | Custom; never missing or null | `allowEmptyArrays:true` parse oracle and fixture |
| Bare/empty query values | Preserve whatever the decoder produced | qs boundary `strictNullHandling`; lookup distinguishes missing, null, empty string | Strict-null oracle and typed value fixtures |
| Null omission | ARMES does not omit nulls | qs serialization `skipNulls` belongs to transport | Transport only; no ARMES claim |
| Encoding, `&`, `=`, `+`, percent escapes | Selectors are not URL-decoded. Quoted keys address literal reserved characters | Markup entities decode once; qs does query encoding/decoding | Encoded-bracket oracle; reserved-key fixture |
| Charset/sentinel/numeric entities | Decoder determines resulting text; ARMES reads Unicode strings | qs transport options, not core options | `charset`, `charsetSentinel`, `interpretNumericEntities` are transport-only |
| Serialization array styles | Preserve a typed array regardless of transport spelling | qs `indices`, `brackets`, `repeat`, `comma`; no ARMES query serializer | Indexed object-array and brackets stringify oracle tests |
| Serialization of null/empty containers | No lookup coercion or inferred missing values | qs serializer, which can omit empty containers; structured sources remain authoritative | Strict-null stringify oracle; empty typed fixtures |
| Depth/parameter/array limits | ARMES has its own positive reference/resource/evaluation budgets | Custom runtime limits; qs limits apply only when parsing a query | `depth`, `strictDepth`, `parameterLimit`, `throwOnLimitExceeded`, `arrayLimit` error oracles; ARMES limit tests |
| Prototype handling | `__proto__`, `prototype`, `constructor` traversal is forbidden even if present | Custom own-property checks; no prototype-chain traversal | Unsafe-member fixtures; do not enable `allowPrototypes` for untrusted query input |

## Selection shapes

```json
{":ref":{"path":"$/Rows/#result[ada][name]"}}
{":ref":{"path":["$/data/@value","$/data/@value"]}}
{":ref":{"path":{"name":"$/Rows/#result[ada][name]","count":"$/Rows/#count"}}}
```

A string returns one selected value, which may itself be a collection. A list returns an ordered list of selections, including duplicates; it does not flatten an array selected by one list entry. A named map returns the same authored names, with each selected value intact. Empty list/map shapes remain empty. Within one selector, each `[]` expands and projects; nested expansions concatenate their projected selections in depth-first index order. A missing projected field fails the whole reference, rather than dropping that item. Structural sibling lookup must match exactly one node unless an occurrence or ordinal is explicit.

`[ada]` reads a map key, or a result adapter's **declared** keyed-result index. It never searches an array's `id` property. Adapter keys must be unique and cover the direct result entries. `[0]` selects an array position; `["0"]` selects an object/declared-result key and rejects ordinary arrays. In PHP, associative maps normally use associative arrays; empty or contiguous numeric-keyed objects remain `stdClass` so JSON serialization and lookup retain their object shape. PHP native arrays do not have JavaScript holes: portable sparse data should use explicit null slots or a numeric-keyed object. This host-language difference is not advertised as sparse-array parity.

See [Reference Syntax](reference-syntax.md) for grammar, escaping, scopes, resources and errors.
