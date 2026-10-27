@tool @tool_secbackup @javascript
Feature: Set the limits of the backup
  In order to keep the backup from slowing the site down
  As a site administrator
  I need to set the limits and be told when a value cannot be used

  Background:
    Given I log in as "admin"

  Scenario: A valid limit is saved and an invalid one is refused
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Limits of the backup" in site administration
    And I set the field "Slowest database response (milliseconds)" to "-1"
    And I press "Save changes"
    Then I should see "Enter a whole number of 1 or more."
    And I set the field "Slowest database response (milliseconds)" to "400"
    And I press "Save changes"
    And I should see "Changes saved"
    And the field "Slowest database response (milliseconds)" matches value "400"

  Scenario: The limits are shown with their descriptions in English
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Limits of the backup" in site administration
    Then I should see "Highest server load per CPU core"
    And I should see "The backup waits while the one-minute load average divided by the number of CPU cores is above this value."
    And I should see "Slowest file storage read speed (MB per second)"
    And I should see "Upload speed limit (Mbps)"
    And I should see "Temporary folder"

  Scenario: The limits are shown with their descriptions in Japanese for Japanese users
    Given the Japanese language pack is installed
    When I visit "/admin/settings.php?section=tool_secbackup_settings&lang=ja"
    Then I should see "バックアップの制限"
    And I should see "CPU コア 1 つあたりの負荷の上限"
    And I should see "一時フォルダ"

  Scenario: A limit that is saved is kept when the page is opened again
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Limits of the backup" in site administration
    And I set the field "Least available memory (MB)" to "512"
    And I press "Save changes"
    And I should see "Changes saved"
    And I open "Plugins > Admin tools > Shinonome LMS Backup > Limits of the backup" in site administration
    Then the field "Least available memory (MB)" matches value "512"
