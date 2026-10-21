#!/usr/bin/env bash
# Names the known failures of a browser or a runner in the output of a failed Behat run, as error annotations of GitHub, so
# that the summary of the run tells them from a failure of the plugin. It only names them: the job fails all the same, and no
# scenario is run again. The jobs of ci.yml call it when their Behat step failed.
#
# The two known failures, both seen on GitHub (the comments of the Behat jobs in ci.yml have the evidence):
# - safaridriver answered "no such window" to a command sent while a page was being replaced; the faildumps show the new page
#   in the same window. The steps of the plugin wait through it (tests/behat/behat_tool_secbackup.php,
#   click_and_wait_for_new_page()). The steps of Moodle do not always: Moodle 4.5 and 5.0 catch the class of Mink, which the
#   driver does not throw, and on every version the check for deprecated icons catches nothing (the comment of the job
#   behat-safari in ci.yml has the files and lines).
# - In the hook before the first step of a scenario, the front page that Moodle opens did not finish its JavaScript within the
#   time of Behat. The plugin has no code on that page.
#
# Each failure in the lists that Behat prints at the end ("--- Failed steps:", "--- Failed hooks:") counts once: a failed
# scenario whose entry names php-webdriver's NoSuchWindowException is the first kind, a failed BeforeStep hook of Moodle whose
# exception says "not ready after" the second. When other failures are listed too, the annotation says how many, so that a
# known name is never taken for the cause of the whole job.
# Lines may start with the time stamps of a log that GitHub keeps, so a downloaded log of a job can be read as well.
# Usage: .dev/ci/behat-known-failures.sh <output of the Behat run>
set -u
log="${1:?usage: behat-known-failures.sh <output of the Behat run>}"
if [ ! -f "$log" ]; then
    echo "::error title=Behat output missing::$log was not written, so the failure could not be classified."
    exit 0
fi
# Prints: failed scenarios, failed hooks, of them with "no such window", of them not ready before the first step.
counts="$(awk '
    { sub(/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9:.]+Z /, "") }
    /^--- Failed steps:/ { section = "steps"; next }
    /^--- Failed hooks:/ { section = "hooks"; next }
    /^[0-9]+ scenarios? \(/ { section = ""; next }
    section == "steps" && /^[0-9][0-9][0-9] [A-Z]/ { scenarios++; marked = 0; next }
    section == "steps" && scenarios > 0 && !marked && /Facebook\\WebDriver\\Exception\\NoSuchWindowException/ {
        window++; marked = 1; next
    }
    section == "hooks" && /^ *(Before|After)(Step|Scenario|Feature|Suite) / {
        hooks++; firststep = ($0 ~ /# behat_hooks::before_step\(\)/); next
    }
    section == "hooks" && firststep && /not ready after/ { notready++; firststep = 0; next }
    END { printf "%d %d %d %d\n", scenarios, hooks, window, notready }
' "$log")"
read -r scenarios hooks window notready <<< "$counts"
failures=$((scenarios + hooks))
known=$((window + notready))
if [ "$failures" -eq 0 ]; then
    echo "::error title=No failure listed by Behat::The output of Behat has no list of failed steps or hooks (it may have stopped before it): look at the log of the step."
    exit 0
fi
if [ "$known" -eq "$failures" ]; then
    share="It is the cause of every failure that Behat lists ($known of $failures)."
else
    share="It is the cause of $known of the $failures failures that Behat lists; the other $((failures - known)) are not a known failure: look at them."
fi
if [ "$window" -gt 0 ]; then
    echo "::error title=Known browser failure (safaridriver: no such window)::safaridriver answered \"no such window\" while a page was being replaced ($window failed scenarios). $share See the comment of the job behat-safari in ci.yml; check in the faildump that the page came."
fi
if [ "$notready" -gt 0 ]; then
    echo "::error title=Known runner failure (page not ready before the first step)::The front page that Moodle opens before the first step did not finish its JavaScript in time ($notready failed hooks). $share See the comment of the Behat jobs in ci.yml."
fi
if [ "$known" -eq 0 ]; then
    echo "No known failure of a browser or a runner among the $failures failures that Behat lists: look at the failed steps."
fi
exit 0
