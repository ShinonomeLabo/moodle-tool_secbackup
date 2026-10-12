@tool @tool_secbackup
Feature: The plugin is installed
  In order to manage the backup connection
  As an administrator
  I need to see the plugin in the list of plugins

  Scenario: The plugin appears in the plugins overview
    Given I log in as "admin"
    When I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "Shinonome LMS Backup"

  Scenario: The plugin name is shown in Japanese
    Given the Japanese language pack is installed
    And I log in as "admin"
    And I follow "Preferences" in the user menu
    And I follow "Preferred language"
    And I set the field "Preferred language" to "日本語 ‎(ja)‎"
    And I press "Save changes"
    When I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "あんしんLMSバックアップ"
