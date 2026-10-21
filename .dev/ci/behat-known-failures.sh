#!/usr/bin/env bash
# Names the known failures of a browser or a runner in the output of a failed Behat run, as error annotations of GitHub, so
# that the summary of the run tells them from a failure of the plugin. It only names them: the job fails all the same, and no
# scenario is run again. The jobs of ci.yml call it when their Behat step failed.
#
# The two known failures, both seen on GitHub (the comments of the Behat jobs in ci.yml have the evidence):
# - safaridriver answered "no such window" to a command sent while a page was being replaced; the faildumps show the new page
#   in the same window. The steps of the plugin wait through it (tests/behat/behat_tool_secbackup.php,
#   click_and_wait_for_new_page()), the steps of Moodle do not.
# - In the hook before the first step of a scenario, the front page that Moodle opens did not finish its JavaScript within the
#   time of Behat. The plugin has no code on that page.
# Usage: .dev/ci/behat-known-failures.sh <output of the Behat run>
set -u
log="${1:?usage: behat-known-failures.sh <output of the Behat run>}"
if [ ! -f "$log" ]; then
    echo "::error title=Behat output missing::$log was not written, so the failure could not be classified."
    exit 0
fi
named=0
if grep -q 'Facebook\\WebDriver\\Exception\\NoSuchWindowException' "$log"; then
    echo '::error title=Known browser failure (safaridriver: no such window)::safaridriver answered "no such window" while a page was being replaced. See the comment of the job behat-safari in ci.yml; check in the faildump that the page came.'
    named=1
fi
if grep -q -E 'BeforeStep .*# behat_hooks::before_step\(\)' "$log" \
        && grep -q 'Javascript code and/or AJAX requests are not ready after' "$log"; then
    echo '::error title=Known runner failure (page not ready before the first step)::The front page that Moodle opens before the first step did not finish its JavaScript in time. See the comment of the Behat jobs in ci.yml.'
    named=1
fi
if [ "$named" -eq 0 ]; then
    echo "No known failure of a browser or a runner in the output: look at the failed steps."
fi
exit 0
