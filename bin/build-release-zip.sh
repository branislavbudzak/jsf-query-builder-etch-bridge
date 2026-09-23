#!/usr/bin/env bash
#
# Builds the release ZIP of JSF Query Builder Etch Bridge from a git ref.
#
# The package is a WHITELIST, not an exclude list: only what WordPress loads
# at runtime goes in. Anything new in the repo (tests, notes, agent files)
# stays out until it is added here on purpose. Everything under
# /wp-content/plugins/ is publicly readable, and a PHP file there is publicly
# executable, so a stray test script is a real exposure, not clutter.
#
# Usage:
#   bin/build-release-zip.sh [--ref <git ref>] [--out <dir>]
#
#   --ref  what to package, default HEAD (uncommitted changes are NOT packaged)
#   --out  where to write the ZIP, default ~/Desktop
#
# Output: <out>/jsf-query-builder-etch-bridge-<version>.zip, or
#         ...-<version>-<sha>.zip when the ref is not exactly the v<version> tag.
#
# Checks (any failure aborts):
#   - header Version, JQBEB_VERSION, readme.txt Stable tag and the top
#     CHANGELOG.md entry all carry the same version
#   - php -l on the lowest supported PHP (Requires PHP header) for every file,
#     in Docker when available, otherwise with the local php binary
#   - nothing outside the whitelist ended up in the package

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="jsf-query-builder-etch-bridge"
REF="HEAD"
OUT="${HOME}/Desktop"

# Runtime files. Directories are copied recursively.
WHITELIST=(
	"${SLUG}.php"
	"readme.txt"
	"includes"
	"assets"
)

while [ $# -gt 0 ]; do
	case "$1" in
		--ref) REF="$2"; shift 2 ;;
		--out) OUT="$2"; shift 2 ;;
		-h|--help) sed -n '2,27p' "$0"; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; exit 1 ;;
	esac
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/jqbeb-release.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/src" "$WORK/pkg/${SLUG}"

SHA="$(git -C "$REPO" rev-parse --short "$REF")"
git -C "$REPO" archive "$REF" | tar -x -C "$WORK/src"
SRC="$WORK/src"

# --- Versions -------------------------------------------------------------
HEADER="$(sed -nE 's/^ \* Version: *([0-9.]+).*/\1/p' "$SRC/${SLUG}.php" | head -1)"
CONST="$(sed -nE "s/.*define\( *'JQBEB_VERSION', *'([^']+)' *\).*/\1/p" "$SRC/${SLUG}.php" | head -1)"
STABLE="$(sed -nE 's/^Stable tag: *([0-9.]+).*/\1/p' "$SRC/readme.txt" | head -1)"
CHANGELOG="$(sed -nE 's/^## \[?([0-9]+\.[0-9]+\.[0-9]+)\]?.*/\1/p' "$SRC/CHANGELOG.md" | head -1)"
README_ENTRY="$(grep -cE "^= ${HEADER} =" "$SRC/readme.txt" || true)"

echo "== ${SLUG} from ${REF} (${SHA})"
echo "   header=${HEADER} constant=${CONST} stable_tag=${STABLE} changelog=${CHANGELOG}"
if [ -z "$HEADER" ] || [ "$HEADER" != "$CONST" ] || [ "$HEADER" != "$STABLE" ] || [ "$HEADER" != "$CHANGELOG" ]; then
	echo "ERROR: versions disagree, bump all of them in one commit (see the release skill)." >&2
	exit 1
fi
[ "$README_ENTRY" -ge 1 ] || { echo "ERROR: readme.txt has no '= ${HEADER} =' changelog entry." >&2; exit 1; }

# --- Package --------------------------------------------------------------
for ITEM in "${WHITELIST[@]}"; do
	[ -e "$SRC/$ITEM" ] || { echo "ERROR: whitelisted '$ITEM' is missing from ${REF}." >&2; exit 1; }
	cp -R "$SRC/$ITEM" "$WORK/pkg/${SLUG}/"
done
find "$WORK/pkg" -name '.DS_Store' -delete

# Belt and braces: the whitelist should make these impossible.
LEAKED="$(cd "$WORK/pkg/${SLUG}" && find . \( -name '*.md' -o -name '*.sh' -o -path './tests*' -o -path './bin*' -o -name '.*' ! -name '.' \) -print)"
[ -z "$LEAKED" ] || { echo "ERROR: dev files in the package:"; echo "$LEAKED"; exit 1; } >&2

# --- Lint on the lowest supported PHP -------------------------------------
MIN_PHP="$(sed -nE 's/^ \* Requires PHP: *([0-9]+\.[0-9]+).*/\1/p' "$SRC/${SLUG}.php" | head -1)"
MIN_PHP="${MIN_PHP:-8.0}"
if docker info >/dev/null 2>&1; then
	LINT="$(docker run --rm -v "$WORK/pkg:/pkg:ro" "php:${MIN_PHP}-cli" \
		sh -c 'find /pkg -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors" || true')"
	LINTED_WITH="PHP ${MIN_PHP} (Docker)"
else
	LINT="$(find "$WORK/pkg" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)"
	LINTED_WITH="local $(php -r 'echo PHP_VERSION;') (Docker not running, ${MIN_PHP}-only syntax not caught)"
fi
[ -z "$LINT" ] || { echo "ERROR: php -l:"; echo "$LINT"; exit 1; } >&2
echo "OK: php -l on ${LINTED_WITH}"

# --- Zip ------------------------------------------------------------------
TAG_SHA="$(git -C "$REPO" rev-parse -q --verify "refs/tags/v${HEADER}^{commit}" 2>/dev/null || true)"
REF_SHA="$(git -C "$REPO" rev-parse "${REF}^{commit}")"
NAME="${SLUG}-${HEADER}.zip"
[ "$TAG_SHA" = "$REF_SHA" ] || NAME="${SLUG}-${HEADER}-${SHA}.zip"

mkdir -p "$OUT"
( cd "$WORK/pkg" && zip -qrX "$WORK/$NAME" "${SLUG}/" )
cp "$WORK/$NAME" "$OUT/$NAME"

echo "OK: $(cd "$WORK/pkg/${SLUG}" && find . -type f | wc -l | tr -d ' ') files"
( cd "$WORK/pkg" && find "${SLUG}" -type f | sort | sed 's/^/   /' )
echo "== $OUT/$NAME"
shasum -a 256 "$OUT/$NAME"
