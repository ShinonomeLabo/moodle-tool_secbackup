@tool @tool_secbackup @javascript
Feature: Check the connection to the backup service
  In order to find out why the site cannot reach the backup service
  As a site administrator
  I need to see a check of each part of the connection

  Background:
    Given the fake backup service is reset
    And I log in as "admin"

  Scenario: The registered site is checked at once
    Given the site is registered with the fake backup service
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Connection diagnostics" in site administration
    Then I should see "Skipped because of the developer settings."
    And I should see "The service was reached. This check asks without a signature, so HTTP 401 (authentication required) is the expected answer."

  Scenario: A typed connection URL is checked before registration
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Connection diagnostics" in site administration
    Then I should see "Register the site, or enter a connection URL, to check this."
    And I set the field "Connection URL" to "http://localhost:8099/api/lms-backup/v1"
    And I press "Check the connection"
    And I should see "The service was reached. This check asks without a signature, so HTTP 401 (authentication required) is the expected answer."

  Scenario: A registration that cannot reach the service points to the diagnostics
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Connection settings" in site administration
    And I set the following fields to these values:
      | Connection URL          | http://localhost:9/api/lms-backup/v1                 |
      | Public key of this site | secbk1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE= |
    And I press "Next"
    And I set the following fields to these values:
      | Fingerprint check | 1                               |
      | Registration code | SEC-BK-AAAA-BBBB-CCCC-DDDD-EEEE |
    And I press "Register"
    Then I should see "Could not connect to the service"
    And I should see "To check the network of this server, open Connection diagnostics."
    And "//div[contains(@class, 'alert-info')][contains(., 'To check the network of this server')]" "xpath_element" should exist
    And "//div[contains(@class, 'alert-info')][contains(., 'To check the network of this server')]//button" "xpath_element" should not exist
    And I click on "Connection diagnostics" "link" in the ".alert-info" "css_element"
    And I should see "Direct connection, without a proxy."

  Scenario: The checks of the server that a backup depends on are listed
    Given the site is registered with the fake backup service
    When I open "Plugins > Admin tools > Shinonome LMS Backup > Connection diagnostics" in site administration
    Then I should see "Temporary folder" in the "region-main" "region"
    And I should see "Server load" in the "region-main" "region"
    And I should see "Available memory" in the "region-main" "region"
    And I should see "Database response" in the "region-main" "region"
    And I should see "Automated backup of Moodle" in the "region-main" "region"
    And I should see "Free space:" in the "Temporary folder" "table_row"
    And I should see "One simple read took" in the "Database response" "table_row"
