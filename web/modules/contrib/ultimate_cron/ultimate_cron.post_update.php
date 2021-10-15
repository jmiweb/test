<?php

/**
 * @file
 * Contains post update functionality of module.
 */

/**
 * Implements hook_post_update_NAME().
 */
function ultimate_cron_post_update_discover_cron_jobs(&$sandbox) {
  if (\Drupal::state()->get('ultimate_cron.discover_cron_jobs', FALSE)) {
    \Drupal::service('ultimate_cron.discovery')->discoverCronJobs();
    \Drupal::state()->delete('ultimate_cron.discover_cron_jobs');
  }
}
