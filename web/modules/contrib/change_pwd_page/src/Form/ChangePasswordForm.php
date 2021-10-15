<?php

namespace Drupal\change_pwd_page\Form;

use Drupal\user\Entity\User;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Url;
use Drupal\Core\Password\PasswordInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a user password reset form.
 */
class ChangePasswordForm extends FormBase {

  /**
   * The Password Hasher.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected $passwordHasher;

  /**
   * The user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $userProfile;

  /**
   * Constructs a UserPasswordForm object.
   *
   * @param \Drupal\Core\Password\PasswordInterface $password_hasher
   *   The password service.
   */
  public function __construct(PasswordInterface $password_hasher) {
    $this->passwordHasher = $password_hasher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('password')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'change_pwd_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\user\UserInterface $user
   *   The user object.
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL) {
    /** @var \Drupal\user\UserInterface $account */
    $this->userProfile = $account = $user;
    $user = $this->currentUser();
    $config = \Drupal::config('user.settings');
    $form['#cache']['tags'] = $config->getCacheTags();
    $register = $account->isAnonymous();

    // Account information.
    $form['account'] = [
      '#type'   => 'container',
      '#weight' => -10,
    ];

    // Display password field only for existing users or when user is allowed to
    // assign a password during registration.
    if (!$register) {
      $form['account']['pass'] = [
        '#type' => 'password_confirm',
        '#size' => 25,
        '#description' => $this->t('To change the current user password, enter the new password in both fields.'),
        '#required' => TRUE,
      ];

      // To skip the current password field, the user must have logged in via a
      // one-time link and have the token in the URL. Store this in $form_state
      // so it persists even on subsequent Ajax requests.
      if (!$form_state->get('user_pass_reset') && ($token = $this->getRequest()->get('pass-reset-token'))) {
        $session_key = 'pass_reset_' . $account->id();
        $user_pass_reset = isset($_SESSION[$session_key]) && hash_equals($_SESSION[$session_key], $token);
        $form_state->set('user_pass_reset', $user_pass_reset);
      }

      // The user must enter their current password to change to a new one.
      if ($user->id() == $account->id()) {
        $form['account']['current_pass'] = [
          '#type' => 'password',
          '#title' => $this->t('Current password'),
          '#size' => 25,
          '#access' => !$form_state->get('user_pass_reset'),
          '#weight' => -5,
          // Do not let web browsers remember this password, since we are
          // trying to confirm that the person submitting the form actually
          // knows the current one.
          '#attributes' => ['autocomplete' => 'off'],
          '#required' => TRUE,
        ];
        $form_state->set('user', $account);

        // The user may only change their own password without their current
        // password if they logged in via a one-time login link.
        if (!$form_state->get('user_pass_reset')) {
          $form['account']['current_pass']['#description'] = $this->t('Required if you want to change the %pass below. <a href=":request_new_url" title="Send password reset instructions via email.">Reset your password</a>.', [
            '%pass' => $this->t('Password'),
            ':request_new_url' => Url::fromRoute('user.pass')->toString(),
          ]);
        }
      }

      // This should never show. The data is needed by other modules.
      $roles = array_map(['\Drupal\Component\Utility\Html', 'escape'], user_role_names(TRUE));
      $form['account']['roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Roles'),
        '#default_value' => (!$register ? $account->getRoles() : []),
        '#options' => $roles,
        '#access' => FALSE,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Submit')];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $current_pass_input = trim($form_state->getValue('current_pass'));
    if ($current_pass_input) {
      $user = User::load(\Drupal::currentUser()->id());
      if (!$this->passwordHasher->check($current_pass_input, $user->getPassword())) {
        $form_state->setErrorByName('current_pass', $this->t('The current password you provided is incorrect.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = User::load($this->userProfile->id());
    $user->setPassword($form_state->getValue('pass'));
    $user->save();
    $this->messenger()->addStatus($this->t('Your password has been changed.'));
  }

  /**
   * Returns the user.
   *
   * @return \Drupal\user\UserInterface
   *   The User profile for the current user.
   */
  public function getEntity() {
    return $this->userProfile;
  }

}
