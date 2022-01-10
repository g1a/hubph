# Changelog

### 0.6.4 - 2022-01-10

* Support returning info about created PR and optionally add comment to PR being closed. (#48)

### 0.6.3 - 2021-12-13

* Add new prGetDiff function.
* Add prGetComments function.
* Add new matchingPrsInUser function.

### 0.6.2 - 2021-12-06

* Add new prGet function

### 0.6.1 - 2021-12-01

* Make PullRequests class to implement Iterable

### 0.6.0 - 2021-11-29

* PHP 8
* Remove ExtraCommands.

### 0.5.0 - 2021-10-27

* repo:statuses
* repo:cat
* Internal commands for analyzing repositories in an org:
  - org:analyze
  - repo:convert-data
* php 8

### 0.4.0 - 2020-06-12

* switch-default command to switch the default branch of a GitHub project
* Use pager for org:repo command, and add a repo:info command

### 0.3.8 - 2020-06-11

* Add a command to list all repos in an org
* Use pager for org:repo command, and add a repo:info command

### 2020-06-11 - 0.3.7

* Add a command to list all repos in an org (#41)

### 2020-05-22 - 0.3.6

* Robo 2 (#40)
* Provide default value for pattern arg of matchingPRs

### 2020-05-13 - 0.3.5

* Factor "matchingPRs" method out of "existingPRs" method.

#### 2019-05-07 - 0.3.4

* Allow pattern '#.#.-' to indicate version might be 5.1 or 5.1.2 (non-semver). (#39)

### 2018-11-13 - 0.3.3

* Need to match both github.com/ and github.com: when deciding whether to insert github credentials

### 2018-11-13 - Version 0.3.2

* Don't add github oauth credentials to non-github repos

### 2018-09-13 - 0.3.1

* Id means number for pull request merging.

### 2018-09-13 - 0.3.0

* Add a pr:statuses command

### 2018-09-13 - 0.2.0

* Fix inconsistent results of checkPr method. (#15)

### 2018-09-11 - 0.1.10

* Allow 'as' parameter to specify the authentication token

### 2018-09-11 - 0.1.9

* Define missing property eventLogger

### 2018-09-11 - 0.1.8

* Update dependencies

### 0.1.7 - 2018-09-11

* Add a logger, and log created pull requests (and nothing else)

### 0.1.6 - 2018-07-28

* Add prCreate api

### 0.1.5 - 2018-07-28

* Add 'allPRs' method.
* Fix existing pull request searching algorithm.
* Fix regex in HubAPI::projectAndOrgFromUrl()

### 0.1.1 - 0.1.4 - 2018-07-26

* Add addTokenAuthentication() as a service, to update a provided github remote URL to contain an oauth token.
* Make gitHubToken() method public in HubphAPI.
* Fix release script

### 0.1.0 - 2018/Jul/19

* Created from template `g1a/hubph`
