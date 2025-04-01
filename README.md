[![CS](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/cs.yml/badge.svg)](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/cs.yml)
[![PHPStan](https://github.com/Emilia-Capital/aaa-option-optimizer/actions/workflows/phpstan.yml/badge.svg)](https://github.com/Emilia-Capital/aaa-option-optimizer/actions/workflows/phpstan.yml)
[![Lint](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/lint.yml/badge.svg)](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/lint.yml)
[![Security](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/security.yml/badge.svg)](https://github.com/emilia-capital/aaa-option-optimizer/actions/workflows/security.yml)

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/aaa-option-optimizer.svg)](https://wordpress.org/plugins/aaa-option-optimizer/)
![WordPress Plugin: Tested WP Version](https://img.shields.io/wordpress/plugin/tested/aaa-option-optimizer.svg)
[![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/aaa-option-optimizer.svg)](https://wordpress.org/plugins/aaa-option-optimizer/advanced/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/aaa-option-optimizer.svg)](https://wordpress.org/plugins/aaa-option-optimizer/advanced/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/aaa-option-optimizer.svg)](https://wordpress.org/support/plugin/aaa-option-optimizer/reviews/)
[![GitHub](https://img.shields.io/github/license/ProgressPlanner/aaa-option-optimizer.svg)](https://github.com/ProgressPlanner/aaa-option-optimizer/blob/main/LICENSE)

[![Try this plugin on the WordPress playground](https://img.shields.io/badge/Try%20this%20plugin%20on%20the%20WordPress%20Playground-%23117AC9.svg?style=for-the-badge&logo=WordPress&logoColor=ddd)](https://playground.wordpress.net/#%7B%22landingPage%22:%22/wp-admin/tools.php?page=aaa-option-optimizer%22,%22features%22:%7B%22networking%22:true%7D,%22steps%22:%5B%7B%22step%22:%22defineWpConfigConsts%22,%22consts%22:%7B%22IS_PLAYGROUND_PREVIEW%22:true%7D%7D,%7B%22step%22:%22login%22,%22username%22:%22admin%22,%22password%22:%22password%22%7D,%7B%22step%22:%22installPlugin%22,%22pluginZipFile%22:%7B%22resource%22:%22url%22,%22url%22:%22https://bypass-cors.altha.workers.dev/https://github.com/Emilia-Capital/aaa-option-optimizer/archive/refs/heads/develop.zip%22%7D,%22options%22:%7B%22activate%22:true%7D%7D%5D%7D)

# AAA Option Optimizer
This plugin tracks which of the autoloaded options are used on a page, and stores that data at the end of page render. It keeps an array of options that it has seen as being used. On the admin page, it compares all the autoloaded options to the array of stored options, and shows the autoloaded options that have not been used as you were browsing the site. If you've been to every page on your site, or you've kept the plugin around for a week or so, this means that those options probably don't need to be autoloaded.

## How to use this plugin
Install this plugin, and go through your entire site. Best is to use it normally for a couple of days, or to visit every page on your site and in your admin manually. Then go to the plugin's settings screen, and go through the unused options. You can either decide to remove an unused option (they might for instance be for plugins you no longer use), or to set it to not autoload. The latter action is much less destructive: it'll still be there, but it just won't be autoloaded.

![Screenshot of the admin panel](/.wordpress-org/screenshot-1.png)

## Frequently Asked Questions

### Why the AAA prefix in the plugin name?

Because the plugin needs to measure options being loaded, it benefits from being loaded itself first. As WordPress loads plugins alphabetically, 
starting the name with AAA made sense.

### Do I need to take precautions?

Yes!! Backup your database.

### How can I add recognized plugins?

Please do a pull request via GitHub on [this file](https://github.com/Emilia-Capital/aaa-option-optimizer/blob/develop/known-plugins/known-plugins.json) in the plugin.

### How can I report security bugs?

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/aaa-option-optimizer)
