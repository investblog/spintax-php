# Changelog

All notable changes to `spintax/core` are documented here. This project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions are published to Packagist from git tags — `composer.json` deliberately carries
no `version` field, so a release is cut by tagging (`v0.2.0`), not by editing the manifest.

## Unreleased

`#set` goes back to being a macro, and `#def` carries roll-once. Breaking: it changes what
existing templates mean. Ships in lockstep with the WordPress plugin 3.0.0, `@spintax/core`
0.3.0 and the OpenCart port.

### Changed

- **`#set` is a macro again.** The value is substituted at every `%var%` reference and whatever
  brackets it holds resolve independently each time. 0.2.0 collapsed an enumeration-valued `#set`
  once at set-time; that behaviour is gone.

  It was worth reverting because it was the newcomer, not the contract. It shipped 2026-07-04,
  was announced in one changelog line, and contradicted the project's published documentation from
  the day it landed — spintax.net has a whole section teaching independent re-rolling as a design
  rule, in fourteen locales. Macro expansion is what the engine did before that, and what every
  consumer written against those docs assumes. Note also that **no test anywhere in this package
  pinned the collapsed behaviour**: it could have flipped in either direction unnoticed, which is
  why the new semantics arrives with tests and corpus fixtures rather than a changelog entry.

- **The bracket type no longer decides anything.** Previously `{…}` in a `#set` collapsed while
  `[…]` re-rolled, because the guard only looked for `{`. That asymmetry was documented nowhere.
  Now `#set` re-rolls both and `#def` freezes both.

- **The validator and the parser share one grammar** — `Parser::DIRECTIVE_PATTERN`. They had
  disagreed: the parser accepted `#set %x% =` as an empty value, which is a supported case, while
  the validator reported it as malformed unless the author happened to leave a trailing space.

### Fixed

- **A host construct inside a variable value is no longer destroyed.** Constructs matched by the
  `$protect` patterns were shielded once, before the body was processed, so any that arrived later —
  carried in by a `#set`, a global, a runtime variable or a frozen `#def` — reached the permutation
  resolver unprotected. `[shortcode id="1"]` reads as a single-element permutation, so the brackets
  were stripped and the construct reached the stage 9 hook as inert text. There is now a second
  shield pass after variable expansion, and definition values are shielded for the length of their
  roll.

  This predates `#def` — `#set` and globals lost constructs the same way — and it is a host-seam
  concern, so no golden-corpus fixture can cover it: `$protect` is empty in a host-free run. Each
  engine has to carry its own regression test. For the WordPress plugin the construct in question is
  `[spintax …]`, and note that this widens nothing security-wise: data-derived (T2) values are
  entity-encoded by `SpintaxShield` before they ever reach the engine, so a variable holding a
  shortcode can only come from a markup-authoring (T1) source that was already trusted to write one.

### Added

- **`#def %var% = value` — roll-once.** The value is rendered once, as if it were a miniature
  template, and the result is held for every reference. It covers enumerations *and* permutations,
  resolves in dependency order so a `#def` built from another `#def` sees the frozen text, and runs
  **after** the variable context is assembled, so it can read globals and runtime variables. A
  runtime variable of the same name still outranks it.

  This is where a plural counter now lives: `#def %n% = {1|4|9}` followed by
  `{plural %n%: …}` prints and agrees the same number. Under `#set` the two disagree — the count
  slot still holds `{1|4|9}` when the plural pass runs — and that is the accepted consequence of a
  macro, pinned by a test and reported by the forthcoming `plural.count-macro` diagnostic.

- **`Parser::extract_directives()`** returns body, `set`, `def`, and an `occurrences` list that
  preserves every directive line with its number. The maps flatten duplicates; a validator cannot
  report a collision it can no longer see. `extract_set_directives()` remains, `#set`-only.

- **Validator diagnostics for the new directive.** `#def` is now recognised everywhere `#set` was:
  malformed-directive reporting, the definitions map behind self-reference and cycle detection, and
  the known-names set (a `#def`-defined name used to warn "may be a runtime variable" at every
  reference). Plus three new checks:
  - **a name defined more than once** is an error, by either directive and in any combination. Two
    `#set` lines sharing a name were silently last-wins before this;
  - **`#include` inside a `#def` value** is an error — includes resolve after the value is frozen.
    Inside a `#set` it is fine, because a macro is substituted verbatim and the include reaches the
    include stage in the body;
  - **a macro in a plural count slot** is an error: the count is still unresolved spintax when the
    plural is decided, so the block renders empty. Taint propagates to a fixed point through `#set`
    chains, so `#set %m% = {1|4|9}` / `#set %n% = %m%` / `{plural %n%: …}` is caught even though
    `%n%` holds no bracket. Known limit: the validator receives global variable *names*, never their
    values, so taint cannot cross a global.

### Changed (advice)

- **"Extract via `#set` first" became wrong and is now "extract via `#def` first."** The advice
  appears in `PluralFormError`, in the `Plurals` docblock and in the runtime message a broken form
  slot raises. Under collapse-once a `#set` froze to literal text and the advice worked; under a
  macro the value is substituted verbatim and puts the brackets straight back into the form,
  raising the very error it was meant to avoid. Verified both ways before rewording.

### Migration

A `#set` whose value is an enumeration or permutation *and* which is referenced more than once for
consistency — a plural counter, a brand name that must not vary mid-sentence — becomes `#def`. One
line per definition; references are untouched.

## 0.2.0 — 2026-07-18

Serbian, Croatian and Bosnian join the 3-form plural family. Minor, not patch: this changes
which templates the engine accepts.

### Added

- **BCS plural buckets — `sr`, `hr`, `bs`.** On integers, BCS shares the East-Slavic rule
  character for character (`mod10===1 && mod100!==11` → one; `mod10∈[2,4] && mod100∉[12,14]`
  → few; else → many), so it reuses that branch rather than getting its own. CLDR names the
  third bucket `other` rather than `many` — positionally the same slot. The genuine
  BCS/East-Slavic divergence is fractional-only and unreachable here: a non-numeric count
  slot is erased before the bucket math runs.

  Script and region subtags carry no plural grammar, so `sr-Latn`, `sr-Cyrl`, `sr_RS` and
  `sr-Latn-RS` all normalise to `sr` and pick identical buckets. The script lives only in the
  author's form text.

### Changed

- **BREAKING for `sr` / `hr` / `bs` only: `{plural}` now requires three forms.** These locales
  previously fell through to the EN-style 2-form default, so `{plural 3: kolačić|kolačići}`
  was accepted and rendered from the wrong bucket set. It is now a `PluralArityError`.

  **The production path does not throw.** Callers that pass `lenient => true` — which the
  engine documents as the production mode — get the block emitted verbatim in fullwidth
  braces (`｛plural 3: kolačić|kolačići｝`), i.e. into live output. Audit existing BCS
  templates for `{plural` and add the third form before upgrading. No other locale changes
  behaviour.

### Tests

- New `tests/PluralsTest.php` covers what the shared golden corpus cannot express: the
  corpus has no vocabulary for a thrown exception, and `@spintax/core` has no strict mode to
  throw from, so the strict/lenient error model is per-engine by construction. Bucket math
  itself stays in the corpus, which carries the full `sr`/`hr`/`bs` ladder including the
  three-digit boundaries (101 is `one`, 111 is not).

The same rule ships in `@spintax/core` 0.2.0 and the WordPress plugin 2.5.0; all three
engines are gated by the shared cross-engine corpus.
