<?php

namespace Drupal\bs_lib\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drush\Commands\DrushCommands;
use Exception;
use stdClass;

/**
 * Drush commands for bs_lib module.
 */
class BsLibCommands extends DrushCommands {

  /**
   * BsLibCommands constructor.
   */
  public function __construct(ThemeHandlerInterface $theme_hanldler) {
    // For easier maintenance we just wrap the methods from bs_base here.
    $bs_base = $theme_hanldler->getTheme('bs_base');
    include_once "{$bs_base->getPath()}/bs_base.drush.inc";
  }

  /**
   * Create a new bs_base compatible child theme.
   *
   * @command bs:theme-create
   *
   * @param string $parent_machine_name Parent theme machine name.
   * @param string $child_machine_name Child theme machine name.
   * @param string $child_name Child theme name.
   * @param string $child_description Child theme description.
   *
   * @bootstrap root
   * @aliases bs-tc,bs-theme-create
   *
   * @usage drush bs-tc bs_bootstrap custom_theme 'Custom theme' 'Custom theme description'
   *   Create a new bs_base compatible child theme.
   *
   * @throws \Exception
   */
  public function themeCreate($parent_machine_name, $child_machine_name, $child_name, $child_description) {
    // Verify that the child machine name contains no disallowed characters.
    if (preg_match('@[^a-z0-9_]+@', $child_machine_name)) {
      throw new \Exception('The machine-readable name "' . $child_machine_name . '" must contain only lowercase letters, numbers, and hyphens.');
    }

    $this->output()->writeln("Starting $child_machine_name theme creation");

    // Parent theme should exist.
    $parent_path = $this->drupalGetThemePath($parent_machine_name);
    if (empty($parent_path)) {
      throw new \Exception('Parent theme does not exist.');
    }

    // Child theme should not exist.
    if (!empty($child_path = $this->drupalGetThemePath($child_machine_name))) {
      throw new \Exception("Child theme already exist on $child_path file system.");
    }

    // Create child theme directory.
    $child_path = 'themes/custom/' . $child_machine_name;
    if (!mkdir($child_path, 0755, TRUE)) {
      throw new \Exception("Failed to create child theme directory on $child_path path.");
    }

    $options = [
      'parent_machine_name' => $parent_machine_name,
      'parent_path' => $parent_path,
      'child_machine_name' => $child_machine_name,
      'child_path' => $child_path,
      'child_name' => $child_name,
      'child_description' => $child_description,
    ];

    // Copy files from parent and change/apply text changes to labels.
    $this->copyThemeFiles($options);

    // Replace text in copied files.
    $this->reconfigureThemeFiles($options);

    // Copy theme-options.yml from parent theme. Try first to copy template if
    // it exist, if not copy theme-options.yml.
    $theme_options_filename = FALSE;
    if (file_exists($options['parent_path'] . '/template.theme-options.yml')) {
      $theme_options_filename = 'template.theme-options.yml';
    }
    elseif (file_exists($options['parent_path'] . '/theme-options.yml')) {
      $theme_options_filename = 'theme-options.yml';
    }
    if ($theme_options_filename && !copy($options['parent_path'] . '/' . $theme_options_filename, $options['child_path'] . '/' . 'theme-options.yml')) {
      throw new \Exception("Failed to copy $theme_options_filename file from {$options['parent_path']} to {$options['child_path']}.");
    }

    // Generate some files from the scratch.
    $this->generateFile("config/schema/{$child_machine_name}.schema.yml", $options);
    $this->generateFile('gulp-options.yml', $options);
    $this->generateFile($child_machine_name . '.info.yml', $options);
    $this->generateFile($child_machine_name . '.libraries.yml', $options);
    $this->generateFile('README.md', $options);

    // Rebuild themes static cache because new theme is created.
    $this->drupalThemeListInfo(TRUE);

    // Make sure we are on latest parent theme versions.
    $update_functions = $this->GetUpdateHooks($child_machine_name);
    if (!empty($update_functions)) {
      $bs_versions = [];
      foreach ($update_functions as $theme_name => $theme_updates) {
        // Get last update.
        end($theme_updates['functions']);
        $last_function = key($theme_updates['functions']);
        $bs_versions['bs_versions.' . $theme_name] = (int) $last_function;
      }

      $all_themes = $this->drupalThemeListInfo();
      $this->setYmlValue($all_themes[$child_machine_name]->pathname, $bs_versions, TRUE);
    }

    // Update and flatten SASS files.
    $this->updateSassFiles($child_machine_name);

    // Rebuild CSS files.
    $this->themeBuild($child_machine_name);
  }

