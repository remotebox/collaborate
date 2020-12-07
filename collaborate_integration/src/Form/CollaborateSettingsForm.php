<?php

namespace Drupal\collaborate_integration\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class CollaborateSettingsForm.
 */
class CollaborateSettingsForm extends ConfigFormBase {

  /**
   * Constructs a new CollaborateSettingsForm object.
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
    return 'collaborate_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $types = node_type_get_names();

    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select bundles.'),
      '#description' => $this->t('Select which bundles should trigger Collaborate sessions.'),
      '#options' => $types,
      '#default_value' => !empty(
      $this->config('collaborate_integration.site_settings')
        ->get('bundles')) ? $this->config('collaborate_integration.site_settings')
        ->get('bundles') : [],
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('collaborate_integration.site_settings')
      ->set('bundles', $form_state->getValue('bundles'))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'collaborate_integration.site_settings',
    ];
  }
}
