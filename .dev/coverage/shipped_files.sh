#!/usr/bin/env bash
# Lists the PHP files that the release ZIP contains (export-ignore applied), without tests/.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
git -C "$root" archive --format=tar HEAD | tar -t | grep '\.php$' | grep -v '^tests/' | sort