  /**
   * Update existing bs_lib compatible child theme.
   *
   * @command bs:theme-update
   *
   * @param string $target_machine_name Theme machine name.
   * @bootstrap root
   * @aliases bs-tu,bs-theme-update
   *
   * @usage drush bs-tu custom_theme
   *   Create a new bs_base compatible child theme.
   * @throws \Exception
   */
  public function themeUpdate($target_machine_name) {
    $this->output()->writeln("Updating a $target_machine_name theme");

    $target_path = $this->drupalGetThemePath($target_machine_name);
    if (empty($target_path)) {
      throw new \Exception('Target theme does not exist.');
    }

    $parent_themes = $this->getParentThemes($target_machine_name);
    if (empty($parent_themes)) {
      throw new \Exception('Parent themes are missing.');
    }

    // Run update hooks.
    $this->themeRunUpdateHooks($target_machine_name);

    $all_themes = $this->drupalThemeListInfo();
    $first_parent_machine_name = $this->drupalGetParentThemeName($target_machine_name);

    $this->updateSassFiles($target_machine_name);

    // Check for any new or removed CSS library in parent theme and update
    // libraries-override section.
    $parent_theme_libraries_override = $this->generateLibrariesOverride($first_parent_machine_name);
    $target_info_array = $all_themes[$target_machine_name]->info;
    $target_theme_libraries_override = $target_info_array['libraries-override'];
    // Keep only the libraries from target that are not from parents.
    foreach ($target_theme_libraries_override as $library_key => $library_value) {
      // We have it in parent already.
      if (isset($parent_theme_libraries_override[$library_key])) {
        unset($target_theme_libraries_override[$library_key]);
      }
      // We do not have it in parent but it does belong to parent themes. We
      // assume that library was removed from parent theme or that it is in
      // parent parents. In this case we will remove it from here.
      elseif ($this->libraryKeyComingFromParents($library_key, $target_machine_name)) {
        unset($target_theme_libraries_override[$library_key]);
      }
    }
    // We start from parent generated libraries override.
    $new_libraries_override = array_merge($parent_theme_libraries_override, $target_theme_libraries_override);
    // Update info file with new libraries override.
    $info_content = file_get_contents($all_themes[$target_machine_name]->pathname);
    $info_content = preg_replace('/^libraries-override:\R(^ +.+\R)+/m', Yaml::encode(['libraries-override' => $new_libraries_override]), $info_content);
    file_put_contents($all_themes[$target_machine_name]->pathname, $info_content);

    // Rebuild CSS files.
    $this->themeBuild($target_machine_name);
  }

  /**
   * Run build-css script in provided theme.
   *
   * @command bs:theme-build
   *
   * @param string $theme_machine_name Theme machine name.
   *
   * @bootstrap root
   * @aliases bs-tb,bs-theme-build
   *
   * @usage drush bs-tb custom_theme
   *   Download custom_theme build dependencies and build all CSS files.
   */
  public function themeBuild($theme_machine_name) {
    $this->output()->writeln("Building CSS asset for a $theme_machine_name theme");

    $target_path = $this->drupalGetThemePath($theme_machine_name);
    if (empty($target_path)) {
      throw new \Exception("Target theme {$theme_machine_name} does not exist.");
    }

    $this->drushBuildCss($target_path);
  }

  /**
   * Returns array with unique lines.
   *
   * This will eliminate all duplicate lines in array but it will also compare
   * commented lines with not commented and eliminate that duplicates also. For
   * example:
   *
   * array(
   *   '@import "bs_bootstrap/sass/components/partials/alert";',
   *   '//@import "bs_bootstrap/sass/components/partials/alert";',
   * )
   *
   * This two values are considered duplicates also and the result will be
   *
   * array(
   *   '//@import "bs_bootstrap/sass/components/partials/alert";',
   * )
   *
   * @param array $lines
   *   Array of lines.
   *
   * @return array
   *   Array of unique lines.
   */
  protected function arrayUniqueLines(array $lines) {
    return _bs_base_array_unique_lines($lines);
  }

  /**
   * Copy general theme files from parent theme to child theme.
   *
   * @param array $options
   *   Array of options having next keys:
   *   - parent_machine_name
   *   - parent_path
   *   - child_path
   *   - child_name
   *   - child_description.
   */
  protected function copyThemeFiles(array $options) {
    _bs_base_copy_theme_files($options);
  }

