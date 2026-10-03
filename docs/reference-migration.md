# Reference migration and identity lifecycle

New source should use compact selectors. Existing typed structural paths continue to work under their explicit compatibility grammar. No files are automatically migrated.

| Existing source | Compact source |
| --- | --- |
| `$/child/section/child/data/props/value` | `$/section/data/@value` |
| `$/child/section/child/ExampleDouble/result` | `$/section/ExampleDouble/#result` |
| `$/child/section/child/ExampleRows/result/entry/ada/member/name` | `$/section/ExampleRows/#result["ada"]["name"]` |
| `/member/name` after a value selection | `["name"]` |
| `/item/0` after a value selection | `[0]` |

There is no heuristic fallback based on whether a target exists. The first unquoted legacy keyword fixes the dialect. A literal child named `child` uses `$/["child"]`; a structured spec may explicitly say `syntax:"compact"`. Mixed `/name` data traversal is invalid in compact syntax. Legacy structural result branches/operands without a compact equivalent remain legacy.

Both sessions provide `previewReferenceMigration()`, returning a proposed tree, exact before/after changes and skipped explanations. It parses and rewrites explicit syntax without loading resources or evaluating adapters. Review the preview, then apply it as a normal tree transaction. Studio's inspector exposes Preview and Apply; Apply records undo history and refuses a preview made before a subsequent document edit. Export/save still emits symbolic references.

Persistent IDs use `@ {":id":"source-value"}` or the markup `:id` attribute, never the ordinary HTML `id`. IDs are scoped to an authored resource/instance. Editor/DOM/runtime IDs are separate and are not serialized into language source. Rename, reorder and reparent keep semantic IDs. Copy/duplicate generates fresh IDs for structural nodes and rewrites explicit local ID references to copied targets; references outside the copy stay external. Typed literal payloads stay opaque. Deleting a target retains its `#id` spelling as an explicit dangling reference, including after save/reload. Legacy positional pins that cannot be saved without retargeting require rebinding before normalized save.

## Moving between resources

TS `previewResourceMove({sourceUri,destinationUri,destination,address,parent?,documents?})` returns a single proposed document batch with the expected revisions and reference rewrites. It does not write files, change either session or invoke loaders/adapters. Supply every open/affected authored document with canonical workspace URIs. The host owns discovery of other incoming references and must check all expected revisions before atomically accepting the batch. It must also supply destination format overrides when extensions do not identify the document format.

The preview preserves semantic IDs, rejects destination ID collisions, rewrites incoming references to the destination, leaves internal moved reads local, and preserves the original base of outgoing resource/import locators. A selection list/map that would span two resources is rejected with `split-resource-selection`; split it into separate explicit refs before moving. Unknown incoming documents cannot be rewritten by a preview that was not given them. Runtime instances are addressed through producer results; a move does not merge instance identity scopes. Moving only part of an `each`/import/include instantiation boundary is rejected with `instance-move`; move the complete producer instead.

Studio currently edits one authored document and mounts selected files as read resources. Same-file move/copy is integrated into its commands and history. The multi-document move batch is a TS host API, not a new workspace editor or an automatic filesystem migration. PHP has matching source/evaluation semantics, stable binding/copy and migration preview; it does not expose the TS multi-document editing-preview API or asynchronous providers/cancellation. These are explicit host-API differences, not alternate reference grammars.
