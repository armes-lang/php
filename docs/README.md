# Language

[ARMES](../README.md) · [Next: Keys](syntax.md)

ARMES describes structure; a mapper interprets it for a destination. The rules below apply across supported source formats. JSON examples make the names, values, and child lists easy to see.

- **New to ARMES?** Read the [introduction and runnable example](../README.md).
- **Writing a document?** Start with [Keys](syntax.md), then [Properties](properties.md).
- **Looking up a key?** Choose a group below; each link opens its example and rules.
- **Connecting an application?** Use [Architecture](architecture.md) and the [API](integration.md).

Examples that show HTML use the default HTML target unless stated otherwise. Their nodes and properties remain ARMES structure; another configured mapper can represent them differently.

## Keys

Names and structure:

- [Names](syntax.md#names) — create a named node.
- [`@`](syntax.md#at) — give the node properties.
- [`#`](syntax.md#hash) — give the node children.
- [Commands](syntax.md#commands) — understand colon-prefixed keys and their kinds.
- [Children](syntax.md#children) — nest nodes and preserve their order.
- [Shorthand](syntax.md#shorthand) — use a shorter form when the structure is simple.

## Properties

Define properties or update the direct parent:

- [`@`](properties.md#at) — define properties on this node.
- [`:props`](properties.md#props) — patch the direct parent’s properties.
- [`:attributes`](properties.md#attributes) — the same patch under another name.
- [Order](properties.md#order) — understand which property value takes precedence.
- [Expressions](properties.md#expressions) — compute or look up a property’s value.

## Logic

Choose content and calculate values:

- **Conditions**

  - [`:if`](logic.md#if) — choose the branch for a true condition.
  - [`:else`](logic.md#else) — provide the branch for a false condition.

- **Repeat**

  - [`:each`](logic.md#each) — repeat a template for each item.

- **Read data**

  - [`:logic:var`](logic.md#logicvar) — read application data, with an optional fallback.

- **Compare**

  - [`:logic:eq`](logic.md#logiceq) — test whether two values are equal.
  - [`:logic:ne`](logic.md#logicne) — test whether two values differ.
  - [`:logic:gt`](logic.md#logicgt) — test whether the first value is greater.
  - [`:logic:gte`](logic.md#logicgte) — test whether it is greater or equal.
  - [`:logic:lt`](logic.md#logiclt) — test whether the first value is less.
  - [`:logic:lte`](logic.md#logiclte) — test whether it is less or equal.

- **Combine**

  - [`:logic:and`](logic.md#logicand) — test whether every condition is true.
  - [`:logic:or`](logic.md#logicor) — test whether any condition is true.
  - [`:logic:not`](logic.md#logicnot) — reverse a condition’s truth value.

- **Calculate**

  - [`:logic:add`](logic.md#logicadd) — add numbers.
  - [`:logic:sub`](logic.md#logicsub) — subtract numbers.
  - [`:logic:mul`](logic.md#logicmul) — multiply numbers.
  - [`:logic:div`](logic.md#logicdiv) — divide numbers.
  - [`:logic:mod`](logic.md#logicmod) — find the remainder after division.

- **Older forms**

  - [`:logic`](logic.md#logic) — write an operation in the legacy wrapper form.
  - [`:expr`](logic.md#expr) — write a legacy data expression.

- [Combining expressions](logic.md#combining) — use one operation as another’s input.

Each operation uses its full command name above. Accepted aliases appear beside its reference entry.

## Types

Write values and literal data:

- [Literals](values.md#literals)
- **Types**

  - [`:type:string`](values.md#typestring) — store text.
  - [`:type:int`](values.md#typeint) — store a whole number.
  - [`:type:float`](values.md#typefloat) — store a decimal number.
  - [`:type:bool`](values.md#typebool) — store true or false.
  - [`:type:null`](values.md#typenull) — store an explicit empty value.
  - [`:type:array`](values.md#typearray) — keep a list as literal data.
  - [`:type:object`](values.md#typeobject) — keep a map as literal data.

- **Text and markup**

  - [`:text`](values.md#text) — store text with ordinary output escaping.
  - [`:comment`](values.md#comment) — include a markup comment.
  - [`:doctype`](values.md#doctype) — include a document type declaration.
  - [`:cdata`](values.md#cdata) — include XML character data.
  - [`:raw`](values.md#raw) — include trusted text without ordinary escaping.

- [Keeping data literal](values.md#literal-data)

## Imports

Reuse another document:

- [`:import`](runtime.md#import) — load another document.
- [`:include`](runtime.md#include) — use the equivalent import spelling.
- [Arguments and appended children](runtime.md#arguments) — supply data and additional content.
- [Rules](runtime.md#rules) — check paths, formats, and import boundaries.

## References

Get a value from another location in the document:

- [`:ref`](references.md#ref) — requires an enabled reference session.
- [Addresses](references.md#addresses) — locate a source in the document.
- [Values and nodes](references.md#values-and-nodes) — select data or structural views.
- [Literal data](references.md#literal-data) — keep data from becoming a reference.
- [Editing](references.md#editing) — preserve references while editing the tree.

## Formats

Choose how to write and save a document:

- [JSON](formats.md#json) — write nodes as objects and lists.
- [ARMES (markup)](formats.md#markup) — write nodes as tags.
- [YAML](formats.md#yaml) — write indented maps and lists.
- [TOML](formats.md#toml) — write tables and values.
- [Pkl](formats.md#pkl) — evaluate trusted modules with PHP’s Pkl support.
- [Saving](formats.md#saving) — choose editable source or evaluated output.

## Extend ARMES

- [Extension guide](extending.md) — build a mapper, write output, or connect another source format.

- [Architecture](architecture.md) — library parts, tree kinds, mapping, sessions, and terminology.
- [API](integration.md) — package-specific calls and complete code examples.
- [Compatibility](troubleshooting.md) — errors, PHP/TypeScript differences, and format limits.
- **Configured TypeScript scopes**

  - [`:<namespace>:props`](architecture.md#namespaceprops) — patch properties for an active namespace.
  - [`:<namespace>:attributes`](architecture.md#namespaceattributes) — use the equivalent scoped patch.
  - [`:<namespace>:unset`](architecture.md#namespaceunset) — remove named properties.
  - [`:<namespace>:scope`](architecture.md#namespacescope) — include children for an active namespace.

## Not executable

- [Inert keys](syntax.md#inert-keys): `:php`, `:js`, `:ts`, `:code`.
- [Unsupported keys](syntax.md#unsupported-keys): names that do not have portable built-in behavior.
