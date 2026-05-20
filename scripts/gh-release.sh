#!/usr/bin/env bash
# Usage: npm run gh:release
# Builds dist and publishes a GitHub release for the current plugin version.
set -euo pipefail

VERSION=$(grep 'Version:' haydi.php | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
TAG="v$VERSION"

if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "Tag $TAG already exists locally."
else
    echo "Creating tag $TAG..."
    git tag "$TAG"
fi

echo "Building dist..."
npm run dist

echo "Pushing tag $TAG..."
git push origin "$TAG"

echo "Creating GitHub release $TAG..."
gh release create "$TAG" dist/haydi.zip dist/haydi-full-extensions.zip \
    --title "Haydi $VERSION" \
    --notes "Release $VERSION" \
    --latest

echo ""
echo "Done: https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/tag/$TAG"
