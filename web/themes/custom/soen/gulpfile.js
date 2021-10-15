/* eslint-env node */

/**
 * @file
 * Provides Gulp configurations and tasks for compiling soen
 * CSS files from SASS files.
 *
 * We are mostly reusing configurations from parent themes, creating new
 * tasks for child theme when needed and making sure we create nice gulp task
 * groups so developer UX is nice.
 */

'use strict';

// Load gulp and needed lower level libs.
var gulp = require('gulp');
var yaml = require('js-yaml');
var fs = require('fs');
var merge = require('deepmerge');

// Load gulp options first from this theme.
// @note - Be sure to define proper base themes relative paths first in
// gulp-options.yml. Most of the time default provided path is OK.
var options = yaml.safeLoad(fs.readFileSync('./gulp-options.yml', 'utf8'));

// Deep merge with gulp options from parent themes.
for (var theme of options.parentTheme) {
  var parentThemeOptions = yaml.safeLoad(fs.readFileSync(theme.path + 'gulp-options.yml', 'utf8'));
  // Due to change in deepmerge 2.x we need to remove parentTheme because it will not be properly merged.
  delete parentThemeOptions.parentTheme;
  options = merge(parentThemeOptions, options);
}

// Load theme options if theme-options.yml file exist and merge it with
// options.sass variable.
if (fs.existsSync('./theme-options.yml')) {
  var themeOptions = yaml.safeLoad(fs.readFileSync('./theme-options.yml', 'utf8'));
  if (themeOptions && typeof themeOptions.sass != 'undefined') {
    options.sass = merge(options.sass, themeOptions.sass);
  }
}

// Add parent path of parent themes so we can do simple
//
//   @import "bs_base/sass/init";
//
// in our sass files.
for (var theme of options.parentTheme) {
  options.sass.includePaths.push(theme.path + '../');
}

// Automatic lazy loading of gulp plugins.
var plugins = require('gulp-load-plugins')(options.gulpPlugins);

// Load gulp tasks from parent theme and this theme.
for (var theme of options.parentTheme.reverse()) {
  require(theme.path + 'gulp-tasks.js')(gulp, plugins, options);
}
require('./gulp-tasks.js')(gulp, plugins, options);
