@tool @tool_secbackup @javascript
Feature: See the status of this site at the backup service
  In order to know that the backup service still accepts this site
  As a site administrator
  I need to see the outcome of the regular status request

  Background:
    Given the fake backup service is reset
    And the site is registered with the fake backup service
    And I log in as "admin"

  Scenario: The regular request records the status
    When I run the scheduled task "\tool_secbackup\task\poll_status"
    And I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "Active"
    And I should not see "The last status request failed."

  Scenario: A site removed at the service is reported
    Given the fake backup service answers in "site_removed" mode
    When I run the scheduled task "\tool_secbackup\task\poll_status"
    And I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    Then I should see "The last status request failed."
    And I should see "The service does not know this site, or its registration was disabled."

  Scenario: The status page links to the connection settings
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    And I click on "Connection settings" "link" in the "region-main" "region"
    Then I should see "The connection URL cannot be changed while the site is registered."
    And "Remove the registration" "button" should exist

  Scenario: The status page links to the connection diagnostics
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Status" in site administration
    And I click on "Connection diagnostics" "link" in the "region-main" "region"
    Then I should see "Direct connection, without a proxy."
    And I should see "The service's API answered (HTTP 401)."
