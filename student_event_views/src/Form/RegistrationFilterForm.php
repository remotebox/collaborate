<?php

namespace Drupal\student_event_views\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class RegistrationFilterForm.
 */
class RegistrationFilterForm extends FormBase {

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs the RegistrationFilterForm object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    RequestStack $requestStack
  ) {
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'registration_filters_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $params = [];
    parse_str(
      html_entity_decode(
        Xss::filter(
          $this->requestStack->getCurrentRequest()->getQueryString()
        )
      ),
      $params[]
    );
    $params = reset($params);

    $form['filter'] = [
      '#type' => 'container',
      '#title' => t('filter'),
      '#weight' => 0,
      '#attributes' => [
        'class' => [''],
      ]
    ];

    $form['filter']['filter_container']['#tree'] = TRUE;

    $form['filter']['filter_container']['q']['row0'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mb-4', 'd-flex', 'justify-content-center']
      ]
    ];

    $form['filter']['filter_container']['q']['row0']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('<b>Teaching format</b>'),
      '#description' => $this->t('Filter registrations by teaching format.'),
      '#options' => [
        'all' => 'All',
        'workshop' => t('Workshops'),
        'tutorial' => t('Tutorials'),
      ],
      '#default_value' => isset($params['type']) ? $params['type'] : 'all',
      '#after_build' => ['events_register_inline_radios'],
      '#attributes' => [
        'class' => ['text-center', 'no-card', 'col-auto'],
        'onChange' => 'this.form.submit();',
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => [
        'class' => ['d-none']
      ]
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValues() as $key => $value) {
      // @TODO: Validate fields.
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = NestedArray::getValue(
      $form_state->getValues(),
      ['filter_container', 'q']
    );
    unset($values['actions']);
    $params = [];
    foreach ($values as $key => $value) {
      foreach ($value as $subkey => $subvalue) {
        if (is_array($subvalue)) {
          $params[$subkey] = implode('/', array_filter($subvalue));
        }
        else {
          $params[$subkey] = $subvalue;
        }
      }
    }
    $params = array_filter($params, 'strlen');
    $form_state->setRedirect(
      $this->getRouteMatch()->getRouteName(),
      $this->getRouteMatch()->getRawParameters()->all(),
      ['query' => $params]
    );
  }
}
