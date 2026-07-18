# Changelog

All notable changes to `spintax/core` are documented here. This project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions are published to Packagist from git tags — `composer.json` deliberately carries
no `version` field, so a release is cut by tagging (`v0.2.0`), not by editing the manifest.

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