  /**
   * Copy file from parent theme to child theme.
   *
   * If parent file is a directory then it will be created.
   *
   * Target sub-directories of a file will be created automatically if they do not
   * exists.
   *
   * @param string $file
   *   File name.
   * @param array $options
   *   Array of options having next keys:
   *   - parent_machine_name
   *   - parent_path
   *   - child_machine_name
   *   - child_path.
   *
   * @return bool
   *   TRUE if the file was copied, FALSE otherwise.
   */
  protected function copyFile($file, array $options) {
    return _bs_base_copy_file($file, $options);
  }

  /**
   * Finds all the base themes for the specified theme.
   *
   * @param array $themes
   *   An array of available themes.
   * @param string $theme
   *   The name of the theme whose base we are looking for.
   *
   * @return array
   *   Returns an array of all of the theme's ancestors including specified
   *   theme.
   */
  protected function drupalGetBaseThemes(array $themes, $theme) {
    return _bs_base_drupal_get_base_themes($themes, $theme);
  }

  /**
   * Returns the first parent theme of passed child theme.
   *
   * @param string $theme_name
   *   The name of the child theme whose first parent theme we are looking for.
   *
   * @return string|NULL
   *   Returns a theme machine name of first parent theme or NULL if parent does
   *   not exist.
   */
  protected function drupalGetParentThemeName($theme_name) {
    return _bs_base_drupal_get_parent_theme_name($theme_name);
  }

  /**
   * Returns the path to a Drupal theme.
   *
   * @param string $name
   *   Theme machine name.
   *
   * @return string
   *   The path to the requested theme or an empty string if the item is not
   *   found.
   */
  protected function drupalGetThemePath($name) {
    return _bs_base_drupal_get_theme_path($name);
  }

  /**
   * Discovers available extensions of a given type.
   *
   * For an explanation of how this work see ExtensionDiscovery::scan().
   *
   * @param string $type
   *   The extension type to search for. One of 'profile', 'module', 'theme', or
   *   'theme_engine'.
   * @param bool $reset
   *   Reset internal cache.
   *
   * @return stdClass[]
   *   An associative array of stdClass objects, keyed by extension name.
   */
  protected function drupalScan($type, $reset = FALSE) {
    return _bs_base_drupal_scan($type, $reset);
  }

  /**
   * Recursively scans a base directory for the extensions it contains.
   *
   * @see ExtensionDiscovery::scanDirectory()
   *   For an explanation of how this works.
   *
   * @param string $dir
   *   A relative base directory path to scan, without trailing slash.
   *
   * @return stdClass[]
   *   An associative array of stdClass objects, keyed by extension name.
   */
  protected function drupalScanDirectory($dir) {
    return _bs_base_drupal_scan_directory($dir);
  }

  /**
   * Get information's for all themes.
   *
   * @param bool $reset
   *   Reset internal cache.
   *
   * @return array
   *   Array holding themes information's.
   */
  protected function drupalThemeListInfo($reset = FALSE) {
    return _bs_base_drupal_theme_list_info($reset);
  }

  /**
   * Finds all parent themes for the specified theme.
   *
   * @param string $theme_machine_name
   *   The machine name of the theme whose parent themes we are looking for.
   *
   * @return array
   *   Returns an array of all of the parent themes.
   */
  protected function getParentThemes($theme_machine_name) {
    return _bs_base_get_parent_themes($theme_machine_name);
  }

  /**
   * Get theme information.
   *
   * @param string $theme_machine_name
   *   Theme machine name.
   *
   * @return mixed|null
   *   Theme info object or NULL if theme does not exist.
   */
  protected function getThemeInfo($theme_machine_name) {
    return _bs_base_get_theme_info($theme_machine_name);
  }

  /**
   * Get all child themes for passed parent_theme.
   *
   * @todo Remove? Doesn't seem to be used.
   *
   * @param string $parent_theme
   *   Machine name of parent theme.
   *
   * @return array
   *   Array of all child themes machine names. Empty array if child themes does
   *   not exist.
   */
  protected function findChildThemes($parent_theme) {
    return _bs_base_find_child_themes($parent_theme);
  }

  /**
   * Check that library is coming from theme parent themes or bs_lib module.
   *
   * @param string $library_key
   *   Library key.
   * @param string $theme_machine_name
   *   Theme machine name.
   *
   * @return bool
   *   TRUE if library is coming from parents, FALSE other way.
   */
  protected function libraryKeyComingFromParents($library_key, $theme_machine_name) {
    return _bs_base_library_key_coming_from_parents($library_key, $theme_machine_name);
  }

