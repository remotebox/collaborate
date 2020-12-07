<?php

namespace Drupal\collaborate_integration\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Collaborate integration authentication.
 */
class CollaborateAuthForm extends ConfigFormBase {

  /**
   * Constructs a new CollaborateAuthForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'collaborate_integration_collaborate_auth';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Url'),
      '#description' => $this->t('Enter the URL to the Collaborate API.'),
      '#default_value' => $this->config('collaborate_integration.auth_settings')
        ->get('url'),
    ];

    $form['key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key'),
      '#description' => $this->t('Enter your key for the Collaborate API.<br><b>It is strongly recommended that you enter false info here and overwrite in settings.php</b>'),
      '#default_value' => $this->config('collaborate_integration.auth_settings')
        ->get('key'),
    ];

    $form['secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret'),
      '#description' => $this->t('Enter your secret for the Collaborate API.<br><b>It is strongly recommended that you enter false info here and overwrite in settings.php</b>'),
      '#default_value' => $this->config('collaborate_integration.auth_settings')
        ->get('secret'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('collaborate_integration.auth_settings')
      ->set('url', $form_state->getValue('url'))
      ->set('key', $form_state->getValue('key'))
      ->set('secret', $form_state->getValue('secret'))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['collaborate_integration.auth_settings'];
  }
}
