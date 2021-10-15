<?php

namespace Drupal\ckeditor_config\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class CkeditorConfigController.
 *
 * @package Drupal\ckeditor_config\Controller
 */
class CkeditorConfigForm extends ConfigFormBase {

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['ckeditor_config.config_form'];
  }

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'ckeditor_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ckeditor_config.config_form');

    $form['config'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CKEditor Custom Configuration'),
      '#description' => $this->t("Each row is configuration set in format key=value. Don't use quotes for string values."),
      '#default_value' => $config->get('config'),
    ];
    $manualText = "<code>
        format_tags=p;h2;h4<br>
        language = de<br>
        forcePasteAsPlainText = true<br>
        uiColor = #AADC6E</code>";
    $form['manual'] = [
      '#prefix' => '<h3>' . $this->t('Example') . '</h3>',
      '#markup' => '<p>' . $manualText . '</p>',
    ];
    $reference_url = 'https://docs.ckeditor.com/ckeditor4/docs/#!/api/CKEDITOR.config';
    $form['reference'] = [
      '#prefix' => '<h3>CKEditor reference</h3>',
      '#markup' => '<p>' . $this->t('For more information, visit CKEditor config reference <a href=":reference">@reference</a>',
          [':reference' => $reference_url, '@reference' => $reference_url]) . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('ckeditor_config.config_form')
      ->set('config', $form_state->getValue('config'))
      ->save();
  }

}
