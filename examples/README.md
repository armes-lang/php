# ARMES Examples

Each scenario has a source file and a mapper script. Run scripts from the repo root:

```bash
php examples/00-chaotic-markup-roundtrip.mapper.php
php examples/01-basic-html.mapper.php
php examples/02-react-jsx.mapper.php
php examples/03-runtime-dashboard.mapper.php
php examples/04-imports.mapper.php
php examples/05-markup-roundtrip.mapper.php
php examples/06-big-html.mapper.php
php examples/07-xml-roundtrip.mapper.php
php examples/08-yaml-runtime.mapper.php
php examples/09-toml-storage.mapper.php
php examples/10-pkl-runtime.mapper.php
php examples/11-custom-render-targets.mapper.php
```

## Scenarios

- `00-chaotic-markup-roundtrip.*`: intentionally chaotic HTML. Unsupported runtime-style tag names now report the original source file and line.
- `01-basic-html.*`: JSON tag-key source mapped to HTML plus compact/canonical JSON.
- `02-react-jsx.*`: JSON source mapped through the React/JSX pipeline.
- `03-runtime-dashboard.*`: scoped `:logic:*`/`:type:*`, `:if`, and `:each` with context data.
- `04-imports.*`: inline `:import` with props and slot children.
- `05-markup-roundtrip.*`: HTML source parsed to ARMES compact JSON and emitted back to HTML.
- `06-big-html.mapper.php`: parses `examples/big-html.html`, saves compact JSON and roundtrip HTML, and checks structural equality.
- `07-xml-roundtrip.*`: XML source parsed through DOM and emitted with XML tag behavior.
- `08-yaml-runtime.*`: YAML tag-key source with scoped runtime logic rendered to HTML and YAML.
- `09-toml-storage.*`: TOML table source used as compact storage and rendered to HTML/TOML.
- `10-pkl-runtime.*`: Pkl module parsed through the Pkl CLI, resolved with context, and rendered back to Pkl.
- `11-custom-render-targets.*`: target-aware custom HTML and JSX mapping for the same ARMES source.

Generated files are written to `examples/output/` and ignored by git. When examples are opened through a local web server that cannot write to that directory, saving is skipped and the example still prints its result.
