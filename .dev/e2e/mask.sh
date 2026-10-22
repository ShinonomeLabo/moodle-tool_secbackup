#!/usr/bin/env bash
# Masks the address of the staging service in the log of a job of .github/workflows/staging-e2e.yml. Development only.
# Usage: SECBK_E2E_BASE_URL=<the secret> .dev/e2e/mask.sh
#
# GitHub masks the URL, a secret, but not its host name alone: both go to ::add-mask:: commands (the host name as written and
# in lower case), which the runner takes out of the log and hides from then on. Those commands are the only place where the
# script writes them, so it runs only on GitHub's runner. Without the URL (an environment without the secret) it stops the
# job before anything reaches the service.
set -euo pipefail

if [ "${GITHUB_ACTIONS:-}" != true ]; then
    echo "mask.sh: this runs in a job of GitHub Actions only" >&2
    exit 2
fi
url="${SECBK_E2E_BASE_URL:-}"
if [ -z "$url" ]; then
    echo "The environment staging-e2e has no secret SECBK_E2E_BASE_URL (.dev/e2e/README.md)" >&2
    exit 1
fi
host="${url#*://}"
host="${host%%/*}"
host="${host%%:*}"
echo "::add-mask::$url"
if [ -n "$host" ]; then
    echo "::add-mask::$host"
    lower="$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')"
    if [ "$lower" != "$host" ]; then
        echo "::add-mask::$lower"
    fi
fi