  /**
   * Replace text in theme files so all configurations are correct.
   *
   * @param array $options
   *   Array of options having next keys:
   *   - parent_machine_name
   *   - parent_path
   *   - child_path
   *   - child_name
   *   - child_description.
   *
   * @throws \Exception
   */
  protected function reconfigureThemeFiles(array $options) {
    _bs_base_reconfigure_theme_files($options);
  }

  /**
   * Get theme update functions.
   *
   * @param string $target_machine_name
   *   Target theme machine name.
   *
   * @return array
   *   Array of update functions.
   */
  protected function getUpdateHooks($target_machine_name) {
    return _bs_base_get_update_hooks($target_machine_name);
  }

  /**
   * Run theme update functions.
   *
   * @param string $target_machine_name
   *   Target theme machine name.
   *
   * @throws \Exception
   */
  protected function themeRunUpdateHooks($target_machine_name) {
    $update_functions = $this->getUpdateHooks($target_machine_name);
    if (empty($update_functions)) {
      $this->output()->writeln("No theme updates required.");
      return;
    }

    // Print a list of pending updates for this module and get confirmation.
    $this->output()->writeln('The following updates are pending:');
    $this->output()->writeln(drush_html_to_text('<h2>'));

    foreach ($update_functions as $theme_name => $theme_updates) {
      $this->output()->writeln($theme_name . ' theme : ');
      foreach ($theme_updates['functions'] as $version => $description) {
        $this->output()->writeln(' ' . $version . ' -   ' . strip_tags($description));
      }
    }
    $this->output()->writeln(drush_html_to_text('<h2>'));

    if (!$this->confirm('Do you wish to run all pending updates?', TRUE)) {
      return;
    }

    $this->output()->writeln(drush_html_to_text('<h2>'));
    $this->output()->writeln('Running next updates:');

    // Load install files and execute update functions.
    $bs_versions = [];
    foreach ($update_functions as $theme_name => $theme_updates) {
      include_once $theme_updates['file'];
      foreach ($theme_updates['functions'] as $version => $description) {
        $update_function = $theme_name . '_bs_update_' . $version;
        $this->output()->writeln('  ' . $version . ' -   ' . strip_tags($description));
        $update_function($target_machine_name);
      }

      // Update theme info bs_versions.
      $bs_versions['bs_versions.' . $theme_name] = (int) $version;
    }

    // Update info file with latest versions.
    $all_themes = $this->drupalThemeListInfo();
    $this->setYmlValue($all_themes[$target_machine_name]->pathname, $bs_versions, TRUE);
  }

  /**
   * Run build-css script in provided theme path.
   *
   * @param string $path
   *   Path to theme folder.
   */
  protected function drushBuildCss($path) {
    _bs_base_drush_build_css($path);
  }

  /**
   * Ensure that theme filename has all directories.
   *
   * @param string $filename
   *   Filename with optional subdirectories in path that we ensure they exist.
   * @param string $path
   *   Base path in which we will check $filename subdirectories.
   *
   * @return bool
   *   TRUE on success, FALSE on error.
   */
  protected function ensureDirectory($filename, $path) {
    return _bs_base_ensure_directory($filename, $path);
  }

  /**
   * Finds all files that match a given mask in a given directory.
   *
   * For additional information see file_scan_directory().
   *
   * @param string $dir
   *   The base directory or URI to scan, without trailing slash.
   * @param string $mask
   *   The preg_match() regular expression for files to be included.
   * @param array $options
   *   An associative array of additional options, with the following elements:
   *   - 'nomask': The preg_match() regular expression for files to be excluded.
   *     Defaults to the 'file_scan_ignore_directories' setting.
   *   - 'callback': The callback function to call for each match. There is no
   *     default callback.
   *   - 'recurse': When TRUE, the directory scan will recurse the entire tree
   *     starting at the provided directory. Defaults to TRUE.
   *   - 'key': The key to be used for the returned associative array of files.
   *     Possible values are 'uri', for the file's URI; 'filename', for the
   *     basename of the file; and 'name' for the name of the file without the
   *     extension. Defaults to 'uri'.
   *   - 'min_depth': Minimum depth of directories to return files from. Defaults
   *     to 0.
   * @param int $depth
   *   The current depth of recursion. This parameter is only used internally and
   *   should not be passed in.
   *
   * @return
   *   An associative array (keyed on the chosen key) of objects with 'uri',
   *   'filename', and 'name' properties corresponding to the matched files.
   */
  protected function fileScanDirectory($dir, $mask, $options = [], $depth = 0) {
    return _bs_base_file_scan_directory($dir, $mask, $options, $depth);
  }

