<?php

namespace Drupal\soen_global\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Link;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

/**
 * Controller routines for L1 Page routes.
 */
class L1PagesController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  protected function getModuleName() {
    return 'soen_global';
  }

  private function processDefaultCategory($vid, $category) {
    $properties = [
      'vid' => $vid, 
    ];
    
    // Process default state. Just return FALSE
    if ($category == 'default') {
      return FALSE;
    }

    // Allow for TID to be passed as an arg. Otherwise look up name of category
    if (is_numeric($category)) {
      $properties['tid'] = $category;
    } else {
      $sanitized_name = str_replace('-', ' ', $category);
      $properties['name'] = $sanitized_name;
    }

    $terms = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);
  
    // If no term is found, return access denied
    if (empty($term)) {
      throw new AccessDeniedHttpException();
    }

    return $term;
  }

  private function setPageTitle($title, $site_setting_variant) {
    $site_settings = \Drupal::service('site_settings.loader');
    $setting = $site_settings->loadByFieldset('level_one_page_content');
    if (isset($setting[$site_setting_variant]) && !empty($setting[$site_setting_variant]) ) {
      $title = $setting[$site_setting_variant]['field_l1_page_title'];
    }
    return $title;
  }

  private function buildPage($default_term, $config_elements) {
    $layout_config = $config_elements['field_l1_page_layout'];
    $layout_counts = explode('x', $layout_config);

    $default_poster_content = [];
    if ($default_term === FALSE) {
      // Use default settings.
      $default_term_id = 0;
      // Get default splash image uploaded and help text
      $media_query_result = Media::load($config_elements['field_default_sample_fit_image']);
      $renderable_media = \Drupal::entityTypeManager()->getViewBuilder('media')->view($media_query_result, 'full');
      $default_poster_content['splash_image'] = $renderable_media;
      $default_poster_content['text'] = $config_elements['field_l1_default_option_help'];
    } else {
      $default_term_id = $default_term->id();
    }

    $parent_term_values = [];
    $main_content_section = [];
    foreach ($config_elements['field_l1_category_content'] as $index => $paragraph) {
      $instance_ids = [
        'id'=> $paragraph['target_id'],
        'revision_id' => $paragraph['target_revision_id']
      ];
      $query_result = \Drupal::entityTypeManager()->getStorage('paragraph')->loadByProperties($instance_ids);
      if (!empty($query_result)) {
        $paragraph_instance = reset($query_result);
        // Get splash image and term name
        $parent_term = $paragraph_instance->get('field_parent_term')->referencedEntities();
        $raw_media = $parent_term[0]->get('field_listing_splash_image')->referencedEntities();
        $renderable_media = \Drupal::entityTypeManager()->getViewBuilder('media')->view($raw_media[0], 'full');
        $parent_term_values[$index]['splash_image'] = $renderable_media;
        $parent_term_values[$index]['name'] = $parent_term[0]->getName();
        $parent_term_values[$index]['id'] = $parent_term[0]->id();

        // Build contents for main section
        $main_content_section[$index]['splash_image'] = $renderable_media;
        $main_content_section[$index]['poster_name'] = $parent_term[0]->getName();
        $main_content_section[$index]['poster_id'] = $parent_term[0]->id();
        $child_links = [];
        // Get L2 page references if any
        $l2_pages = $paragraph_instance->get('field_l2_page_reference')->referencedEntities();
        if (!empty($l2_pages)) {
          foreach ($l2_pages as $l2_page_node) {
            $options = ['absolute' => TRUE];
            $view_link = Link::fromTextAndUrl($l2_page_node->getTitle(), $l2_page_node->toUrl('canonical', $options));
            $child_links[] = $view_link;
          }
        }
        // Get Product references if any
        $product_pages = $paragraph_instance->get('field_product_reference')->referencedEntities();
        if (!empty($product_pages)) {
          foreach ($product_pages as $commerce_product) {
            $options = ['absolute' => TRUE];
            $view_link = Link::fromTextAndUrl($commerce_product->getTitle(), $commerce_product->toUrl('canonical', $options));
            $child_links[] = $view_link;            
          }
        }

        $main_content_section[$index]['child_links'] = $child_links;
      }
      
    }

    return $payload = [
      'parentTermGroup' => $layout_counts[0],
      'parentTermGroup2' => $layout_counts[1],      
      'defaultTerm' => $default_term_id,
      'defaultPoster' => $default_poster_content,      
      'parentTerms' => $parent_term_values,
      'mainContent' => $main_content_section,
    ];    

  }

  /**
   * Page Title Callbacks
   * 
   */
  public function get_ubf_title($default_category) {
    $title = 'Underwear By Fit'; // Default value in case this isnt set in the site settings
    $site_setting_variant = 'l1_page_content_underwear_by_fit';
    return $this->setPageTitle($title, $site_setting_variant);
  }

  public function get_ubc_title($default_category) {
    $title = 'Underwear By Collection'; // Default value in case this isnt set in the site settings
    $site_setting_variant = 'l1_page_content_underwear_by_col';
    return $this->setPageTitle($title, $site_setting_variant);
  }

  public function get_bbf_title($default_category) {
    $title = 'Bra By Fit'; // Default value in case this isnt set in the site settings
    $site_setting_variant = 'l1_page_content_bra_by_fit';
    return $this->setPageTitle($title, $site_setting_variant);
  }

  public function get_bbc_title($default_category) {
    $title = 'Bra By Collection'; // Default value in case this isnt set in the site settings
    $site_setting_variant = 'l1_page_content_bra_by_col';
    return $this->setPageTitle($title, $site_setting_variant);
  }

  /**
   *
   * This callback is mapped to the path
   * '/underwear-by-fit/{default_category}'.
   *
   * @param string $default_category
   *   Sets this category as the active path/trail/block.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameter is invalid.
   */
  public function underwear_by_fit($default_category) {
    $vocab_machine_name = 'underwear_style';
    $site_setting_variant = 'l1_page_content_underwear_by_fit';
    $default_term = $this->processDefaultCategory($vocab_machine_name, $default_category);

    $site_settings = \Drupal::service('site_settings.loader');
    $setting = $site_settings->loadByFieldset('level_one_page_content');
    if (isset($setting[$site_setting_variant]) && !empty($setting[$site_setting_variant]) ) {
      $config_elements = $setting[$site_setting_variant];
    } else {
      drupal_set_message('Contact Site Admin: Site Setting for this page needs to be created.', 'error');
      throw new AccessDeniedHttpException();
    }
    
    // Process paragraph contents
    $payload = $this->buildPage($default_term, $config_elements);

    $render_array = [
      '#theme' => 'l1_page_markup',
      '#attached' => [
        'library' => [
          'soen_global/l1-theming',
        ],
      ],
      '#headline' => $config_elements['field_l1_headline'],
      '#subheadline' => $config_elements['field_l1_sub_heading'],
      '#parentTermGroup' => $payload['parentTermGroup'],
      '#parentTermGroup2' => $payload['parentTermGroup2'],
      '#defaultTerm' => $payload['defaultTerm'],
      '#defaultPoster' => $payload['defaultPoster'],
      '#parentTerms' => $payload['parentTerms'],
      '#mainContent' => $payload['mainContent'],
    ];
    return $render_array;
  
  }

  /**
   *
   * This callback is mapped to the path
   * '/underwear-by-collection/{default_category}'.
   *
   * @param string $default_category
   *   Sets this category as the active path/trail/block.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameter is invalid.
   */
  public function underwear_by_collection($default_category) {
    $vocab_machine_name = 'underwear_collection';
    $site_setting_variant = 'l1_page_content_underwear_by_col';
    $default_term = $this->processDefaultCategory($vocab_machine_name, $default_category);

    $site_settings = \Drupal::service('site_settings.loader');
    $setting = $site_settings->loadByFieldset('level_one_page_content');
    if (isset($setting[$site_setting_variant]) && !empty($setting[$site_setting_variant]) ) {
      $config_elements = $setting[$site_setting_variant];
    } else {
      drupal_set_message('Contact Site Admin: Site Setting for this page needs to be created.', 'error');
      throw new AccessDeniedHttpException();
    }
    
    // Process paragraph contents
    $payload = $this->buildPage($default_term, $config_elements);

    $render_array = [
      '#theme' => 'l1_page_markup',
      '#attached' => [
        'library' => [
          'soen_global/l1-theming',
        ],
      ],
      '#headline' => $config_elements['field_l1_headline'],
      '#subheadline' => $config_elements['field_l1_sub_heading'],
      '#parentTermGroup' => $payload['parentTermGroup'],
      '#parentTermGroup2' => $payload['parentTermGroup2'],
      '#defaultTerm' => $payload['defaultTerm'],
      '#defaultPoster' => $payload['defaultPoster'],
      '#parentTerms' => $payload['parentTerms'],
      '#mainContent' => $payload['mainContent'],
    ];
    return $render_array;
    
  }  

  /**
   *
   * This callback is mapped to the path
   * '/bra-by-fit/{default_category}'.
   *
   * @param string $default_category
   *   Sets this category as the active path/trail/block.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameter is invalid.
   */
  public function bra_by_fit($default_category) {
    $vocab_machine_name = 'bra_style';
    $site_setting_variant = 'l1_page_content_bra_by_fit';
    $default_term = $this->processDefaultCategory($vocab_machine_name, $default_category);

    $site_settings = \Drupal::service('site_settings.loader');
    $setting = $site_settings->loadByFieldset('level_one_page_content');
    if (isset($setting[$site_setting_variant]) && !empty($setting[$site_setting_variant]) ) {
      $config_elements = $setting[$site_setting_variant];
    } else {
      drupal_set_message('Contact Site Admin: Site Setting for this page needs to be created.', 'error');
      throw new AccessDeniedHttpException();
    }
    
    // Process paragraph contents
    $payload = $this->buildPage($default_term, $config_elements);

    $render_array = [
      '#theme' => 'l1_page_markup',
      '#attached' => [
        'library' => [
          'soen_global/l1-theming',
        ],
      ],
      '#headline' => $config_elements['field_l1_headline'],
      '#subheadline' => $config_elements['field_l1_sub_heading'],
      '#parentTermGroup' => $payload['parentTermGroup'],
      '#parentTermGroup2' => $payload['parentTermGroup2'],
      '#defaultTerm' => $payload['defaultTerm'],
      '#defaultPoster' => $payload['defaultPoster'],
      '#parentTerms' => $payload['parentTerms'],
      '#mainContent' => $payload['mainContent'],
    ];
    return $render_array;
     
  }  

  /**
   *
   * This callback is mapped to the path
   * '/bra-by-collection/{default_category}'.
   *
   * @param string $default_category
   *   Sets this category as the active path/trail/block.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameter is invalid.
   */
  public function bra_by_collection($default_category) {
    $vocab_machine_name = 'bra_collection';
    $site_setting_variant = 'l1_page_content_bra_by_col';
    $default_term = $this->processDefaultCategory($vocab_machine_name, $default_category);

    $site_settings = \Drupal::service('site_settings.loader');
    $setting = $site_settings->loadByFieldset('level_one_page_content');
    if (isset($setting[$site_setting_variant]) && !empty($setting[$site_setting_variant]) ) {
      $config_elements = $setting[$site_setting_variant];
    } else {
      drupal_set_message('Contact Site Admin: Site Setting for this page needs to be created.', 'error');
      throw new AccessDeniedHttpException();
    }
    
    // Process paragraph contents
    $payload = $this->buildPage($default_term, $config_elements);

    $render_array = [
      '#theme' => 'l1_page_markup',
      '#attached' => [
        'library' => [
          'soen_global/l1-theming',
        ],
      ],
      '#headline' => $config_elements['field_l1_headline'],
      '#subheadline' => $config_elements['field_l1_sub_heading'],
      '#parentTermGroup' => $payload['parentTermGroup'],
      '#parentTermGroup2' => $payload['parentTermGroup2'],
      '#defaultTerm' => $payload['defaultTerm'],
      '#defaultPoster' => $payload['defaultPoster'],
      '#parentTerms' => $payload['parentTerms'],
      '#mainContent' => $payload['mainContent'],
    ];
    return $render_array;
   
  }    
}
