<?php
// Development only (test VMs): raises one PHP warning from inside the plugin's directory, as a positive control for the
// count of PHP warnings of check.sh. Included by probe.php when it is asked for it. Not part of the plugin package.
// Like probe.php it does nothing unless /etc/secbk-secureos-probe exists (made by provision.sh).

if (!is_file('/etc/secbk-secureos-probe')) {
    return;
}

trigger_error('secbk positive control', E_USER_WARNING);
