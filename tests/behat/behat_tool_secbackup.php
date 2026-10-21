<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat steps of tool_secbackup.
 *
 * @package    tool_secbackup
 * @category   test
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by Behat before including config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ExpectationException;
use Facebook\WebDriver\Exception\NoSuchWindowException;
use tool_secbackup\local\backup\run_record;
use tool_secbackup\local\crypto\public_key_format;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\enroller;
use tool_secbackup\local\trust\developer_settings;
use tool_secbackup\local\trust\sec_trust;

/**
 * Behat steps of tool_secbackup.
 *
 * @package    tool_secbackup
 * @category   test
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_secbackup extends behat_base {
    /** @var string Address of the fake backup service. */
    private const FAKE = 'http://localhost:8099';

    /**
     * Installs a minimal Japanese language, so that real pages load the strings of lang/ja of the plugin.
     *
     * The language has no strings of its own: the strings of Moodle fall back to English and only the plugin is in
     * Japanese. A language pack cannot be put on the test site beforehand, because Moodle empties the language folder of
     * the test site before every scenario, so each scenario that needs Japanese installs it.
     *
     * @Given /^the Japanese language pack is installed$/
     */
    public function the_japanese_language_pack_is_installed(): void {
        global $CFG;

        $strings = [
            'locale' => 'ja_JP.UTF-8',
            'parentlanguage' => '',
            'thisdirection' => 'ltr',
            'thislanguage' => '日本語',
            'thislanguageint' => 'Japanese',
        ];
        $code = "<?php\n";
        foreach ($strings as $id => $text) {
            $code .= '$string[' . var_export($id, true) . '] = ' . var_export($text, true) . ";\n";
        }

        // The folder in which Moodle looks for language packs: the language folder of the dataroot, unless it is moved.
        $dir = $CFG->langotherroot . '/ja';
        make_writable_directory($dir);
        $file = $dir . '/langconfig.php';
        if (file_put_contents($file, $code) === false) {
            throw new \Exception("The Japanese language pack cannot be written to $file.");
        }
        get_string_manager()->reset_caches();
    }

    /**
     * Checks that developer settings, put where the database or a cache of Moodle could put them, are not used.
     *
     * Behat is not PHPUnit, so here the plugin reads the constant of config.php and nothing else. The step puts the
     * settings in $CFG and in the copy of config.php that Moodle keeps in it, as the database and the caches could, and
     * checks that neither the settings nor the trusted keys change. The step takes the settings out again.
     *
     * @Then /^developer settings that the database or a cache could provide are ignored$/
     */
    public function developer_settings_that_the_database_or_a_cache_could_provide_are_ignored(): void {
        global $CFG;

        $publickey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $planted = [
            'trustedkeys' => [base64_encode($publickey)],
            'allowhttp' => true,
            'allowprivatehosts' => true,
            'skipnetworkchecks' => true,
        ];
        // What config.php gives this site: nothing, or the constant that the site defines.
        $expected = developer_settings::from_value(defined('TOOL_SECBACKUP_DEVELOPER') ? TOOL_SECBACKUP_DEVELOPER : null);

        $hadsetting = property_exists($CFG, 'tool_secbackup_developer');
        $setting = $CFG->tool_secbackup_developer ?? null;
        $hadcopy = property_exists($CFG, 'config_php_settings');
        $copy = $CFG->config_php_settings ?? null;
        try {
            $CFG->tool_secbackup_developer = $planted;
            $CFG->config_php_settings = ['tool_secbackup_developer' => $planted] + (is_array($copy) ? $copy : []);
            $actual = developer_settings::current();
            $keyids = sec_trust::current()->key_ids();
        } finally {
            if ($hadsetting) {
                $CFG->tool_secbackup_developer = $setting;
            } else {
                unset($CFG->tool_secbackup_developer);
            }
            if ($hadcopy) {
                $CFG->config_php_settings = $copy;
            } else {
                unset($CFG->config_php_settings);
            }
        }

        if ($actual != $expected) {
            throw new ExpectationException(
                'The plugin used developer settings that config.php did not define.',
                $this->getSession()
            );
        }
        if (in_array(sec_trust::kid($publickey), $keyids, true)) {
            throw new ExpectationException('The plugin trusts a key that config.php did not give it.', $this->getSession());
        }
    }

    /**
     * Opens a page of the site administration: the primary navigation, the tab of the administration page, the link.
     *
     * It does what the step "I navigate to ... in site administration" of Moodle does, with one difference that matters in
     * Safari: Moodle looks for the tab at once after it clicked "Site administration", and safaridriver gives the control back
     * before the page has changed, so the tab is looked for on the old page and is not there. This step waits until the new
     * page has replaced the old one, and then looks for the tab, like the step of Moodle in the other browsers.
     * The path starts with the tab (for example "Plugins"), may name the section that holds the link, and ends with the link.
     *
     * @When /^I open "(?P<path_string>[^"]*)" in site administration$/
     * @param string $path Names separated by ">".
     */
    public function i_open_in_site_administration(string $path): void {
        $nodes = array_map('trim', explode('>', $path));
        if (count($nodes) < 2) {
            throw new \Exception('The path needs a tab and a link, separated by ">".');
        }
        $tab = array_shift($nodes);
        $link = array_pop($nodes);

        // The click may meet a page that the step before has not finished leaving, so it is tried again. Moodle's step makes
        // its checks after the click; when they meet the page that is being replaced, the attempt is tried again and clicks
        // "Site administration" once more, which the new page has too.
        $this->click_and_wait_for_new_page(function (): void {
            $this->execute('behat_navigation::i_select_from_primary_navigation', [get_string('administrationsite')]);
        }, 'Site administration');

        $tabxpath = '//ul[@role="tablist"]/li/a[contains(normalize-space(.), ' . behat_context_helper::escape($tab) . ')]';
        $this->click_when_present($tabxpath, 'The tab "' . $tab . '" is not on the page.', false);

        $xpath = '//div[@id="region-main"]//div[contains(@class, "active")]';
        if ($nodes) {
            $xpath .= '//div[@class="row" and contains(., ' . behat_context_helper::escape($nodes[0]) . ')]';
        }
        $xpath .= '//a[contains(normalize-space(.), ' . behat_context_helper::escape($link) . ')]';
        $this->click_when_present($xpath, 'The link "' . $path . '" is not on the page.', true, $path);
    }

    /**
     * Clicks the element that an XPath finds, trying again until it is there and can be clicked.
     *
     * The page that holds the element may still be loading: safaridriver gives the control back before a page has changed,
     * so an element can be missing at first, or can be gone from the old page when it is clicked. The attempt starts again
     * with a new search, until the time of Behat for slow steps is over.
     *
     * A click that loads a page is the last command of its attempt: the checks that Moodle makes after the click of a step
     * (pending JavaScript, exceptions, deprecated styles and icons) wait until the new page is there
     * (click_and_wait_for_new_page()).
     *
     * @param string $xpath The element.
     * @param string $message What to say when the element did not come.
     * @param bool $loadspage Whether the click loads a new page, which is then waited for.
     * @param string $name What is clicked, for the message when no new page came.
     */
    private function click_when_present(string $xpath, string $message, bool $loadspage, string $name = ''): void {
        $find = function () use ($xpath, $message): NodeElement {
            $node = $this->getSession()->getPage()->find('xpath', $xpath);
            if ($node === null) {
                throw new ExpectationException($message, $this->getSession());
            }
            return $node;
        };
        if ($loadspage) {
            $this->click_and_wait_for_new_page(function () use ($find): void {
                $find()->click();
            }, $name);
        } else {
            $this->spin(function () use ($find): bool {
                $this->execute('behat_general::i_click_on', [$find(), 'NodeElement']);
                return true;
            }, false, self::get_extended_timeout());
        }
    }

    /**
     * Runs a click that loads a new page, again and again until it could be done, and returns when the new page has
     * replaced the old one.
     *
     * A marker is put on the old page before each attempt; the new page does not have it. An attempt is tried again only
     * when it threw, so the click is the last command of the attempt: an attempt that clicked and then failed on the page
     * that was being replaced would be tried again on the new page, where the element is not, and the step would fail with
     * the first exception although the page had come. (The click of the primary navigation in
     * i_open_in_site_administration() is Moodle's step, which checks after the click; trying it again is harmless there.)
     *
     * While the old page is replaced, safaridriver can answer a command with "no such window" (the NoSuchWindowException of
     * php-webdriver) for a moment; the same window then shows the new page. So such an answer during the wait means that the
     * new page has not come yet. (Moodle's own wait for pending JavaScript catches this answer as the NoSuchWindowException of
     * Mink, which the php-webdriver driver does not throw.) The checks that Moodle makes after the actions of a step run once
     * the new page is there.
     *
     * @param callable $click The click; it throws when it cannot be done (yet).
     * @param string $name What is clicked, for the message when no new page came.
     */
    private function click_and_wait_for_new_page(callable $click, string $name): void {
        $this->spin(function () use ($click): bool {
            $this->getSession()->executeScript('window.tool_secbackup_old_page = true;');
            $click();
            return true;
        }, false, self::get_extended_timeout());
        // A page can be slow to load (an emulated browser on a slow machine took more than the usual 10 seconds), so the wait
        // is six times the extended time of Behat; it ends as soon as the new page is there.
        $end = microtime(true) + self::get_extended_timeout() * 6;
        do {
            try {
                $arrived = (bool) $this->getSession()->evaluateScript('return typeof window.tool_secbackup_old_page === "undefined"'
                    . ' && document.readyState === "complete";');
            } catch (NoSuchWindowException $e) {
                $arrived = false;
            }
            if (!$arrived) {
                usleep(100000);
            }
        } while (!$arrived && microtime(true) < $end);
        if (!$arrived) {
            // Going on would let the next step look at the old page, and a text that both pages show would pass.
            throw new ExpectationException('"' . $name . '" did not load a new page.', $this->getSession());
        }
        $this->wait_for_pending_js();
        $this->look_for_exceptions();
        $this->look_for_deprecated_styles();
        $this->look_for_deprecated_icons();
    }

    /**
     * Forgets everything the fake backup service knows.
     *
     * @Given /^the fake backup service is reset$/
     */
    public function the_fake_backup_service_is_reset(): void {
        $this->control('reset');
    }

    /**
     * Makes the fake backup service answer in a special way.
     *
     * @Given /^the fake backup service answers in "(?P<mode_string>[a-z_]+)" mode$/
     * @param string $mode Mode of the fake service.
     */
    public function the_fake_backup_service_answers_in_mode(string $mode): void {
        $this->control('mode/' . $mode);
    }

    /**
     * Lets the registration code be used once more, like a new code issued on the platform.
     *
     * @Given /^the fake backup service issued a new registration code$/
     */
    public function the_fake_backup_service_issued_a_new_registration_code(): void {
        $this->control('newcode');
    }

    /**
     * Registers the site with the fake backup service without the pages.
     *
     * @Given /^the site is registered with the fake backup service$/
     */
    public function the_site_is_registered_with_the_fake_backup_service(): void {
        $endpoint = endpoint::parse(self::FAKE . '/api/lms-backup/v1', developer_settings::current());
        enroller::create()->enroll(
            $endpoint,
            public_key_format::parse('secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='),
            'SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE'
        );
    }

    /**
     * Makes the fake backup service send the settings of the backup, with a window of one hour that starts twelve hours from
     * now (in UTC), so that the scheduled start never falls in the time that a scenario runs, whatever the time of day is.
     * A backup is then started only by the button.
     *
     * @Given /^the fake backup service gives the settings of the backup$/
     */
    public function the_fake_backup_service_gives_the_settings_of_the_backup(): void {
        $this->give_settings(12 * HOURSECS);
    }

    /**
     * Sends settings whose window of one hour starts some seconds from now.
     *
     * @param int $offset Seconds from now to the start of the window, which is then rounded down to the hour or the half hour.
     */
    private function give_settings(int $offset): void {
        // The service sends times on the hour or the half hour.
        $from = intdiv(time() + $offset, 1800) * 1800;
        $start = gmdate('H:i', $from);
        $end = gmdate('H:i', $from + HOURSECS);
        $this->control('instruction/settings', [
            'seq' => 1,
            'body' => [
                'timezone' => 'UTC',
                'window_start' => $start,
                'window_end' => $end,
                'runs_per_day' => 1,
                'full_interval' => 'weekly',
                'bandwidth_mbps' => 0,
                'include_logs' => false,
            ],
        ]);
    }

    /**
     * Puts a backup in the record that completed a short time ago.
     *
     * @Given /^a backup of the site completed in (\d+) seconds after sending (\d+) chunks of (\d+) bytes$/
     * @param string $seconds How long the backup took.
     * @param string $chunks The chunks that were sent.
     * @param string $bytes The bytes that were sent.
     */
    public function a_backup_of_the_site_completed(string $seconds, string $chunks, string $bytes): void {
        $start = time() - (int) $seconds - 60;
        $run = run_record::create('schedule', $start);
        $run->add_uploaded((int) $bytes, (int) $chunks);
        $run->finish($start + (int) $seconds);
    }

    /**
     * Puts a backup in the record that was stopped a short time ago.
     *
     * @Given /^a backup of the site was stopped with the reason "(?P<code_string>[a-z_]+)"$/
     * @param string $code The reason.
     */
    public function a_backup_of_the_site_was_stopped(string $code): void {
        $start = time() - 300;
        run_record::create('schedule', $start)->abort($code, $start + 100);
    }

    /**
     * Puts a backup in the record that the backup service did not start.
     *
     * @Given /^the backup service did not start a backup of the site, with the answer "(?P<code_string>[a-z_]+)"$/
     * @param string $code The answer of the service.
     */
    public function the_backup_service_did_not_start_a_backup(string $code): void {
        $start = time() - 300;
        run_record::create('manual', $start)->refuse($code, $start + 1);
    }

    /**
     * Sends a control request to the fake backup service.
     *
     * @param string $action Control action.
     * @param array|null $body A body to send as JSON, or null for none.
     */
    private function control(string $action, ?array $body = null): void {
        $client = new \core\http_client(['ignoresecurity' => true]);
        $options = ['http_errors' => false, 'timeout' => 10];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $client->post(self::FAKE . '/_fake/' . $action, $options);
        if ($response->getStatusCode() !== 204) {
            throw new \Exception(
                'The fake backup service did not accept "' . $action . '". Start it with .dev/fakesec/start.sh.'
            );
        }
    }
}
