@tool @tool_secbackup
Feature: Register this site with the backup service
  In order to send backups to the backup service
  As a site administrator
  I need to register this site with the connection URL, the public key and a registration code

  Background:
    Given the fake backup service is reset
    And I log in as "admin"

  Scenario: The status page explains that the site is not registered
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "This site is not registered with the backup service yet."
    And I should see "Developer settings are active in config.php"
    And I should see "The word Moodle and associated Moodle logos are trademarks"

  Scenario: Register the site
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the following fields to these values:
      | Connection URL          | http://localhost:8099/api/lms-backup/v1/              |
      | Public key of this site | secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE= |
    And I press "Next"
    Then I should see "http://localhost:8099/api/lms-backup/v1"
    And I should see "A260 B405 0FD2 8D2B 313C 9A94"
    And I set the following fields to these values:
      | Fingerprint check | 1                               |
      | Registration code | SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE |
    And I press "Register"
    And I should see "This site was registered with Example Platform."
    And I should see "Example site"
    And I should see "http://localhost:8099/api/lms-backup/v1"

  Scenario: A wrong registration code saves nothing
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the following fields to these values:
      | Connection URL          | http://localhost:8099/api/lms-backup/v1              |
      | Public key of this site | secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE= |
    And I press "Next"
    And I should see "http://localhost:8099/api/lms-backup/v1"
    And I set the following fields to these values:
      | Fingerprint check | 1                               |
      | Registration code | SEC-BK-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ |
    And I press "Register"
    Then I should see "The registration code is wrong, has expired"
    And I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    And I should see "This site is not registered with the backup service yet."

  Scenario: An answer signed with an unknown key is refused
    Given the fake backup service answers in "untrusted_key" mode
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the following fields to these values:
      | Connection URL          | http://localhost:8099/api/lms-backup/v1              |
      | Public key of this site | secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE= |
    And I press "Next"
    And I should see "http://localhost:8099/api/lms-backup/v1"
    And I set the following fields to these values:
      | Fingerprint check | 1                               |
      | Registration code | SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE |
    And I press "Register"
    Then I should see "The response was signed with a key that this plugin does not trust."
    And I should see "To be safe, issue a new registration code on the platform screen."

  Scenario: Wrong values are explained before anything is sent
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the following fields to these values:
      | Connection URL          | localhost:8099/api |
      | Public key of this site | secbk1:AQEB        |
    And I press "Next"
    Then I should see "The connection URL is not a URL."
    And I should see "The public key is not complete."

  Scenario: Register again with a new registration code, without changing the connection URL
    Given the site is registered with the fake backup service
    And the fake backup service issued a new registration code
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    Then I should see "The connection URL cannot be changed while the site is registered."
    And "Connection URL" "field" should not exist
    And I set the field "Public key of this site" to "secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE="
    And I press "Next"
    And I should see "http://localhost:8099/api/lms-backup/v1"
    And I set the following fields to these values:
      | Fingerprint check | 1                               |
      | Registration code | SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE |
    And I press "Register"
    And I should see "This site was registered with Example Platform."

  Scenario: Remove the registration
    Given the site is registered with the fake backup service
    When I navigate to "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the field "Site name" to "Another site"
    And I press "Remove the registration"
    Then I should see "The typed name is not the site name on the platform."
    And I set the field "Site name" to "Example site"
    And I press "Remove the registration"
    And I should see "The registration of this site was removed."
    And I should see "This site is not registered with the backup service yet."

  Scenario: The pages are in Japanese for Japanese users
    Given the Japanese language pack is installed
    When I visit "/admin/tool/secbackup/index.php?lang=ja"
    Then I should see "このサイトは、まだバックアップのサービスに登録されていません。"
    And I should see "Moodle という語と関連する Moodle のロゴは、Moodle Pty Ltd またはその関連会社の商標または登録商標です。"
