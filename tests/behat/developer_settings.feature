@tool @tool_secbackup @javascript
Feature: The developer settings come from config.php only
  In order to keep the trust of the plugin out of reach of the database and of the caches of Moodle
  As an administrator
  I need the plugin to ignore developer settings that config.php did not define

  Scenario: Developer settings that the database or a cache could provide are ignored
    Then developer settings that the database or a cache could provide are ignored
