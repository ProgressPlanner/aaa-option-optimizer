=== AAA Autoload Optimizer ===
Contributors: joostdevalk, aristath, filipi, progressplanner
Tags: autoload, options, database, performance, cleanup
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 1.7.1
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

Find and fix bloated autoloaded options slowing down your WordPress database

== Description ==

Every WordPress site loads dozens — sometimes hundreds — of autoloaded
options from the `wp_options` table on every single page request. Many of
those options belong to plugins you removed long ago, or simply don't need
to be autoloaded. The result: a bigger database query, slower page loads,
and wasted server resources.

**AAA Autoload Optimizer** tracks which autoloaded options your site actually
uses, then shows you the ones it doesn't — so you can safely remove them
or turn off autoloading.

== How It Works ==

1. Install the plugin and browse your site normally for a few days.
2. The plugin quietly records which autoloaded options are actually used
   during page rendering.
3. Visit the settings screen to see every autoloaded option that was
   *never* used — and decide what to do with it.

You can **remove** unused options entirely (great for leftover data from
deactivated plugins) or **disable autoloading** for options that exist but
don't need to load on every request. Disabling autoloading is
non-destructive — the option stays in your database, it just won't be
fetched automatically.

== Features ==

- **Automatic usage tracking** — monitors which autoloaded options are
  loaded on every page, on both the front end and in the admin.
- **Bulk actions** — optimize or delete multiple options at once instead
  of one by one.
- **Known-plugin recognition** — identifies which plugin or theme each
  option belongs to, so you know exactly what you're looking at.
- **"All Options" browser** — view and manage every option in your
  `wp_options` table, not just the unused ones.
- **Source filtering** — filter options by their source plugin, theme, or
  WordPress core to quickly find what you need.
- **Safe by design** — disabling autoload is non-destructive. You can
  always re-enable it.
- **Lightweight** — the plugin's own tracking data is stored in a custom
  database table for optimal performance.

== Why the "aaa" Slug? ==

WordPress loads plugins alphabetically. Because this plugin needs to start
tracking options as early as possible, the `aaa-option-optimizer` slug
ensures it loads first. It's not vanity — it's a technical requirement for
accurate measurement.

== Links ==

