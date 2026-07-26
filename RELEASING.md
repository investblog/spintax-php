# Releasing `spintax/core`

Packagist publishes from **git tags** — `composer.json` deliberately carries no `version`
field, so a release is cut by tagging, never by editing the manifest. There is no upload
step anywhere: Packagist pulls the repository and lists every `vX.Y.Z` tag it finds.

Two consequences worth internalizing before the first tag:

- **A tag is the release.** Whatever commit the tag points at is what Composer installs,
  bit for bit. Nothing rebuilds, nothing repackages.
- **A tag can point at any commit — including one CI never saw green.** The `Release`
  workflow (`.github/workflows/release.yml`) exists for exactly this: pushing a `v*` tag
  re-runs the full gate (PHP 8.0–8.4 × the shared golden corpus) against the tagged
  commit, then publishes a GitHub release from the tag annotation. A red verify does not
  un-publish the tag from Packagist — so tag only a commit whose CI you have already seen
  green; the tag-time run is the backstop, not the gate.

## Preconditions

1. `main` is green in CI — that workflow runs this suite on PHP 8.0–8.4 against the
   golden corpus checked out from `investblog/spintax-js` at `main`.
2. `CHANGELOG.md` has an `## Unreleased` section describing everything since the last
   tag. Behaviour changes that remove something a host may rely on (an exception, a
   verdict) go under **Changed**, not **Fixed**.
3. If the release mirrors a cross-engine fix, the corpus fixtures for it are already on
   `spintax-js@main` — this repo's CI pulls them from there, so a fixture that lands
   *after* the tag is a fixture the tagged artifact was never tested against.

## Versioning

While 0.x: a change that moves **verdicts or rendered output** for any input is a
**minor** (the `0.2.0` precedent — sr/hr/bs plural arity turned existing templates
invalid); everything else is a patch. The parity contract itself (syntax surface,
verdict set) is versioned by the family spec, not by this package alone.

## Cutting a release

```sh
# 1. Finalize the changelog: ## Unreleased  ->  ## X.Y.Z — YYYY-MM-DD (+ intro line)

# 2. Release commit (convention: "release: spintax/core X.Y.Z")
git add CHANGELOG.md && git commit -m "release: spintax/core X.Y.Z"
git push origin main

# 3. Wait for CI on that exact commit to go green. THEN tag it — annotated, and the
#    annotation IS the release note (subject + body become the GitHub release).
git tag -a vX.Y.Z -m "spintax/core X.Y.Z — one-line headline

A few sentences of body: what changed, what it was measured against, corpus numbers."

# 4. Push the tag — this triggers the Release workflow (verify + announce).
git push origin vX.Y.Z
```

## After the tag

- **Packagist** updates from the GitHub webhook within a minute or two:
  `https://packagist.org/packages/spintax/core` lists the new version, or query
  `https://repo.packagist.org/p2/spintax/core.json`. If the hook ever goes missing,
  press **Update** on the package page (maintainer login) or re-add the GitHub hook via
  Packagist profile → *Show API Token* → repository webhook.
- **GitHub release** appears automatically from the tag annotation (the `announce` job).
- Verify what an installer sees:

  ```sh
  composer show spintax/core --all | head        # in any project, or:
  curl -s https://repo.packagist.org/p2/spintax/core.json | grep -o '"version":"[^"]*"'
  ```

## Un-shipping a mistake

Packagist mirrors tags, so deleting a pushed tag removes the version on the next sync —
but anyone who already installed it keeps it, and `composer.lock` files keep resolving
to it from cache. Prefer shipping a fixed `X.Y.(Z+1)` over deleting `X.Y.Z`.
