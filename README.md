# spintax/core

[![CI](https://github.com/investblog/spintax-php/actions/workflows/ci.yml/badge.svg)](https://github.com/investblog/spintax-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/spintax/core)](https://packagist.org/packages/spintax/core)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A framework-agnostic **spintax engine** for PHP. Zero dependencies, PHP 8.0+.

This is the engine behind the [Spintax WordPress plugin](https://wordpress.org/plugins/spintax/) and
[`@spintax/core`](https://www.npmjs.com/package/@spintax/core) on npm — extracted, so it can be used
from any PHP application.

```php
use Spintax\Core\Render\Pipeline;

echo (new Pipeline())->render(
    'Hola %name%, {tenemos|traemos} [<, > ofertas|novedades|precios] para ti. ' .
    '{plural 3: producto|productos}.',
    ['name' => 'Ana'],
    locale: 'es_ES',
);

// Every render is a different variant of the same message:
//   Hola Ana, tenemos ofertas, novedades, precios para ti. Productos.
//   Hola Ana, traemos novedades, precios, ofertas para ti. Productos.
```

## Why another one

Most PHP spintax libraries parse `{a|b|c}` and stop there. That is a *replacement* primitive, and it
is enough only until your copy needs to agree with itself:

| | `{a\|b\|c}` | `[…]` permutations | `%vars%` | conditionals | plural agreement | `#include` | prose clean-up |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| typical spintax libs | ✅ | — | — | — | — | — | — |
| **`spintax/core`** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Plural agreement is the one people discover late. `%n% {товар|товара|товаров}` picks a form at
random — which is wrong for 1, wrong for 3, and wrong for 5. Russian, Ukrainian and Serbian content
cannot be generated correctly without a number-gated form; this engine has one.

Three-form locales are `ru`, `uk`, `be`, `sr`, `hr`, `bs`. Everything else takes the EN-style
2-form rule — including `pl`, `cs`, `sk`, `sl` and `bg`, whose real rules differ and which are
therefore **not** yet supported: they are accepted silently and bucketed wrongly, not rejected.

## Install

```sh
composer require spintax/core
```

## Syntax

| construct | meaning |
| --- | --- |
| `{a\|b\|c}` | **enumeration** — pick one. Nests: `{a\|{b\|c}}` |
| `[a\|b\|c]` | **permutation** — pick N, shuffle, join |
| `[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a\|b\|c]` | configured permutation |
| `%var%` | variable reference (case-insensitive) |
| `#set %var% = value` | local variable; enumerations inside collapse **once**, so every reference sees the same value |
| `{?VAR?then\|else}` | conditional — `{?!VAR?…}` inverts it |
| `{plural <count>: one\|few\|many}` | plural agreement by grammatical bucket (RU/UK/BE + SR/HR/BS 3-form, EN-style 2-form) |
| `#include "name"` | embed another template |
| `/# … #/` | comment, stripped from the output |

Full reference with a live playground: **[spintax.net/docs/syntax](https://spintax.net/docs/syntax)**.

## Includes

Fetching a template is I/O, so it belongs to your application. Everything *around* the fetch —
recursion, cycle detection, depth and fan-out budgets, and scope isolation — stays in the engine, so
a naive host cannot hang itself:

```php
$pipeline = new Pipeline(
    globals: ['brand' => 'Acme'],
    source: fn (string $name): ?string => $repository->rawTemplate($name),
);

echo $pipeline->render("Intro.\n#include \"footer\"");
```

A nested template inherits globals and runtime variables, but never the parent's `#set` locals — it
defines its own. A circular include resolves to nothing rather than looping.

## Validation

```php
use Spintax\Core\Engine\Validator;

$result = (new Validator())->validate($template);
// $result['errors'] — line/column diagnostics: unbalanced brackets, bad plural arity, unknown includes…
```

## Deterministic output

Inject the RNG and a render becomes reproducible — which is what makes the test suite below possible:

```php
use Spintax\Core\Engine\Parser;

$always_first = new Parser(fn (int $min, int $max): int => $min);
$pipeline     = new Pipeline($always_first);
```

## The parity contract

The same spintax syntax is implemented three times: here, in
[`@spintax/core`](https://github.com/investblog/spintax-js) (TypeScript), and in the
[WordPress plugin](https://github.com/investblog/spintax). They are held together by a **shared
golden corpus** — one set of JSON fixtures that every engine must reproduce.

This package's test suite *is* that corpus, run against the shipped `Pipeline`. Not a replica of it,
not a subset: the same file the TypeScript engine is tested against. If a fixture goes red here, the
engines have diverged, and that is the point.

```sh
git clone https://github.com/investblog/spintax-js ../spintax-js
SPINTAX_FIXTURES=../spintax-js/packages/conformance/fixtures vendor/bin/phpunit
```

## Deliberately not here

Caching, template storage, settings, and **output sanitisation**. `render()` returns pre-sanitise
text; a host emitting HTML must run its own sanitiser over it (the WordPress plugin applies
`wp_kses_post()`). Those are host concerns, and an engine that guesses at them is an engine you have
to fight.

## Also available

- **WordPress:** [Spintax](https://wordpress.org/plugins/spintax/) — templates, ACF / post-meta bindings, WooCommerce product context, WP-CLI.
- **JavaScript / TypeScript:** [`@spintax/core`](https://www.npmjs.com/package/@spintax/core).
- **OpenCart 3.x:** [Spintax SEO](https://github.com/investblog/spintax-opencart).
- **Docs & playground:** [spintax.net](https://spintax.net).

## License

MIT © [301st](https://301.st)
