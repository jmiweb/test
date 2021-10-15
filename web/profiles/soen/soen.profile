<?php


/**
 * Implements hook_theme_suggestions_alter().
 */
function soen_theme_suggestions_form_alter(array &$suggestions, array $variables) {
  $suggestions[] = "{$variables['theme_hook_original']}__{$variables['element']['#form_id']}";
}