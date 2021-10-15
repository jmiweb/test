<?php

namespace Drupal\redirect\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\redirect\RedirectRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Plugin implementation of the 'link' widget for the redirect module.
 *
 * Note that this field is meant only for the source field of the redirect
 * entity as it drops validation for non existing paths.
 *
 * @FieldWidget(
 *   id = "redirect_source",
 *   label = @Translation("Redirect source"),
 *   field_types = {
 *     "link"
 *   },
 *   settings = {
 *     "placeholder_url" = "",
 *     "placeholder_title" = ""
 *   }
 * )
 */
class RedirectSourceWidget extends WidgetBase implements ContainerFactoryPluginInterface {
  
  /**
   * The path alias manager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The redirect repository service.
   *
   * @var \Drupal\redirect\RedirectRepository
   */
  protected $redirectRepository;

  /**
   * The dynamic router service.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, AliasManagerInterface $alias_manager, RedirectRepository $redirect_repository, RequestMatcherInterface $router) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->aliasManager = $alias_manager;
    $this->redirectRepository = $redirect_repository;
    $this->router = $router;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('path.alias_manager'),
      $container->get('redirect.repository'),
      $container->get('router')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $default_url_value = $items[$delta]->path;
    if ($items[$delta]->query) {
      $default_url_value .= '?' . http_build_query($items[$delta]->query);
    }

    $element['#prefix'] = '<div id="redirect-path-wrapper">';
    $element['#suffix'] = '</div>';    
    $element['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#placeholder' => $this->getSetting('placeholder_url'),
      '#default_value' => $default_url_value,
      '#maxlength' => 2048,
      '#required' => $element['#required'],
      '#field_prefix' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
      '#attributes' => ['data-disable-refocus' => 'true'],
    ];

    // If creating new URL add checks.
    if ($items->getEntity()->isNew()) {
      $element['status_box'] = [
        '#prefix' => '<div id="redirect-link-status">',
        '#suffix' => '</div>',
      ];

      $source_path = $form_state->getValue(['redirect_source', 0, 'path']);
      if ($source_path) {
        $source_path = trim($source_path);

        // Convert alias to system path if it is found.
        $source_path_trimmed = ltrim($source_path, '/');
        $system_path = ltrim($this->aliasManager->getPathByAlias('/' . $source_path_trimmed), '/');
        if ($source_path_trimmed != $system_path) {
          $message = $this->t('The path %path is an alias and has been converted to its source path %system_path.', ['%path' => $source_path_trimmed, '%system_path' => $system_path]);
          $element['status_box'][]['#markup'] = '<div class="messages messages--warning">' . $message . '</div>';

          $source_path = $element['path']['#value'] = $system_path;
        }
        
        // Warning about creating a redirect from a valid path.
        // @todo - Hmm... exception driven logic. Find a better way how to
        //   determine if we have a valid path.
        try {
          $this->router->match('/' . $source_path);
          $element['status_box'][]['#markup'] = '<div class="messages messages--warning">' . $this->t('The source path %path is likely a valid path. It is preferred to <a href="@url-alias">create URL aliases</a> for existing paths rather than redirects.',
              ['%path' => $source_path, '@url-alias' => Url::fromRoute('entity.path_alias.add_form')->toString()]) . '</div>';
        }
        catch (ResourceNotFoundException $e) {
          // Do nothing, expected behaviour.
        }
        catch (AccessDeniedHttpException $e) {
          // @todo Source lookup results in an access denied, deny access?
        }

        // Warning about the path being already redirected.
        $parsed_url = UrlHelper::parse($source_path);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : NULL;
        if (!empty($path)) {
          $redirects = $this->redirectRepository->findBySourcePath($path);
          if (!empty($redirects)) {
            $redirect = array_shift($redirects);
            $element['status_box'][]['#markup'] = '<div class="messages messages--warning">' . $this->t('The base source path %source is already being redirected. Do you want to <a href="@edit-page">edit the existing redirect</a>?', ['%source' => $source_path, '@edit-page' => $redirect->toUrl('edit-form')->toString()]) . '</div>';
          }
        }
      }

      $element['path']['#ajax'] = [
        'callback' => 'redirect_source_link_get_status_messages',
        'wrapper' => 'redirect-path-wrapper',
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    // It is likely that the url provided for this field is not existing and
    // so the logic in the parent method did not set any defaults. Just run
    // through all url values and add defaults.
    foreach ($values as &$value) {
      if (!empty($value['path'])) {
        // In case we have query process the url.
        if (strpos($value['path'], '?') !== FALSE) {
          $url = UrlHelper::parse($value['path']);
          $value['path'] = $url['path'];
          $value['query'] = $url['query'];
        }
      }
    }
    return $values;
  }
}
