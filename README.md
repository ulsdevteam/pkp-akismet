# Akismet anti-spam plugin for OJS

This plugin verifies new user registrations via the [Akismet anti-spam service](http://akismet.com/).  Subscription to Akismet is required.

## Requirements

* OJS 2.4.x
* PHP 5.3 or later
* Akismet API key

## Installation

Install this as a "generic" plugin in OJS.  To install manually via the filesystem, extract the contents of this archive to a directory (e.g. "akismet") under "plugins/generic" in your OJS root.  To install via Git submodule, target that same directory path: `git submodule add https://github.com/ulsdevteam/pkp-akismet plugins/generic/akismet` and `git submodule update --init --recursive plugins/generic/akismet`.  Run the upgrade script to register this plugin, e.g.: `php tools/upgrade.php upgrade`

## Configuration

You will need to provide your Akismet API key within the plugin settings.

## Usage

When a new user registers within a journal context, the user input will be submitted to Akismet for analysis.  If the user is identified by Akismet as spam, the registration will be blocked.

## Author / License

Written by Clinton Graham for the [University of Pittsburgh](http://www.pitt.edu).  Copyright (c) University of Pittsburgh.

Released under a license of GPL v2 or later.
