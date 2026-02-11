#!/bin/bash
# Bump Astrolabe app version
set -euo pipefail

# Parse optional --increment flag
INCREMENT=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --increment)
            INCREMENT="$2"
            shift 2
            ;;
        *)
            echo "Error: Unknown option: $1" >&2
            echo "Usage: $0 [--increment PATCH|MINOR|MAJOR]" >&2
            exit 1
            ;;
    esac
done

# Validate required files exist
if [ ! -f "appinfo/info.xml" ]; then
    echo "Error: appinfo/info.xml not found - must run from repository root" >&2
    exit 1
fi

if [ ! -f "package.json" ]; then
    echo "Error: package.json not found" >&2
    exit 1
fi

echo "Bumping Astrolabe version..."
if [ -n "$INCREMENT" ]; then
    echo "  Forcing $INCREMENT bump"
fi

# Prefer uv if available, fall back to direct cz
if command -v uv >/dev/null 2>&1; then
    CZ_CMD="uv run cz --config .cz.toml bump --yes"
elif command -v cz >/dev/null 2>&1; then
    CZ_CMD="cz --config .cz.toml bump --yes"
else
    echo "Error: Neither uv nor commitizen (cz) found" >&2
    exit 1
fi

if [ -n "$INCREMENT" ]; then
    CZ_CMD="$CZ_CMD --increment $INCREMENT"
fi

# Run commitizen bump and capture output
if ! output=$($CZ_CMD 2>&1); then
    # Check if this is the expected "no commits to bump" case
    if echo "$output" | grep -q "\[NO_COMMITS_TO_BUMP\]"; then
        echo "No commits eligible for version bump" >&2
        echo "$output" >&2
        exit 0
    fi

    # Otherwise, this is an actual error
    echo "Error: Version bump failed" >&2
    echo "$output" >&2
    echo "" >&2
    echo "Common causes:" >&2
    echo "  - No conventional commits since last version" >&2
    echo "  - Git working directory not clean" >&2
    exit 1
fi

echo "$output"
echo ""
echo "Astrolabe version bumped successfully"
echo "  Updated: appinfo/info.xml, package.json"
echo "  Tag format: v\${version}"
echo ""
echo "Next steps:"
echo "  git push --follow-tags"
