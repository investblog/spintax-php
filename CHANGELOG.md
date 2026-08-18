# Changelog

All notable changes to `spintax/core` are documented here. This project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versions are published to Packagist from git tags — `composer.json` deliberately carries
no `version` field, so a release is cut by tagging (`v0.2.0`), not by editing the manifest.

## 0.8.0 — 2026-08-18

**Validation now emits one circular-reference error per NAME that takes part in, or leads to, a
cycle** — it used to emit one per PATH (spintax-js#59). Minor rather than patch: diagnostic output
visibly changes, though no verdict moves.

### Fixed

The number of routes into a cycle is exponential in the depth of a converging diamond of
definitions, so 457 bytes produced **524 288 errors in 1.45 s** here; the JS engine measured
2 097 152 from 507 bytes and its reference deployment answered HTTP 503. Now: 20 errors, immeasurably
fast, and a diamond of depth 200 stays linear.

The same issue's second half: one cycle of N names printed an N-name route in each of N messages —
20 KB of one giant cycle carried **8.7 MB of message text**, now 121 KB. The route is capped at
eight names and becomes a count past that. Real cycles read exactly as they did: the colour walk
that already existed as a prune now records one witness edge per name, and following those edges
reproduces the old routes.

### Notes

This reverses a decision `tests/ValidatorCyclesTest.php` pinned on purpose — its header said so —
so the tests that pinned the old shape were rewritten in place rather than deleted. `spintax-core`
(Python) already emitted per name and was the one engine immune; its counts are the reference, and
400 generated definition graphs now agree across all three engines.

## 0.7.3 — 2026-08-18

The expansion budget added in 0.7.2 was local to `expand_variables()`, and an `#include` is
expanded by its own call — so every include had a fresh allowance. Bounded here by
`MAX_INCLUDES` rather than unbounded as it was in the JS engine, but the shape was the same
mistake.

### Fixed

`Parser::expand_variables()` takes an optional shared counter by reference, and `Pipeline`
opens one per render next to the include budget, for the same reason and at the same moment.
Measured after: 100 includes over one 62-character bomb produce 0.57 MB, flat — the figure one
include produces. A standalone caller that passes nothing keeps the per-call allowance.

## 0.7.2 — 2026-08-18

**Rendering no longer dies on a 62-character template** (spintax-js#69). Not a regression — every
released version of every engine in the family did this.

### Fixed

```
#set %a% = %b% %b%
#set %b% = %a% %a%
%a%
```

`Parser::expand_variables` replaces every reference each pass, so the text doubles and 51 passes
is 2^51: `Allowed memory size exhausted`. Acyclic doubling definitions do it too, so the cycle
guard never fires. Any reference to such a value reaches it; defining the pair is harmless, and
so is a conditional testing one, because neither expands the value.

A render may now expand at most 1 MB of `%variable%` text. Past that a reference is left literal
— exactly what an undefined name already does, so no new output shape appears and rendering
stays lenient.

### Notes

What a truncated explosion looks like is deliberately not parity-gated: this engine expands by a
whole-text fixpoint while the JS and Python engines walk per reference, so they stop in different
places on the same bomb. The contract is that rendering terminates, does not throw, bounds its
output, and leaves what it could not afford as literal `%name%` — pinned here in `PipelineTest`.

## 0.7.1 — 2026-08-18

A memory fatal in the 0.7.0 form-counting path, reachable from template text. **Upgrade from 0.7.0.**

### Fixed

- **A 62-character template could kill `validate()` with `Allowed memory size exhausted`.**

  ```
  #set %a% = %b% %b%
  #set %b% = %a% %a%
  {plural 2: one|%a%}
  ```

  Counting plural forms expands definitions, and the expansion was bounded by 51 *passes* with
  nothing bounding the *size*. That template doubles the text every pass, so 51 passes is 2^51.
  Every engine in the family died on it. A non-cyclic doubling chain does the same, so the
  circular-reference guard never got a chance.

  The expansion now stops at 64 KB and reports the count as unknowable — the same silence this
  validator already uses for any input it cannot pin down. A form list is a handful of plural
  forms; nothing legitimate approaches the ceiling.

### Notes

No verdict change for any template that was not crashing. This engine's recursive `#set`-graph
walk was left as it is: unlike the JS and Python ports it returned an answer rather than
overflowing, and it served as the reference those two were diffed against when their walks were
made iterative. Pinned by two corpus fixtures whose real assertion is that the engine answers at
all — a runner that hangs fails them.

## 0.7.0 — 2026-08-18

A verdict change, so minor: templates that were reported `plural.arity` are now valid.

### Fixed

- **Plural forms were counted before `%variable%` expansion; the renderer counts them after**
  (spintax-js#66, found by the Object Pascal port of this engine and confirmed in all five).

  `#def %tail% = few|many` with `{plural 2: one|%tail%}` under `ru` renders correctly as three
  forms, and the validator reported `plural.arity` for the two it could see in the source. The
  count now substitutes definition values first — every reference per pass, as the renderer does —
  and splits the result.

  Only where the count is **provably invariant**: a value carrying any bracket, conditionals
  included, suppresses the count-based verdicts rather than guessing. `{a|b}` really does always
  freeze to one form, but `{?flag?a|b|c}` freezes as `a` or as `b|c`, and telling those apart means
  evaluating the construct. A `#set` named directly in the form slot is the one exception: it is
  substituted verbatim and still spintax when the plural is decided, so it keeps earning
  `plural.nested-brackets`.

  This engine's **renderer needed no change** — it has always resolved conditionals before plurals,
  which is exactly what the TypeScript and Python engines were missing (fixed on their side in
  `@spintax/core` 0.5.0).

### Notes

`validate()` walks the definition graph once more per call than it strictly needs to; that is
linear and left alone here rather than folded into a verdict change.

## 0.6.0 — 2026-08-18

A new diagnostic, so minor rather than patch: validation output changes for a `{plural ...}`
block whose form count is not the render default when no locale was supplied. **No verdict
moves** — the new code is a warning, and validity still means "no errors".

### Added

- **`plural.locale-missing` — a warning where validation used to be silent.** Mirrors
  `@spintax/core` (spintax-js#65), reported from a pipeline rendering ~1000 articles per
  campaign: a three-form plural validated without a locale passed clean and then rendered as
  the fullwidth-brace fallback `｛plural …｝` into finished text, where downstream checks that
  scan for `{`/`}` never see it.

  Half of the asymmetry was deliberate and stays: **no locale means no arity verdict**, because
  the template may well be correct for the locale the host renders with, and failing it here
  would fail a good template for a fact the caller never claimed. Rendering has no such luxury —
  it resolves against `Plurals::DEFAULT_ARITY` whatever the caller said — so the warning covers
  exactly that gap. It fires only when no locale normalizes AND the form count is not the
  default; a two-form block stays silent.

  `check_plurals()` now returns `array{errors, warnings}` like `check_variable_references()`;
  the single call site merges both. `Plurals::DEFAULT_ARITY` is a named constant so the
  validator's claim and the renderer's behaviour cannot drift apart — that drift was the bug.

  Worth knowing about the gate: the shared corpus cannot police this here. Its PHP runner
  asserts the VERDICT only, because these diagnostics carry human messages rather than machine
  codes, and a warning does not move a verdict. Both the warning and its ABSENCE on a two-form
  block are pinned in `PluralsTest` instead.

## 0.5.2 — 2026-08-07

The last of the from-offset-0 line counts. No output changes: the 464-document differential
is byte-identical before and after, and freezing the cursor turns it red at 149 documents.

### Fixed

- **Occurrence line numbers resume instead of recounting.** Four sites computed each item's
  line with `substr_count( $text, "\n", 0, $offset )` — a fresh scan from the start of the
  text per directive occurrence (`extract_directives`, which the RENDER path calls too),
  per `#include` (`find_include_directives`), per permutation config and per plural block
  (validator). C's `memchr` hid the quadratic term behind small constants, but it was
  there: 6400 plural blocks 256 ms → 109 ms, 6400 configs 442 ms → 144 ms, 6400 duplicate
  directives 313 ms → 148 ms. All four loops walk ascending offsets, so a resuming cursor
  counts every byte once. Same cure as `@spintax/core` 0.3.3's `extractDirectives`.

## 0.5.1 — 2026-08-07

`validate()` scales. No output changes anywhere — a 464-document differential (definition
graphs, plural taint, fuzz, an options matrix) is byte-identical before and after, and two
deliberate mutations each turn it red.

### Fixed

- **`validate()` is no longer super-linear on definition graphs — and no longer hangs.** The
  circular-reference walk re-ran `preg_match_all` on every value at every visit, tested the
  path with `in_array`, copied it with `array_merge` per step, and restarted from every
  definition with no memory of silent subtrees — on a converging diamond that re-explored
  shared subtrees exponentially, so ~1.5 KB of template pinned a CPU indefinitely (a
  denial-of-service shape for any host exposing validate). Measured on PHP 8.2, same
  machine: a chain of 1600 `#set`s 15.2 s → 61 ms; a 20-level converging diamond 3.5 s →
  26 ms; 26 levels killed at 20 s → 23 ms; one cycle of 1600 killed at 20 s → 1.7 s (that
  remainder is the size of the answer — every message carries the full cycle path). The
  plural-taint fixed point became a reverse-edge worklist computing the same closure.
  References parse once, the walk is iterative with the path as a hash set, and one colour
  walk computes up front which names can reach a cycle — a subtree that cannot reach one
  cannot report, so pruning it is output-neutral by construction. Emission order, count and
  messages are exactly the recursive walk's, duplicated edges and all. Same fix as
  `@spintax/core` 0.3.3; the emission-semantics questions it surfaced are
  [spintax-js#59](https://github.com/investblog/spintax-js/issues/59).

## 0.5.0 — 2026-08-07

A one-change release, minor by this repo's rule that a verdict move is never a patch:
templates that a bare `ltrim()` charlist used to condemn are now valid, as they always
were to the reference.

### Changed

- **The malformed-directive check trims spaces and tabs only** (the reference's `/^[ \t]+/`).
  A bare `ltrim()` also eats NUL, VT and CR, so `NUL + "#set broken"` was reported
  `set.malformed` — a valid template called invalid, on a check whose split (`\n` alone)
  was already the reference's. A host that relied on the `invalid` verdict for such
  control-character corners will now see `valid` — that verdict move is why this is a
  Changed and a minor. Caught by the new golden-corpus `validate/directive-check-*`
  fixtures, which pin the trim class, the LF-only line split and the mid-line-CR
  directive survivor across all engines (corpus 234; this engine 224 tests / 259
  assertions / 1 skip, own suite 307/351/1).

## 0.4.0 — 2026-07-26

The release where the recognition rules stopped being *readings* and became the machine-checked
family contract: `#include`, the `#set`/`#def` grammar, `%var%` references and the expansion
stop are now pinned by shared golden-corpus fixtures that all three engines run, after a
differential campaign against `@spintax/core` (165 + 19 shaped cases, two 60 000-input fuzzes —
all 0-divergent at the end). Minor, not patch: verdicts move for non-ASCII names and exotic
whitespace, and one exception leaves the API.

### Changed

- **`render()` no longer throws on a circular `#set`** (investblog/spintax-js#57). A
  self- or mutually-referencing definition — invalid to every validator in the family, but
  render is contractually lenient on template content — used to escape
  `Pipeline::render()` as a `RuntimeException` from the variable-expansion depth guard. The
  guard now stops instead of throwing: expansion ends at the budget and the still-unresolved
  reference stays literal in the output, exactly as the reference and the Python engine
  behave (measured shape for shape, including the mutual cycle's odd/even parity and the
  literal-accumulating knot; the budget is 51 hops — the reference counts recursion depth
  0..50 inclusive). A host that renders without validating first now always gets text.
  Hosts that caught this exception will no longer see it — that is the aligned contract,
  and why this is a Changed, not a Fixed. Five golden-corpus fixtures pin the behaviour.

### Fixed

- **The directive grammar matches the family rule exactly** (investblog/spintax-js#56) — the
  same two dialect axes as the `#include` fix below, cutting across `#set`/`#def`, `%var%`
  references, the validator's conditional-ref collector, and the `#include`-in-a-`#def`-value
  check:
  - **Names are ASCII `[A-Za-z0-9_]` by contract, not `\w`.** Under `/u` PCRE2 turns on UCP, so
    `#set %имя% = X` was a valid, *expanding* directive here while every other engine reported a
    malformed-directive error for the same line — opposite verdicts — and `%Имя%` expanded from
    context here while staying literal text everywhere else.
  - **The `/m` anchors are spelled out as lookarounds** over the four ECMAScript line
    terminators: a directive after a bare CR, U+2028 or U+2029 now exists here too.
  - **A CRLF line's `\r` no longer leaks into the value.** PCRE's `.` excludes only `\n` and its
    `$` sits before `\n`, so `#set %a% = X` followed by CRLF captured the value `"X\r"` — every
    value on a Windows-authored template carried a trailing CR. The value class now excludes all
    four terminators and the tail carries the reference's explicit `\r?`.
  - The validator's `#include\b` (Unicode `\b` under UCP) missed `#includeя` in a `#def` value;
    it is now the ASCII lookahead `#include(?![A-Za-z0-9_])`, and the conditional-ref collector's
    name tail is ASCII, mirroring `Conditionals::NAME_RE`.

  Measured against `@spintax/core` on a 19-case shaped probe (13 divergent before, 0 after) and a
  60 000-input directive-shaped differential fuzz over sets, defs and full pipeline render
  (0 divergences; 126 inputs hit the circular-expansion guard, a separate policy question filed
  upstream). Templates whose directives use ASCII names and ordinary line endings are unaffected.

- **`#include` recognition matches the family rule exactly** (investblog/spintax-js#55). The
  directive was matched with `/^[ \t]*#include\s+"([^"]+)"\s*$/mu`, and under the `u` modifier
  PCRE2 turns on UCP: that `\s` matched U+0085, U+00A0 and all of `\p{Z}`, so
  `#include<NBSP>"x"` was an include here and plain text to every other engine in the family —
  and since `include.unknown-target` is an error, the widened class moved *verdicts*. The `/m`
  anchors leaned the other way: PCRE breaks lines on `\n` alone, the reference also on `\r`,
  U+2028 and U+2029, so an `#include` after a bare CR was plain text here and an include there.
  Both halves are now spelled out in `Parser::INCLUDE_PATTERN` — the gap is the six ASCII
  whitespace characters, the anchors are the four ECMAScript line terminators. Measured against
  `@spintax/core` over a 33-character whitespace/terminator alphabet (165 shaped cases) and
  60 000 include-shaped differential inputs: zero divergences; the previous pattern diverged on
  4 241 of them. The golden corpus now pins the rule (`extract/include-*`), so no port decides
  it by reading again. Templates whose includes use ordinary spaces, tabs or newlines are
  unaffected.

## 0.3.1 — 2026-07-23

Post-process parity with `@spintax/core` 0.3.2: no leaked U+0000 sentinel, and a linear placeholder
restore. Reimplemented from the shared behaviour contract and the golden corpus, not transcribed.

### Fixed

- **`post_process()` no longer emits its own U+0000 sentinel into output**, on input carrying
  none. A URI body runs to the first delimiter, so the URL rule and the `mailto:`/`tel:` rule
  overlap whenever one URI carries the other's scheme. Shielding them in two passes let the second
  run into a placeholder the first had minted:
  `mailto:sales@example.com?body=see%20https://shop.example.com/cart` shielded the URL first, then
  stored a `mailto:` value with `URL_0`'s key inside it. The restore is past that key by the time
  the value lands, so the engine returned a literal `\x00URL_0\x00`.

  That output is invalid text — illegal in XML, U+FFFD to an HTML parser, rejected by Postgres
  `text` — and the token becomes a live key again as soon as an edit detaches it from the prefix
  that was shielding it, so a later render substitutes an unrelated URL into a contact link.

  Both schemes now shield in ONE alternation, so the leftmost match takes the whole token.
  Reordering the two passes is not equivalent and was rejected upstream after measuring: it only
  moves the damage onto a URL whose path carries a `mailto:`. NUL also leaves the URI body class,
  for a caller-supplied one. Mirrors `@spintax/core` (investblog/spintax-js#53); the golden corpus
  gates it with three fixtures, the third a negative case that fails under the ordering fix and
  passes under this one.

- **The placeholder restore is linear.** Both shields — the post-process one (`\x00URL_0\x00` and
  friends) and the pipeline's host-construct one (`\x00HOST_0\x00`) — put their placeholders back
  with one `str_replace()` over arrays. That call is sequential: every occurrence of the first key
  throughout the text, then the second, and so on. Every shieldable construct mints a key, so on
  shield-heavy text the key count grows with the input and the cost is O(text x keys).

  Measured on prose carrying every shieldable construct (URL, mailto/tel, email, domain, decimal,
  abbreviation), repeated to size:

  | input | `post_process()` | `Pipeline::render()`, post-process off |
  |-------|------------------|----------------------------------------|
  | 14 KB | 0.013 s → 0.002 s | 0.003 s → 0.001 s |
  | 59 KB | 0.176 s → 0.007 s | 0.046 s → 0.002 s |
  | 237 KB | 2.702 s → 0.027 s | 0.538 s → 0.013 s |
  | 950 KB | 34.4 s → 0.101 s | 9.588 s → 0.045 s |

  Four times the input now costs about four times the work, where it used to cost seven to ten.
  On a 950 KB render the restore alone was 23.9 s of the 34.4 s; every other pass in the stage
  together came to 0.065 s, so the restore was the whole of the quadratic term.

  `strtr()` with the map is the same restore in one left-to-right pass. It is NOT a drop-in for
  the general case, and the code says so: a sequential replace can rewrite text an earlier
  replacement produced, and a NUL the caller supplied can pair with a real placeholder's delimiter
  into a key that was never minted. Both stages therefore keep the sequential restore whenever a
  NUL reaches the working text from outside the shield — for the pipeline that includes one
  arriving through a `#set`, a global, a runtime variable or a frozen `#def`, since expansion is
  the one place new text enters after the first shield.

- **Behaviour change in one corner, and it is deliberate.** Placeholder delimiters are not owned by
  the token that placed them: between two adjacent shielded constructs, ordinary caller text can
  spell a key the shield really minted, and the sequential restore substitutes it — destroying both
  real tokens. `https://a.io e.g. URL_0mailto:x@y.io` used to render as
  `https://a.io \x00ABBR_2https://a.ioURI_1` and now renders unchanged. **No NUL in the input is
  required for this**, so the NUL guard above does not cover it and the engine has to choose. It
  restores as `@spintax/core` does — the reference takes the single pass here too — which moves
  this package onto the reference's answer rather than away from it. The golden corpus now pins this
  shape too (`postprocess/adjacent-placeholders-around-a-key-name`, investblog/spintax-js#54), so
  the family is gated on it rather than split; `tests/RestoreParityTest.php` covers both directions.

- **A stale comment.** Step 12 was labelled "reverse order for safety". `array_keys()` preserves
  insertion order and nothing was ever reversed — and since replacement order is exactly what makes
  this stage observable, the comment was worse than noise. Insertion order is the contract, and the
  code now says that.

## 0.3.0 — 2026-07-19

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
  macro, pinned by a test and reported by the validator (see below).

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
