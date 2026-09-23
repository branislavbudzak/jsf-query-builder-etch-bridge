---
name: release
description: "Release a new version of JSF Query Builder Etch Bridge: bump the five version spots, CHANGELOG and readme.txt entry, commit, tag, build the whitelisted release ZIP, publish the GitHub Release with the ZIP. Run only on an explicit request (release, bump version, new version, tag)."
---

# Release

The plugin has no updater and no CI. Sites get it **only** through the release
ZIP, so the ZIP is the product. Build it with `bin/build-release-zip.sh`, never
by zipping the working copy.

Run this skill only when the user explicitly asks for a release or a version
bump. Several changes in one session go into one release.

## 1. Pre-flight

```bash
git status                     # clean, or only the changes that belong to this release
git log "$(git describe --tags --abbrev=0)"..HEAD --oneline
for t in tests/*.php; do php "$t" || echo "FAILED: $t"; done
```

The tests are plain PHP scripts with WP stubs; `tests/ajax-query-security.php`
needs an installed JetSmartFilters (`../jet-smart-filters`, or pass the path to
`includes/query.php` as the first argument). Every line must be `PASS`.

## 2. Pick the version

SemVer, see CLAUDE.md "Versioning workflow":

| Change | Bump | Commit prefix |
|---|---|---|
| bug fix, security fix | `x.y.(z+1)` | `fix:` |
| backwards-compatible feature | `x.(y+1).0` | `feat:` |
| breaking change | `(x+1).0.0` | document the migration in CHANGELOG and readme.txt |

## 3. Bump all five spots in ONE commit

1. `jsf-query-builder-etch-bridge.php` header ` * Version:`
2. `define( 'JQBEB_VERSION', ... )` in the same file (also busts the JS cache
   and the loopback response cache key)
3. `readme.txt` `Stable tag:`
4. `readme.txt` `== Changelog ==` entry `= x.y.z =`
5. `CHANGELOG.md` entry `## x.y.z - YYYY-MM-DD` (Keep a Changelog sections:
   Security / Added / Changed / Fixed / Verification)

`bin/build-release-zip.sh` refuses to build when any of them disagree.

Commit message pattern used in this repo:

```
fix: <what changed, user-facing> (x.y.z)
```

## 4. Tag and push

```bash
git tag vX.Y.Z
git push origin main vX.Y.Z
```

If the work lives on a branch, merge it first and tag the merge commit on
`main`, so the tag, the ZIP and the GitHub Release point at the same commit.

## 5. Build the ZIP from the tag

```bash
bin/build-release-zip.sh --ref vX.Y.Z      # → ~/Desktop/jsf-query-builder-etch-bridge-X.Y.Z.zip
```

The package is a **whitelist**: `jsf-query-builder-etch-bridge.php`,
`readme.txt`, `includes/`, `assets/`. Nothing else ships: no `tests/` (the test
scripts define their own `ABSPATH` and would be executable by URL under
`/wp-content/plugins/`), no `*.md`, no `bin/`, no agent or editor files.
A new runtime directory has to be added to `WHITELIST` in the script on
purpose.

The script also runs `php -l` on the lowest supported PHP (`Requires PHP`
header, in Docker) and prints the file list and SHA-256. A ZIP named
`...-X.Y.Z-<sha>.zip` means the ref was not exactly the `vX.Y.Z` tag; do not
publish that one.

## 6. GitHub Release

```bash
awk -v v="X.Y.Z" '$0 ~ "^## " v " " {f=1; next} f && /^## / {exit} f' CHANGELOG.md > /tmp/jqbeb-notes.md
gh release create vX.Y.Z ~/Desktop/jsf-query-builder-etch-bridge-X.Y.Z.zip \
  --title "X.Y.Z - <short title>" --notes-file /tmp/jqbeb-notes.md
```

To replace the ZIP on an existing release: `gh release upload vX.Y.Z <zip> --clobber`.

Verify: `gh release view vX.Y.Z --json assets -q '.assets[].name'` lists exactly
one `jsf-query-builder-etch-bridge-X.Y.Z.zip`.

## 7. Deploy

Deployment is per site and not part of this repo (it is public, so no server
paths here).

- Generic WordPress site: Plugins → Add New → Upload the ZIP → "Replace
  current with uploaded".
- Nearcharger: the private `nearcharger-core-logic` repo has
  `bin/deploy-bridge.sh` and the skill `deploy-bridge` (backup, rsync, OPcache
  invalidation, smoke test, rollback, compatibility with core-logic). Staging
  first, production only on explicit request.

After a JSF-related release, re-check on a real page: a filter, a range
slider, sorting, search, page 2 and the per-option counters, all via AJAX.
The stubbed tests do not exercise Etch rendering or the loopback path.
