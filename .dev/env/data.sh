#!/usr/bin/env bash
# Fills one environment with dummy data. Usage: .dev/env/data.sh <ver> <db> <S|M> [--seed=N] [--force]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env.sh
source "$here/env.sh" "${1:?ver}" "${2:?db}"
size="${3:?size S or M}"
shift 3
"$here/run.sh" "$secbk_ver" "$secbk_db" php "$SECBK_PLUGIN_PATH/.dev/env/generate_site_data.php" --size="$size" "$@"