- [GitHub repository](https://github.com/ProgressPlanner/aaa-option-optimizer/)
  — report bugs, contribute code, or browse the source.
- [Report a security vulnerability](https://patchstack.com/database/vdp/aaa-option-optimizer)
  — via the Patchstack Vulnerability Disclosure Program.
- Built by the team behind
  [Progress Planner](https://progressplanner.com/).

== Installation ==

= Automatic installation =

1. In your WordPress dashboard, go to **Plugins → Add New**.
2. Search for **AAA Autoload Optimizer**.
3. Click **Install Now**, then **Activate**.
4. Browse your site normally for a few days — or visit every page
   manually if you're in a hurry.
5. Go to **Tools → Autoload Optimizer** to review and optimize your
   autoloaded options.

= Manual installation =

1. Download the plugin zip file.
2. Upload it via **Plugins → Add New → Upload Plugin**, or extract it
   to `/wp-content/plugins/aaa-option-optimizer/` via FTP.
3. Activate the plugin through the **Plugins** menu.
4. Follow steps 4–5 above.

== Frequently Asked Questions ==

= Will this plugin break my site? =

The safest action — disabling autoload — is non-destructive. The option
remains in your database and can still be loaded on demand. Deleting an
option is permanent, so only do that for options you're confident are no
longer needed (e.g., from plugins you've already removed). Always back up
your database before making bulk changes.

= Can I undo changes? =

Disabling autoload can be reversed from the "All Options" tab. Deleting
an option is permanent. If you need it back, you'll need to restore from
a database backup.

= How long should I wait before optimizing? =

A few days of normal browsing is usually enough. If you have a lot of 
visitors, a few hours might even suffice. The goal is to visit every major 
page on your site (front end and admin) so the plugin can record which 
options are actually used. If you're thorough, you can start optimizing 
sooner.

= Does this work with WordPress multisite? =

The plugin currently targets single-site installations. Multisite
compatibility has not been officially tested.

= How do I know which options are safe to remove? =

The plugin identifies the source of each option (which plugin, theme, or
WordPress core). If an option belongs to a plugin you've already
deactivated and removed, it's generally safe to delete. When in doubt,
disable autoloading instead of deleting — it's the safer choice.

= Do I need to take precautions? =

Yes, always back up your database before optimizing. Disabling autoload
is safe and reversible, but deleting options is permanent.

= Why are some option names not tracked? =

Names that can't be real options are ignored: empty names and names longer
than 191 characters. Developers can exclude more names with the
`aaa_option_optimizer_is_trackable_option_name` filter, which receives
whether the name should be tracked and the option name. Keep in mind that
an autoloaded option excluded this way will be listed as unused. The 191
character limit can't be changed.

= Where can I report bugs? =

Please use [the GitHub repository](https://github.com/ProgressPlanner/aaa-option-optimizer/)
for bug reports and code suggestions. The WordPress.org support forum
works too for general questions.

= How can I report security issues? =

Through the Patchstack Vulnerability Disclosure Program. The Patchstack
team validates, triages, and handles all security reports.
[Report a security vulnerability here.](https://patchstack.com/database/vdp/aaa-option-optimizer)

= How can I add my plugin to the known-plugins list? =

Submit a pull request on GitHub to
[known-plugins.json](https://github.com/ProgressPlanner/aaa-option-optimizer/blob/develop/known-plugins/known-plugins.json).

== Screenshots ==

1. The main dashboard showing unused autoloaded options with their size,
   source plugin, and action buttons.
2. The "All Options" tab lets you browse and manage every option in your
   wp_options table.
<!-- TODO: add screenshot showing bulk actions in use -->
<!-- TODO: add screenshot showing the source filtering feature -->

== Changelog ==

= 1.7.1 =

Security:

* Hardened the escaping of option data in the admin screens.

Bug fixes:

* Fix the autoload toggle and delete button not updating rows when the
  table columns are collapsed on smaller screens.
* Fix tables on hidden tabs not fitting their panel.
* Show the correct autoload button for options with the "off" and
  "auto-off" autoload values.
* Fix bulk delete for option names that contain a dot.
* Fix duplicate element ids when the same option appears in more than
  one table.

Enhancements:

* Add the `aaa_option_optimizer_is_trackable_option_name` filter to
  exclude option names from tracking.

= 1.7.0 =

* Rename the plugin to AAA Autoload Optimizer.
* Fix database deadlocks when concurrent requests write tracked options.
* Fix a fatal error when W3 Total Cache's database cache is active.
* Readme improvements.

= 1.6.1 =

* Fix infinite recursion in option access monitoring that could cause a
  fatal error in certain hosting environments.

= 1.6.0 =

* Replace using 'all' filter for monitoring option usage with
  'pre_option' filter for better performance.
* Migrate tracked options data from a single wp_option to a custom
  database table for improved performance and reliability.

= 1.5.1 =

* Add "select all" checkbox.
* Fix table filtering by Source column.

= 1.5.0 =

* Prefix the Datatables script slug to avoid conflict with other plugins.
* Add MainWP to known-plugins list
* Add SliceWP to known-plugins list
* Add more known prefixes for WooCommerce options
* Fixed a bug in bulk-actions

= 1.4.0 =

* Performance improvements.
* Added bulk-actions to allow optimizing & deleting options in bulk.
* Added more known plugins.

= 1.3.2 =

* Performance fix: Do not autoload the plugin option.

= 1.3.1 =

* Fix JS error when deleting an option.

= 1.3 =

* Make plugin work with the latest autoload changes.

= 1.2.1 =

* Fix error in `known-plugins.json`.
* Prevent fatal error when there's an error in `known-plugins.json`.

= 1.2 =

Enhancements:

* Overhaul of the UX, implementing proper tabs, better buttons and more.
* Added a new "All options" tab, which, when you hit the button, loads
  all the options from the database and allows you to manage them.
* Added a "Reset data" button which resets the tracking data.
* Added a link to the Optimize Options page from the Plugins page.
* Much improved recognition of core WordPress options, themes and plugins
  under "Source", thanks in part to pull requests from
  [Rogier Lankhorst](https://profiles.wordpress.org/rogierlankhorst/)
  and [system4pc](https://github.com/system4pc).
* You can now also filter all tables by Source so you can more easily
  find the options you're looking for.
* Many code and speed improvements under the hood, including adding a
  class autoloader and some i18n fixes.

= 1.1.1 =

Implement the missing functionality to create an option with value
`false` when it's being loaded but doesn't exist.

= 1.1 =

The plugin now recognizes plugins from which the options came (thanks to
a great pull by
[Rogier Lankhorst](https://profiles.wordpress.org/rogierlankhorst/)).
If you're a plugin developer and want your plugin's options properly
recognized, please do a pull request on
[this file](https://github.com/ProgressPlanner/aaa-option-optimizer/blob/main/known-plugins/known-plugins.json).

Small enhancements:

* Column width is now automatically determined which leads to better
  spacing.
* Action buttons are now centered in their columns.

Bugs fixed:

* If you removed autoload from or deleted an option, it'd be removed
  from the table but would be back when you paginated, that's fixed -
  thanks to
  [Jono Alderson](https://profiles.wordpress.org/jonoaldersonwp/)
  for reporting.
* Fixed sorting by filesize by moving the `KB` to the table heading, so
  that you can now properly sort numbers.
* Fixed issue where an empty option would result in weird size output.

= 1.0.2 =

* Fixed a bug where the buttons wouldn't work in a paginated state.
* Show the value of an option in a `popover`, as suggested with a great
  pull request by
  [@rogierlankhorst](https://profiles.wordpress.org/rogierlankhorst/).

= 1.0.1 =

Fixed an error with values that are objects, not strings, which also
caused sorting not to work for some people.

= 1.0 =

Initial release on GitHub and WordPress.org.

== Upgrade Notice ==

= 1.7.0 =
The plugin is now called AAA Autoload Optimizer. Also fixes bugs with
concurrent requests and W3 Total Cache.

= 1.6.1 =
Fixes a potential fatal error from infinite recursion in option
monitoring on certain hosts.

= 1.6.0 =
Performance and reliability: option tracking now uses a custom database
table instead of a single wp_option.
