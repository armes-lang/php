# Agent Instructions

ARMES is a spec-first, language-agnostic tree processor. Treat PHP as the first implementation, not the whole product.

Core rules:

- Source formats normalize into the canonical ARMES Tree.
- Normal object keys are element/component names.
- Runtime keys beginning with `:` are processing nodes and must not render literally.
- `@` means props.
- `#` means children.
- Primitive values are typed by inference.
- Explicit typed nodes override inference.
- `:props` and `:attributes` patch only the direct parent element.
- Logic is data-based through `:expr`; do not add raw eval to core behavior.
- `:php`, `:js`, `:ts`, and `:code` are inert payload concepts unless a future explicit plugin handles them.
- Fixtures should stay portable for future TypeScript/JavaScript implementations.
- Strict mode is the default correctness target.

Before changing behavior:

1. Read `README.md`, `SPEC.md`, `ARCHITECTURE.md`, `composer.json`, `fixtures/`, `tests/`, and the relevant source.
2. Add or update fixtures when syntax changes.
3. Add or update PHPUnit coverage.
4. Run `./vendor/bin/phpunit --configuration phpunit.xml`.
5. Run `php benchmarks/core-benchmark.php` for performance-sensitive changes.
6. Run `php benchmarks/markup-benchmark.php` for markup parser or HTML emitter changes.
7. Update docs when behavior changes.

Never silently ignore unresolved runtime nodes in strict mode.
Never add unsafe code execution by default.

## Custom mapping is core-level, not React-only

ARMES supports target-aware custom mapping.

React component mapping is only one example. The core architecture should allow custom mapping for different render targets such as HTML, JSX, XML, and future targets.

Rules:
- Armes should provide convenient default methods like `renderHtml()` and `renderJsx()`.
- Default render methods should use configurable render targets internally.
- Developers should be able to replace or configure target mappers.
- ReactMapper may support component mapping and import generation.
- HtmlMapper may support element/tag mapping.
- Emitters serialize mapped output; they should not parse ARMES source or resolve runtime nodes.
- Runtime nodes beginning with `:` should be resolved before final mapping.
- Headless UI is an example only, never a hardcoded dependency.