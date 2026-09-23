# Changelog

## Unreleased

- Default and `--export` paths now use `output_directory` from the config
- `--export=png` is accepted
- `--format` other than `table`/`json` is rejected
- Quotes and backslashes in names no longer break the Graphviz output
- Plain `$var->method()` calls are no longer listed as services
- Models are no longer listed twice
- Dev dependencies updated for Laravel 12 (Testbench 10, PHPUnit 11)

## 1.0.0 - 2024-08-17

First release: `flow:trace` for routes, URLs and controller actions, table and JSON output, PNG/SVG/DOT/Mermaid export via Graphviz.
