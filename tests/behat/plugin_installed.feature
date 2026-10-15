@tool @tool_secbackup @javascript
Feature: The plugin is installed
  In order to manage the backup connection
  As an administrator
  I need to see the plugin in the list of plugins

  Scenario: The plugin appears in the plugins overview
    Given I log in as "admin"
    When I open "Plugins > Plugins overview" in site administration
    # Only the list of plugins has table rows: the page before it, with the Plugins tab, has none.
    Then "Shinonome LMS Backup" "table_row" should exist
    And I should see "tool_secbackup" in the "Shinonome LMS Backup" "table_row"

  Scenario: The category of the plugin lists its pages
    Given I log in as "admin"
    When I open "Plugins > Admin tools > Shinonome LMS Backup" in site administration
    Then I should see "Shinonome LMS Backup" in the "page-header" "region"
    And I should not see "Plugins overview" in the "region-main" "region"
    And I should see "Status" in the "region-main" "region"
    And I should see "Connection settings" in the "region-main" "region"
    And I should see "Connection diagnostics" in the "region-main" "region"

  Scenario: The plugin name is shown in Japanese
    Given the Japanese language pack is installed
    And I log in as "admin"
    And I follow "Preferences" in the user menu
    And I follow "Preferred language"
    And I set the field "Preferred language" to "日本語 ‎(ja)‎"
    And I press "Save changes"
    And I should see "Changes saved"
    When I open "Plugins > Plugins overview" in site administration
    Then "あんしんLMSバックアップ" "table_row" should exist
    And I should see "tool_secbackup" in the "あんしんLMSバックアップ" "table_row"
