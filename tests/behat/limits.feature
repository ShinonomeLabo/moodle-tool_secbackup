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
