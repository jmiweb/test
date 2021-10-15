<?php

/**
 * @file
 * Builds placeholder replacement tokens for profile-related data.
 */

use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user\Entity\User;

/**
 * Implements hook_token_info().
 */
function profile_token_info() {
  $type = [
    'name' => t('Profiles'),
    'description' => t('Tokens related to profiles.'),
    'needs-data' => 'profile',
  ];

  // Core tokens for profiles.
  $profile['id'] = [
    'name' => t("Profile ID"),
    'description' => t('The unique ID of the profile.'),
  ];
  $profile['vid'] = [
    'name' => t("Revision ID"),
    'description' => t("The unique ID of the profile's latest revision."),
  ];
  $profile['type'] = [
    'name' => t("Profile type"),
  ];
  $profile['langcode'] = [
    'name' => t('Language code'),
    'description' => t('The language code of the language the profile is written in.'),
  ];
  $profile['url'] = [
    'name' => t("URL"),
    'description' => t("The URL of the profile."),
  ];
  $profile['edit-url'] = [
    'name' => t("Edit URL"),
    'description' => t("The URL of the profile's edit page."),
  ];

  // Chained tokens for profiles.
  $profile['created'] = [
    'name' => t("Date created"),
    'type' => 'date',
  ];
  $profile['changed'] = [
    'name' => t("Date changed"),
    'description' => t("The date the profile was most recently updated."),
    'type' => 'date',
  ];
  $profile['author'] = [
    'name' => t("Author"),
    'type' => 'user',
  ];

  return [
    'types' => ['profile' => $type],
    'tokens' => ['profile' => $profile],
  ];
}

/**
 * Implements hook_tokens().
 */
function profile_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $token_service = \Drupal::token();

  $url_options = ['absolute' => TRUE];
  if (isset($options['langcode'])) {
    $url_options['language'] = \Drupal::languageManager()->getLanguage($options['langcode']);
    $langcode = $options['langcode'];
  }
  else {
    $langcode = LanguageInterface::LANGCODE_DEFAULT;
  }
  $replacements = [];

  if ($type == 'profile' && !empty($data['profile'])) {
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = $data['profile'];

    foreach ($tokens as $name => $original) {
      switch ($name) {
        // Simple key values on the profile.
        case 'id':
          $replacements[$original] = $profile->id();
          break;

        case 'vid':
          $replacements[$original] = $profile->getRevisionId();
          break;

        case 'type':
          $replacements[$original] = $profile->getType();
          break;

        case 'langcode':
          $replacements[$original] = $profile->language()->getId();
          break;

        case 'url':
          $uri = $profile->toUrl('canonical');
          $uri->setOptions($url_options);
          $replacements[$original] = $uri->toString();
          break;

        case 'edit-url':
          $uri = $profile->toUrl('edit-form');
          $uri->setOptions($url_options);
          $replacements[$original] = $uri->toString();
          break;

        // Default values for the chained tokens handled below.
        case 'author':
          $account = $profile->getOwner() ? $profile->getOwner() : User::load(0);
          $bubbleable_metadata->addCacheableDependency($account);
          $replacements[$original] = $account->label();
          break;

        case 'created':
          $date_format = DateFormat::load('medium');
          $bubbleable_metadata->addCacheableDependency($date_format);
          $replacements[$original] = \Drupal::service('date.formatter')->format($profile->getCreatedTime(), 'medium', '', NULL, $langcode);
          break;

        case 'changed':
          $date_format = DateFormat::load('medium');
          $bubbleable_metadata->addCacheableDependency($date_format);
          $replacements[$original] = \Drupal::service('date.formatter')->format($profile->getChangedTime(), 'medium', '', NULL, $langcode);
          break;
      }
    }

    if ($owner_tokens = $token_service->findWithPrefix($tokens, 'author')) {
      $replacements += $token_service->generate('user', $owner_tokens, ['user' => $profile->getOwner()], $options, $bubbleable_metadata);
    }

    if ($created_tokens = $token_service->findWithPrefix($tokens, 'created')) {
      $replacements += $token_service->generate('date', $created_tokens, ['date' => $profile->getCreatedTime()], $options, $bubbleable_metadata);
    }

    if ($changed_tokens = $token_service->findWithPrefix($tokens, 'changed')) {
      $replacements += $token_service->generate('date', $changed_tokens, ['date' => $profile->getChangedTime()], $options, $bubbleable_metadata);
    }
  }

  return $replacements;
}
