@tool @tool_secbackup @javascript
Feature: See and start the backups of the site
  In order to know that the site is backed up, and to back it up at once
  As a site administrator
  I need to see the last backup, what became of a stopped one, and a button to back up now

  Background:
    Given the fake backup service is reset
    And the site is registered with the fake backup service
    And the fake backup service gives the settings of the backup
    And the following config values are set as admin:
      | loadpercore | 1000   | tool_secbackup |
      | minmemorymb | 0      | tool_secbackup |
      | maxdbms     | 600000 | tool_secbackup |
      | minnfsmbps  | 0      | tool_secbackup |
    And I log in as "admin"

  Scenario: The button queues a backup and cannot be pressed again
    Given I run the scheduled task "\tool_secbackup\task\poll_status"
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "No backup has completed yet."
    And I press "Back up now"
    And I should see "The backup was queued. It starts the next time the cron of Moodle runs."
    And I should see "Backup in progress"
    And I should see "A backup is in progress, so another one cannot be started now."
    And the "Back up now" "button" should be disabled

  Scenario: The last backup that completed is shown with its size and its duration
    Given a backup of the site completed in 3725 seconds after sending 7 chunks of 3145728 bytes
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "Last completed backup"
    And I should see "(7 chunks)"
    And I should see "1 hour 2 mins"
    And I should not see "No backup has completed yet."
    And the "Back up now" "button" should be enabled

  Scenario: A stopped backup is shown with its reason and what to do
    Given a backup of the site was stopped with the reason "disk_low"
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "There is not enough free space in the temporary folder of the plugin, so the backup was stopped."
    And I should see "What to do: Free up space on the disk that holds the temporary folder"
    And ".alert-danger" "css_element" should exist

  Scenario: A backup that the backup service did not start is shown with its own text
    Given the backup service did not start a backup of the site, with the answer "contract_inactive"
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "The backup service did not start the latest backup"
    And I should see "The contract for the backup service on Example Platform is not active"
    And I should not see "was stopped."
    And ".alert-warning" "css_element" should exist
    And ".alert-danger" "css_element" should not exist

  Scenario: The reason and what to do are in Japanese for Japanese users
    Given a backup of the site was stopped with the reason "version_too_old"
    And the Japanese language pack is installed
    When I visit "/admin/tool/secbackup/index.php?lang=ja"
    Then I should see "この連携ツールの版が、バックアップのサービスが受け付ける版より古いため、バックアップを中止しました。"
    And I should see "直し方: 連携ツールを最新の版に更新してから、もう一度バックアップを始めてください。"
    And I should see "予定を待たずにバックアップする"

  Scenario: A modified Moodle program is warned in English
    Given the following config values are set as admin:
      | coremodified | 1 | tool_secbackup |
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "The core source code of the LMS being backed up (Moodle™) may have been modified." in the ".modal-dialog" "css_element"
    And I should see "After each backup, always use the Preview feature to confirm that the backup can be restored." in the ".modal-dialog" "css_element"

  Scenario: The warning about a modified Moodle program is in Japanese for Japanese users
    Given the following config values are set as admin:
      | coremodified | 1 | tool_secbackup |
    And the Japanese language pack is installed
    When I visit "/admin/tool/secbackup/index.php?lang=ja"
    Then I should see "バックアップ対象LMS (Moodle™) は、LMS本体のソースコードに変更が加えられている可能性があります。" in the ".modal-dialog" "css_element"
    And I should see "バックアップ後に必ず「プレビュー」機能を利用し、リストア出来ることを確認して下さい。" in the ".modal-dialog" "css_element"
    And I click on "OK" "button" in the ".modal-dialog" "css_element"
    And I should not see "バックアップ対象LMS (Moodle™)"
