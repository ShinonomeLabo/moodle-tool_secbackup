# Shared by the scripts on the Mac (sourced, not run). Development only.
# SECBK_VM is the name of the Lima machine; matrix.sh sets it for each template. Without it, the machine of SECBK_TEMPLATE.
SECBK_VM="${SECBK_VM:-secbk-${SECBK_TEMPLATE:-rocky-9}}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Runs a command in the VM. Standard input is closed, so that nothing waits for it (ausearch does).
vm() { limactl shell "$SECBK_VM" "$@" < /dev/null; }

# Runs a command in the VM and lets it read this script's standard input.
vm_stdin() { limactl shell "$SECBK_VM" "$@"; }

# One value of /etc/secbk-secureos-info, which provision.sh wrote.
guest_info() { vm sudo sh -c ". /etc/secbk-secureos-info; printf '%s' \"\$$1\""; }

# A host port of its own: from 18200 by the number given, and on past any port that is in use.
free_port() {
    local p=$((18200 + $1))
    while [ -n "$(lsof -nP -iTCP:"$p" -sTCP:LISTEN -t 2> /dev/null)" ]; do
        p=$((p + 20))
    done
    echo "$p"
}

# Creates and starts the VM $1 from the Lima template $3, with the guest's port 80 on the host port $2. A machine sometimes
# does not come up in time (the image boots slowly while another VM starts), so a failed start is tried once more with a
# new machine. A machine of the same name is replaced: the callers check before that this is meant.
start_vm() {
    local name="$1" port="$2" template="$3" attempt
    for attempt in 1 2; do
        limactl delete -f "$name" > /dev/null 2>&1 || true
        if limactl create --tty=false --name="$name" --cpus=2 --memory=4 --disk=20 --mount-none \
            --set ".portForwards=[{\"guestPort\":80,\"hostPort\":$port,\"hostIP\":\"127.0.0.1\"}]" "template:$template" \
            && limactl start --tty=false "$name"; then
            return 0
        fi
        echo "start of $name failed (attempt $attempt)"
    done
    return 1
}

# Whether a Lima machine of this name exists. The list is read into a variable first: a pipe into grep -q can fail when grep
# ends before the writer does (the writer gets SIGPIPE, and pipefail reports it).
vm_exists() {
    local list
    list="$(limactl list -q 2> /dev/null)"
    case $'\n'"$list"$'\n' in
        *$'\n'"$1"$'\n'*) return 0 ;;
    esac
    return 1
}
