@tool @tool_secbackup @javascript
Feature: The scheduled task of the plugin is on the scheduled tasks page of Moodle
  In order to see, disable or run the regular status request
  As a site administrator
  I need to find the task of the plugin with the other scheduled tasks

  Background:
    Given I log in as "admin"

  Scenario: The task of the plugin is listed with its name
    When I open "Server > Tasks > Scheduled tasks" in site administration
    Then I should see "Ask the backup service for the status of this site"
    And I should see "\tool_secbackup\task\poll_status"

  Scenario: The task is named in the language of the page
    Given the Japanese language pack is installed
    When I visit "/admin/tool/task/scheduledtasks.php?lang=ja"
    Then I should see "バックアップのサービスに、このサイトの状態を問い合わせる"
