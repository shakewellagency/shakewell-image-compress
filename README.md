# Shakewell plugin template

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

The starting point for every Shakewell WordPress plugin. Each plugin built from it is free and open source (GPL-2.0-or-later), Shakewell-owned and maintained, and written so it can be listed in the WordPress plugin directory.

## Rules every plugin follows

- **Small and single-purpose.** Build only what the sites need. Fewer lines means less to attack and less to break.
- **Generic.** Anything site-specific is a setting, a constant or a filter, never a value in code. The plugin must install from its release zip on a plain WordPress site and do something sensible, or explain in an admin notice or the readme what it needs, before anything is configured.
- **No client content anywhere.** No client, staff or host names, ids, buckets or account numbers in code, comments, tests, fixtures, readmes, changelogs, commit messages, tags or the repository description. Use neutral examples (`book`, `author`, `example.test`). Anything one client needs to switch the plugin on belongs in that client's own documentation, not here.
- **No paid dependencies** where it can be avoided. Core WordPress storage first. A plugin that extends ACF or Gravity Forms says so in its readme.
- **Directory rules from day one** ([guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)): GPL, no tracking or calls home, no locked features, every outside service named in the readme, translatable strings.
- **Security basics on every action:** capability check, nonce check, sanitise input, escape output (late, at the point of output), prepared SQL, no secrets in options or logs, and `defined( 'ABSPATH' ) || exit;` at the top of every PHP file that ships (`uninstall.php` checks `WP_UNINSTALL_PLUGIN` instead).
- **Prefix everything.** Functions, classes, constants, options, transients, hooks, query vars, script and style handles and CSS ids start with `shakewell_<name>` or sit in the `Shakewell\<Name>` namespace. Enqueue scripts and styles with `wp_enqueue_*`; no inline `<script>` or `<style>` blocks. Use the libraries WordPress bundles instead of shipping copies.
- **Nothing remote.** No telemetry, no calls home, no CDN-hosted assets, no downloading or running code, no own updater. A call to an outside service is off until an admin opts in, and the readme lists it under "External services" with what is sent, when, and links to the service's terms and privacy policy.
- **No nagging.** No upsells, forced credits or links, or admin takeover. A notice is dismissible and appears only where the plugin needs attention.
- **readme.txt:** `Contributors: shakewellagency` (the WordPress.org account), at most 5 tags, a short description of at most 150 characters that says what the plugin does, no keyword stuffing, `Tested up to` set to the current WordPress major (check https://api.wordpress.org/core/version-check/1.7/), `Stable tag` equal to the header version, and a changelog entry for every release.
- **Dependencies.** When the plugin needs another plugin that is on WordPress.org, declare it with a `Requires Plugins:` header in `shakewell-<name>.php`, next to `Requires at least` and `Requires PHP`.
- **Trademarks.** Another product's name appears only as "for X" at the end of the plugin name, never at the start.
- **Licence.** Everything in the zip, including bundled libraries and assets, is GPL-2.0-or-later compatible. Keep any copyright or authorship notice a file already carries. Minified files ship with their readable source.
- **Naming:** repository, folder, main file, slug and text domain are all `shakewell-<name>`. PHP namespace `Shakewell\<Name>`, global prefix `shakewell_<name>_`. Never start a name with someone else's trademark ("Shakewell Mailer for Amazon SES", not "Amazon SES Mailer"), and never use the words "plugin", "WordPress" or "WP" in a name: the directory refuses them. The template's own name breaks this on purpose; it is never listed.

## Make a new plugin

1. On GitHub: **Use this template** → `shakewellagency/shakewell-<name>`, public from the start.
2. Rename, all in one go: `shakewell-plugin-template` → `shakewell-<name>`, `Shakewell\PluginTemplate` → `Shakewell\<Name>`, `shakewell_plugin_template` → `shakewell_<name>`, `Shakewell Plugin Template` → the plugin's name. Rename the main file to `shakewell-<name>.php`.
3. Write `readme.txt` (description, external services, changelog), keeping `Contributors: shakewellagency`, and turn on private vulnerability reporting (Settings → Code security). Set the repository description and topics to say what the plugin does, with no client names.

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8888 (admin / password), needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check, as the directory runs it
```

CI runs the same on every pull request, on the lowest supported WordPress and PHP and on the latest.

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line. Every release gets a new version.
2. Build the zip (`git archive`, as the release workflow does), unzip it, and run Plugin Check against that folder as well as the repository, so you test what ships. The zip must hold no tests, lock files, `.github` or other development files.
3. Tag `vX.Y.Z` and push. The release workflow checks the three versions match and attaches `shakewell-<name>.zip` to a GitHub release.

## Install with Composer

Until a plugin is listed, install the zip from its GitHub release, pinned to a version with a checksum:

```json
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "shakewellagency/shakewell-<name>",
      "version": "1.0.0",
      "type": "wordpress-plugin",
      "dist": {
        "url": "https://github.com/shakewellagency/shakewell-<name>/releases/download/v1.0.0/shakewell-<name>.zip",
        "type": "zip",
        "shasum": "<sha1 of the zip>"
      },
      "require": { "php": ">=8.1", "composer/installers": "^2.3" }
    }
  }
],
"require": { "shakewellagency/shakewell-<name>": "1.0.0" }
```

Get the checksum with `shasum -a 1 shakewell-<name>.zip`.

Use the zip, not a `vcs` repository. A source install puts tests and repository files under the web root, while the zip leaves them out. The build needs no GitHub token and makes no GitHub API calls. Copy the plugin's PHP requirement into `require`, because Composer doesn't read the plugin's own `composer.json` here.

A plugin with runtime Composer libraries must ship `vendor/` in its zip. The template's release workflow uses `git archive`, which leaves `vendor/` out, so add `composer install --no-dev` and build the zip from `.distignore`, as `shakewell-saml-sso` does.

Once a plugin is listed, switch to `wpackagist-plugin/shakewell-<name>` and drop this entry.

## Made by Shakewell

Plugins built from this template join a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).

In a new plugin, change the first sentence of this section to say what the plugin is part of.
