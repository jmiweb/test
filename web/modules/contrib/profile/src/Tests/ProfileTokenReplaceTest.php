<?php

/**
 * @file
 * Contains \Drupal\profile\Tests\ProfileTokenReplaceTest.
 */

namespace Drupal\profile\Tests;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Generates text using placeholders for dummy content to check profile token
 * replacement.
 *
 * @group profile
 */
class ProfileTokenReplaceTest extends ProfileTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('profile', 'filter');

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
  }

  public function testProfileTokenReplacement() {
    $current_language = \Drupal::languageManager()->getCurrentLanguage();
    $url_options = array(
      'absolute' => TRUE,
      'language' => $current_language,
    );

    // Create a user and a profile.
    $account = $this->createUser();
    /** @var ProfileInterface $profile */
    $profile = $this->createProfile($this->type, $account);

    // Generate and test tokens.
    $tests = array();
    $tests['[profile:id]'] = $profile->id();
    $tests['[profile:vid]'] = $profile->getRevisionId();
    $tests['[profile:type]'] = $profile->bundle();
    $tests['[profile:langcode]'] = $profile->language()->getId();
    $tests['[profile:url]'] = $profile->url('canonical', $url_options);
    $tests['[profile:edit-url]'] = $profile->url('edit-form', $url_options);
    $tests['[profile:author]'] = $account->getUsername();
    $tests['[profile:author:uid]'] = $profile->getOwnerId();
    $tests['[profile:author:name]'] = $account->getUsername();
    $tests['[profile:created:since]'] = \Drupal::service('date.formatter')->formatTimeDiffSince($profile->getCreatedTime(), array('langcode' => $current_language->getId()));
    $tests['[profile:changed:since]'] = \Drupal::service('date.formatter')->formatTimeDiffSince($profile->getChangedTime(), array('langcode' => $current_language->getId()));

    $base_bubbleable_metadata = BubbleableMetadata::createFromObject($profile);

    $metadata_tests = [];
    $metadata_tests['[profile:id]'] = $base_bubbleable_metadata;
    $metadata_tests['[profile:vid]'] = $base_bubbleable_metadata;
    $metadata_tests['[profile:type]'] = $base_bubbleable_metadata;
    $metadata_tests['[profile:langcode]'] = $base_bubbleable_metadata;
    $metadata_tests['[profile:url]'] = $base_bubbleable_metadata;
    $metadata_tests['[profile:edit-url]'] = $base_bubbleable_metadata;
    $bubbleable_metadata = clone $base_bubbleable_metadata;
    $metadata_tests['[profile:author]'] = $bubbleable_metadata->addCacheTags(['user:' . $account->id()]);
    $metadata_tests['[profile:author:uid]'] = $bubbleable_metadata;
    $metadata_tests['[profile:author:name]'] = $bubbleable_metadata;
    $bubbleable_metadata = clone $base_bubbleable_metadata;
    $metadata_tests['[profile:created:since]'] = $bubbleable_metadata->setCacheMaxAge(0);
    $metadata_tests['[profile:changed:since]'] = $bubbleable_metadata;

    // Test to make sure that we generated something for each token.
    $this->assertFalse(in_array(0, array_map('strlen', $tests)), 'No empty tokens generated.');

    foreach ($tests as $input => $expected) {
      $bubbleable_metadata = new BubbleableMetadata();
      $output = \Drupal::token()->replace($input, array('profile' => $profile), array('langcode' => $current_language->getId()), $bubbleable_metadata);
      $this->assertEqual($output, $expected, new FormattableMarkup('Profile token %token replaced.', ['%token' => $input]));
      $this->assertEqual($bubbleable_metadata, $metadata_tests[$input]);
    }
  }

}
