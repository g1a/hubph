# Hubph

Hubph (pronounced "huff") is an experimental PHP implementation of Hub.

[![Travis CI](https://travis-ci.org/g1a/hubph.svg?branch=master)](https://travis-ci.org/g1a/hubph)
[![Windows CI](https://ci.appveyor.com/api/projects/status/{{PUT_APPVEYOR_STATUS_BADGE_ID_HERE}}?svg=true)](https://ci.appveyor.com/project/g1a/hubph)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/g1a/hubph/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/g1a/hubph/?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/g1a/hubph/badge.svg?branch=master)](https://coveralls.io/github/g1a/hubph?branch=master) 
[![License](https://img.shields.io/badge/license-MIT-408677.svg)](LICENSE)

## Getting Started

To try out hubph, download the phar from the [releases](https://github.com/g1a/hubph/releases) section of the GitHub project.

This is just a prototype project, so documentation is sparse, and the commands and their options may change at any time.

### Local Development

Clone this project and then run:

```
composer install
```

If you wish to build the phar for this project, install the `box` phar builder via:

```
composer phar:install-tools
```

## Running the tests

The test suite may be run locally by way of some simple composer scripts:

| Test             | Command
| ---------------- | ---
| Run all tests    | `composer test`
| PHPUnit tests    | `composer unit`
| PHP linter       | `composer lint`
| Code style       | `composer cs`     
| Fix style errors | `composer cbf`


## Deployment

- Edit the `VERSION` file to contain the version to release, and commit the change.
- Run `composer release`

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [releases](https://github.com/g1a/hubph/releases) page.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details
