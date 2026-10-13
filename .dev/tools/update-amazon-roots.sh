#!/usr/bin/env bash
# Rebuilds data/amazon-roots.pem from Amazon Trust Services and checks the SHA-256 fingerprint of every certificate.
# Development only; not part of the plugin package.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'command rm -rf "$tmp"' EXIT

# name|SHA-256 fingerprint. Checked on 2026-10-12 against https://www.amazontrust.com/repository/
# and against the macOS system root store.
certs=(
    "AmazonRootCA1|8ECDE6884F3D87B1125BA31AC3FCB13D7016DE7F57CC904FE1CB97C6AE98196E"
    "AmazonRootCA2|1BA5B2AA8C65401A82960118F80BEC4F62304D83CEC4713A19C39C011EA46DB4"
    "AmazonRootCA3|18CE6CFE7BF14E60B2E347B8DFE868CB31D02EBB3ADA271569F50343B46DB3A4"
    "AmazonRootCA4|E35D28419ED02025CFA69038CD623962458DA5C695FBDEA3C22B0BFB25897092"
    "SFSRootCAG2|568D6905A2C88708A4B3025190EDCFEDB1974A606A13C6E5290FCB2AE63EDAB5"
)

out="$tmp/amazon-roots.pem"
{
    echo "# Amazon Trust Services root certificates, from https://www.amazontrust.com/repository/"
    echo "# tool_secbackup trusts only these roots for connections to the backup service."
} > "$out"
for entry in "${certs[@]}"; do
    name="${entry%%|*}"
    want="${entry#*|}"
    curl -fsS --max-time 30 -o "$tmp/$name.pem" "https://www.amazontrust.com/repository/$name.pem"
    got="$(openssl x509 -in "$tmp/$name.pem" -noout -fingerprint -sha256 | sed 's/.*=//; s/://g')"
    if [ "$got" != "$want" ]; then
        echo "Fingerprint mismatch for $name: $got" >&2
        exit 1
    fi
    {
        echo
        echo "# $(openssl x509 -in "$tmp/$name.pem" -noout -subject)"
        cat "$tmp/$name.pem"
    } >> "$out"
done
mkdir -p "$root/data"
/bin/cp -f "$out" "$root/data/amazon-roots.pem"
echo "wrote data/amazon-roots.pem (${#certs[@]} certificates)"
