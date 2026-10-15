# Shared by the scripts on the Mac (sourced, not run). Development only.
# SECBK_VM is the name of the Lima machine; matrix.sh sets it for each template.
SECBK_VM="${SECBK_VM:-secbk-selinux}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Runs a command in the VM. Standard input is closed, so that nothing waits for it (ausearch does).
vm() { limactl shell "$SECBK_VM" "$@" < /dev/null; }

# Runs a command in the VM and lets it read this script's standard input.
vm_stdin() { limactl shell "$SECBK_VM" "$@"; }

# One value of /etc/secbk-secureos-info, which provision.sh wrote.
guest_info() { vm sudo sh -c ". /etc/secbk-secureos-info; printf '%s' \"\$$1\""; }
