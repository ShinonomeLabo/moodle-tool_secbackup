#!/usr/bin/env bash
# Enables the repository hooks and sets the only identity allowed in this repository.
# The hooks need perl (see lib/rules.sh), so nothing is changed when perl is missing.
set -eu
if ! command -v perl > /dev/null 2>&1; then
    echo "error: perl is required by the commit hooks (.dev/hooks/lib/rules.sh) but was not found in PATH; install it and run this script again." >&2
    exit 1
fi
root="$(cd "$(dirname "$0")/../.." && pwd)"
git -C "$root" config core.hooksPath .dev/hooks
git -C "$root" config user.name 'Takayuki Fuwa'
git -C "$root" config user.email 'fuwa@shinonome.co.jp'
chmod +x "$root/.dev/hooks/commit-msg" "$root/.dev/hooks/pre-push" "$root/.dev/hooks/check-history.sh" \
    "$root/.dev/hooks/check-contents.sh" "$root/.dev/hooks/tests/run.sh"
if [ ! -s "$root/.dev/hooks/patterns.local" ]; then
    echo "warning: .dev/hooks/patterns.local is missing; every commit will be rejected until it exists." >&2
fi
echo "hooks enabled: $(git -C "$root" config core.hooksPath)"