  /**
   * Flatten all parent theme @import directives in a SASS file.
   *
   * @param object $target_sass_file
   *   Target SASS file object.
   * @param string $target_machine_name
   *   Target theme machine name.
   * @param array $current_themes
   *   Array of current themes.
   * @param array $parent_themes_sass_files
   *   Array of all SASS files from parent theme.
   * @param int $depth
   *   Current recursion depth, internally used.
   *
   * @return array
   *   Returns array of flattened SASS @import directives.
   */
  protected function flattenSassFileImports($target_sass_file, $target_machine_name, array $current_themes, array $parent_themes_sass_files, $depth = 0) {
    return _bs_base_flatten_sass_file_imports($target_sass_file, $target_machine_name, $current_themes, $parent_themes_sass_files, $depth);
  }

  /**
   * Generate child SASS file from parent theme.
   *
   * @param object $parent_sass_file
   *   Plain PHP object holding SASS file information.
   * @param array $options
   *   Array of parent/child options.
   *
   * @return bool
   *   TRUE on success, FALSE other way.
   */
  protected function generateSassFile($parent_sass_file, array $options) {
    return _bs_base_generate_sass_file($parent_sass_file, $options);
  }

  /**
   * Generate theme file with a default content.
   *
   * @param string $file_name
   *   File name.
   * @param array $options
   *   Options array.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function generateFile($file_name, array $options) {
    return _bs_base_generate_file($file_name, $options);
  }

  /**
   * Get all CSS libraries from passed theme that child theme needs to override.
   *
   * @param string $parent_machine_name
   *   Parent theme machine name.
   *
   * @return array
   *   Libraries override array.
   */
  protected function generateLibrariesOverride($parent_machine_name) {
    return _bs_base_generate_libraries_override($parent_machine_name);
  }

  /**
   * Get CSS libraries from theme in libraries override format.
   *
   * @param string $theme_machine_name
   *   Theme machine name from which we are getting CSS libraries.
   *
   * @return array
   *   Arrays of CSS libraries in libraries override format.
   */
  protected function getCssLibrariesForOverride($theme_machine_name) {
    return _bs_base_get_css_libraries_for_override($theme_machine_name);
  }

  /**
   * Get array of all SASS files in the given path.
   *
   * @param string $path
   *   Theme path.
   *
   * @return array
   *   Array of all SASS files in the given path.
   */
  protected function getSassFiles($path) {
    return _bs_base_get_sass_files($path);
  }

  /**
   * Regular expression search and replace in the text.
   *
   * @param string $text
   *   Text to search and replace.
   * @param array $regexps
   *   Array of regexps searches with it replace values.
   * @param string $modifiers
   *   PHP regular expression modifiers.
   * @param string $delimiter
   *   PHP regular expression delimiter.
   *
   * @return string
   *   Replaced text.
   */
  protected function regexp($text, array $regexps, $modifiers = 'm', $delimiter = '%') {
    return _bs_base_regexp($text,$regexps, $modifiers, $delimiter);
  }

  /**
   * Regular expression search and replace in the file.
   *
   * @param string $file_name
   *   File path.
   * @param array $regexps
   *   Array of regexps searches with it replace values.
   *
   * @return bool
   *   TRUE on success, FALSE other way.
   */
  protected function regexpFile($file_name, array $regexps) {
    return _bs_base_regexp_file($file_name, $regexps);
  }

  /**
   * Set primitive values in a yml file.
   *
   * Please note that this implementation is not perfect but exist only to
   * supportthe needs of this drush implementation. There are couple of
   * limitations that are explained in function comments. Most importantly
   * values in values array can be only primitive types for now.
   *
   * @param string $path
   *   Yaml file path.
   * @param array $values
   *   Array with yaml values to set. The element key is a combination of yaml
   *     keys and element value is yaml value. For example:
   *
   *     $values['logo:path'] = 'custom/logo/path'
   *
   *   will do
   *
   *     logo:
   *       path: 'custom/logo/path'.
   * @param bool $add
   *   If TRUE then if value does not exist it will be added.
   *
   * @throws Exception
   *   Throws exception in the case that we try to set non scalar value.
   */
  protected function setYmlValue($path, array $values, $add = FALSE) {
    _bs_base_set_yml_value($path, $values, $add);
  }

  /**
   * Update SASS files in theme and do SASS import flattening.
   *
   * @param string $theme_machine_name
   *   Theme machine name.
   */
  protected function updateSassFiles($theme_machine_name) {
    _bs_base_update_sass_files($theme_machine_name);
  }

}
