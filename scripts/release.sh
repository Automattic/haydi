#!/usr/bin/env bash
# Usage: npm run release -- <version>
# Example: npm run release -- 1.1.0
set -euo pipefail

VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    echo "Usage: npm run release -- <version>" >&2
    exit 1
fi

if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Version must be in X.Y.Z format (got: $VERSION)" >&2
    exit 1
fi

CURRENT=$(grep 'Version:' haydi.php | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
if [ "$CURRENT" = "$VERSION" ]; then
    echo "Version is already $VERSION — nothing to do." >&2
    exit 1
fi

echo "Bumping $CURRENT → $VERSION"

# Update plugin header
sed -i '' "s/Version:           $CURRENT/Version:           $VERSION/" haydi.php

# Update readme.txt Stable tag
sed -i '' "s/^Stable tag: $CURRENT$/Stable tag: $VERSION/" readme.txt

# Prepend changelog entry (after the == Changelog == heading)
TODAY=$(date +%Y-%m-%d)
ENTRY="= $VERSION =\n* Released $TODAY.\n"
sed -i '' "/^== Changelog ==$/a\\
\\
$ENTRY" readme.txt

echo "Running dist build..."
npm run dist

echo "Committing version bump..."
git add haydi.php readme.txt LICENSE
git commit -m "Bump version to $VERSION"

echo "Creating tag v$VERSION..."
git tag "v$VERSION"

echo ""
echo "Done. Run 'git push && git push --tags' to publish."
