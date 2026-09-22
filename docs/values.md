# Types

[Language](README.md) · [Next: Imports](runtime.md)

Values describe data regardless of the destination. Most values need no type command. Write text, numbers, booleans, and null directly; use an explicit type when you need a declared type or a literal data boundary.

## Literals

```json
{ "Panel": { "@": { "width": 100, "enabled": true, "name": "hello", "description": null } } }
```

- `100` is a number; `"100"` is text.
- `true` and `false` are booleans.
- `null` is an explicit empty value.
- A normal object or array in a **child position** describes structure. Use `:type:object` or `:type:array` when it should be data instead.

## Index

- **Types**

  - [`:type:string`](#typestring) — store text.
  - [`:type:int`](#typeint) — store a whole number.
  - [`:type:float`](#typefloat) — store a decimal number.
  - [`:type:bool`](#typebool) — store true or false.
  - [`:type:null`](#typenull) — store an explicit empty value.
  - [`:type:array`](#typearray) — keep a list as literal data.
  - [`:type:object`](#typeobject) — keep a map as literal data.

- **Text and markup**

  - [`:text`](#text) — store text with ordinary output escaping.
  - [`:comment`](#comment) — include a markup comment.
  - [`:doctype`](#doctype) — include a document type declaration.
  - [`:cdata`](#cdata) — include XML character data.
  - [`:raw`](#raw) — include trusted text without ordinary escaping.

- [Literal data](#literal-data) — preserve opaque objects and arrays.

## `:type:string`

Store text.

- **Syntax:** the string payload shown below.

```json
{
  ":type:string": "Hello"
}
```

- **Result:** one `string` value: `"Hello"`.
- **Rules:** Use a string for portable source.
- **Aliases:** `:string`.

## `:type:int`

Store an integer.

- **Syntax:** the int payload shown below.

```json
{
  ":type:int": 42
}
```

- **Result:** one `int` value: `42`.
- **Rules:** Use an integer within the reliable numeric range of your host.
- **Aliases:** `:int`, `:integer`, `:type:integer`.

## `:type:float`

Store a floating-point number.

- **Syntax:** the float payload shown below.

```json
{
  ":type:float": 3.5
}
```

- **Result:** one `float` value: `3.5`.
- **Rules:** Use a number. Request explicit scalar types when saving if the declared float kind must survive normalization.
- **Aliases:** `:float`.

## `:type:bool`

Store a boolean.

- **Syntax:** the bool payload shown below.

```json
{
  ":type:bool": true
}
```

- **Result:** one `bool` value: `true`.
- **Rules:** Use `true` or `false`, not quoted text, for portable values.
- **Aliases:** `:bool`, `:boolean`, `:type:boolean`.

## `:type:null`

Store null.

- **Syntax:** the null payload shown below.

```json
{
  ":type:null": null
}
```

- **Result:** one `null` value: `null`.
- **Rules:** Null is a value, not a request to delete a property. TOML cannot represent null.
- **Aliases:** `:null`.

## `:type:array`

Store an ordered list as one data value.

- **Syntax:** the array payload shown below.

```json
{
  ":type:array": [
    1,
    2,
    3
  ]
}
```

- **Result:** one `array` value: `[1, 2, 3]`.
- **Rules:** The contents are data, not child nodes or executable commands.
- **Aliases:** `:array`.

## `:type:object`

Store an object as one data value.

- **Syntax:** the object payload shown below.

```json
{
  ":type:object": {
    "name": "Ada",
    "active": true
  }
}
```

- **Result:** one `object` value: `{"name": "Ada", "active": true}`.
- **Rules:** The contents are data. Use this boundary for an object that contains command-looking keys.
- **Aliases:** `:object`.

## `:text`

Write explicit text content.

- **Syntax:** a string.

```json
{
  "p": {
    ":text": "A < B"
  }
}
```

- **Result:** HTML output `<p>A &lt; B</p>`.
- **Rules:** Compatible emitters escape text. PHP records the value type as `string`; TypeScript uses `text`.
- **Aliases:** Ordinary string children are usually simpler.

## `:comment`

Include a markup comment.

- **Syntax:** comment text.

```json
{
  ":comment": "A note"
}
```

- **Result:** HTML output `<!--A note-->`.
- **Rules:** The comment can appear in emitted source even though it is not displayed as page text. It is not private data.
- **Aliases:** None.

## `:doctype`

Include a document type declaration.

- **Syntax:** declaration content without the outer markup.

```json
{
  ":doctype": "html"
}
```

- **Result:** a document type declaration: `<!DOCTYPE html>` in TypeScript, `<!doctype html>` in PHP.
- **Rules:** Use a declaration appropriate to the output format.
- **Aliases:** None.

## `:cdata`

Include CDATA text for XML output.

- **Syntax:** a string.

```json
{
  "note": {
    ":cdata": "A < B"
  }
}
```

- **Result:** TypeScript’s default XML output is `<note><![CDATA[A < B]]></note>`. PHP’s default XML mapping emits equivalent escaped text: `<note>A &lt; B</note>`.
- **Rules:** CDATA handling is emitter-specific; use a compatible XML emitter.
- **Aliases:** None.

## `:raw`

Include text without ordinary output escaping.

- **Syntax:** a trusted output string.

```json
{
  ":raw": "<strong>Hello</strong>"
}
```

- **Result:** HTML output `<strong>Hello</strong>`.
- **Rules:** Use only trusted content intended for the destination. Core does not evaluate it as code, but an output platform may interpret emitted markup.
- **Aliases:** None.

## Literal data

```json
{
  "Widget": {
    "@": {
      "payload": { ":type:object": { ":ref": "some-data-id" } }
    }
  }
}
```

- The `payload` is data. Its `":ref"` key is not a reference expression.
- Typed object/array contents stay opaque, including when a reference session is enabled.
- Source saving retains object/array wrappers so reloading does not turn data into commands.
- Type commands may convert input, but they are not schema validators. Use values already appropriate to the requested type for portability.
- A top-level `#` key in a typed command body is reserved payload syntax. An object with its own top-level `#` data key is not a reliable portable source round trip. Keep that key below an ordinary data member or verify a host-owned encoding strategy.
