# Contributing

## Setup

```bash
composer install
```

Until `chatflowphp/automata` 2.x is on Packagist, point Composer at a local checkout:

```bash
composer config -g repositories.chatflowphp-automata '{"type":"path","url":"/path/to/chatflow-automata","options":{"versions":{"chatflowphp/automata":"2.0.0"}}}'
```

## Quality Gate

Run everything CI runs:

```bash
composer check
```

This validates `composer.json`, checks code style (PER-CS 2.0 through php-cs-fixer), runs PHPStan
at level max with strict rules over `src` and `tests`, and runs PHPUnit with warnings,
deprecations and notices treated as failures.

Fix style automatically with `composer cs:fix`.

## Rules

- The core never imports a platform SDK. `tests/Core/PlatformBoundaryTest.php` enforces it.
- Keep the "everything is one tick" invariant: state changes happen inside
  `Application::handle()`, effects are delivered after the tick committed.
- Keys starting with `_` in `SceneContext` are reserved for the runtime.
- Public behaviour changes need a test and a changelog entry.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`, `test:`).
