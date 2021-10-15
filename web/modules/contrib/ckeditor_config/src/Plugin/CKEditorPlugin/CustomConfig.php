<?php

namespace Drupal\ckeditor_config\Plugin\CKEditorPlugin;

use Drupal\ckeditor\Plugin\CKEditorPlugin\Internal;
use Drupal\editor\Entity\Editor;

/**
 * Allow custom config settings.
 *
 * @CKEditorPlugin(
 *   id = "custom_config",
 *   label = @Translation("Custom CKEditor config")
 * )
 */
class CustomConfig extends Internal {

  /**
   * {@inheritdoc}
   */
  public function getConfig(Editor $editor) {
    // Get default config.
    $config = parent::getConfig($editor);

    // Parse and implement the custom config.
    /** @var \Drupal\Core\Config\ConfigFactory $configFactory */
    $configFactory = \Drupal::service('config.factory');
    $configForm = $configFactory->get('ckeditor_config.config_form');
    $customConfig = $configForm->get('config');
    if (!empty($customConfig)) {
      // Separate custom config textarea to each row.
      $configArray = explode("\r\n", $customConfig);
      if (is_array($configArray)) {
        foreach ($configArray as $configRow) {
          $configParts = explode('=', $configRow);
          if (count($configParts) == 2) {
            // Prepare value (remove " and ').
            $value = trim($configParts[1]);
            $value = str_replace('"', '', $value);
            $value = str_replace("'", '', $value);
            // Convert boolean values to real boolean.
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            // Append or override the default config.
            $config[trim($configParts[0])] = $value;
          }
        }
      }
    }
    // Return modified config.
    return $config;
  }

}
