<?php

namespace Drupal\student_event_views\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class MyRegistrationsForm.
 */
class MyRegistrationsForm extends FormBase {

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Drupal account to use for checking for access to advanced search.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_type_manager;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new MyRegistrationsForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Session\AccountInterface $account
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function __construct(
    MessengerInterface $messenger,
    AccountInterface $account,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $requestStack,
    LoggerInterface $logger
  ) {
    $this->messenger = $messenger;
    $this->account = $account;
    $this->database = $database;
    $this->entity_type_manager = $entity_type_manager;
    $this->requestStack = $requestStack;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('student_event_views')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'my_registrations_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $block = FALSE) {
    $params = [];
    if ($block == FALSE) {
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
    }


    $header = [
      ['data' => t('Teaching format'), 'class' => ['align-middle']],
      ['data' => t('Event'), 'class' => ['align-middle']],
      ['data' => t('Tutor'), 'class' => ['align-middle']],
      ['data' => t('Location'), 'class' => ['align-middle']],
      ['data' => t('Room'), 'class' => ['align-middle']],
      ['data' => t('Date/time'), 'field' => 'time.field_event_time_value', 'sort' => 'desc', 'class'=> ['align-middle']],
      ['data' => t('Registration status'), 'class' => ['align-middle']],
      ['data' => t('Cancel?'), 'class' => ['align-middle']],
//      ['data' => t('Tutor feedback'), 'class' => ['align-middle']],
    ];

    if ($block == TRUE) {
      unset($header[2]);
      unset($header[5]);
      unset($header[6]);
//      unset($header[8]);
      array_splice( $header, 1, 0, [['data' => t('Date/time'), 'class' => ['align-middle']]] );
    }

    $teaching_format = 'tutorials or workshops';
    if (isset($params['type'])) {
      switch ($params['type']) {
        case 'tutorial':
          $teaching_format = 'tutorials';
          break;
        case 'workshop':
          $teaching_format = 'workshops';
          break;
      }
    }

    $form['registrations'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t("<p class='text-align-center'>You currently have no upcoming $teaching_format. Please check out our <a href='/face-to-face-support'>face to face support</a>.</p>"),
      '#prefix' => "<div class='table-responsive row-full px-5 py-3'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => [''],
      ]
    ];

    $results = $this->getMyRegistrations($params, $header, $block);
    if ($results) {
      try {
        // load nodes here for better performance.
        // to do group access checks use entity query instead
        $node_storage = $this->entity_type_manager->getStorage('node');
        $nodes = $node_storage->loadMultiple(array_column($results, 'nid'));
      } catch (\Exception $e) {
        $this->logger->error(
          '@e <br> <pre>%t</pre>',
          [
            '@e' => $e->getMessage(),
            '%t' => $e->getTraceAsString()
          ]
        );
        $nodes = FALSE;
      }
      if ($nodes) {
        foreach ($results as $registration) {
          try {
            $tids = [];
            $tids[] = $nodes[$registration->nid]->get('field_event_building')->target_id;
            $tids[] = $nodes[$registration->nid]->get('field_event_room')->target_id;
            $term_storage = $this->entity_type_manager->getStorage('taxonomy_term');
            $terms = $term_storage->loadMultiple(array_filter($tids));
            $taxonomy = [];
            foreach ($terms as $term) {
              $taxonomy[$term->bundle()] = $term->label();
            }
          } catch (\Exception $e) {
            $this->logger->error(
              '@e <br> <pre>%t</pre>',
              [
                '@e' => $e->getMessage(),
                '%t' => $e->getTraceAsString()
              ]
            );
          }

          if ($block == FALSE) {
            $tutor_id = $nodes[$registration->nid]->get('field_event_tutor')->target_id;
            if ($tutor_id) {
              try {
                $name = '';
                $dq = $this->database->select('profile', 'profile');
                $dq->addField('f_name', 'field_prof_first_name_value', 'fname');
                $dq->addField('l_name', 'field_prof_last_name_value', 'lname');
                $dq->join('profile__field_prof_first_name', 'f_name', 'profile.profile_id = f_name.entity_id');
                $dq->join('profile__field_prof_last_name', 'l_name', 'profile.profile_id = l_name.entity_id');
                $dq->condition('profile.uid', $tutor_id, '=');
                $dq->condition('profile.type', 'employee', '=');
                $tutor = $dq->execute()->fetchAssoc();
                if (!empty($tutor['fname']) && !empty($tutor['lname'])) {
                  $name = trim($tutor['fname']) . ' ' . trim($tutor['lname']);
                }
              } catch (DatabaseExceptionWrapper $e) {
                $this->logger->error(
                  '@e <br> <pre>%t</pre>',
                  [
                    '@e' => $e->getMessage(),
                    '%t' => $e->getTraceAsString()
                  ]
                );
              }
            }
          }
          $start = DrupalDateTime::createFromFormat(
            'Y-m-d\TH:i:s', $registration->field_event_time_value,
            DateTimeItemInterface::STORAGE_TIMEZONE
          )->setTimezone(new \DateTimeZone('Europe/London'));
          $end = DrupalDateTime::createFromFormat(
            'Y-m-d\TH:i:s', $registration->field_event_time_end_value,
            DateTimeItemInterface::STORAGE_TIMEZONE
          )->setTimezone(new \DateTimeZone('Europe/London'));

          $date = $start->format('d M Y');
          $time = $start->format('H:i') . ' - ' . $end->format('H:i');
          $date_markup = "<div class='mr-3 d-inline-block'>
                          <span class='far fa-clock' aria-hidden='true'></span> $time
                        </div>
                        <div class='mr-3 d-inline-block'>
                          <span class='far fa-calendar-alt' aria-hidden='true'></span> $date
                        </div>";
          if ($registration->type == 'workshop') {
            $hide_day = $nodes[$registration->nid]->hasField('field_hide_day') ? $nodes[$registration->nid]->get('field_hide_day')->value : 0;
            $hide_time = $nodes[$registration->nid]->hasField('field_hide_time') ? $nodes[$registration->nid]->get('field_hide_time')->value : 0;
            if ($hide_day == 1) {
              $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> ".$start->format('M Y')."</div>";
            }
            if ($hide_time == 1) {
              $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> ".$start->format('d M Y')."</div>";
            }
          }

          $form['registrations'][$registration->sid]['teaching_format'] = [
            '#type' => 'item',
            '#markup' => t('@node_type', ['@node_type' => ucfirst($registration->type)])
          ];

          if ($block == TRUE) {
            $form['registrations'][$registration->sid]['date_time'] = [
              '#type' => 'item',
              '#markup' => $date_markup
            ];
          }

          $event_link = Link::fromTextAndURL(
            $registration->title,
            Url::fromRoute('entity.node.canonical', ['node' => $registration->nid])
          )->toString();
          $form['registrations'][$registration->sid]['title'] = [
            '#type' => 'item',
            '#markup' => $event_link
          ];

          if ($block == FALSE) {
            $form['registrations'][$registration->sid]['tutor'] = [
              '#type' => 'item',
              '#markup' => t('@tutor', ['@tutor' => isset($name) ? $name : 'Not available'])
            ];
          }

          $form['registrations'][$registration->sid]['building_location'] = [
            '#type' => 'item',
            '#markup' => t('@building_location',
              [
                '@building_location' => isset($taxonomy['buildings']) ? $taxonomy['buildings'] : 'Not available'
              ]
            )
          ];

          $form['registrations'][$registration->sid]['room_location'] = [
            '#type' => 'item',
            '#markup' => t('@room_location',
              [
                '@room_location' => isset($taxonomy['rooms']) ? $taxonomy['rooms'] : 'Not available'
              ]
            )
          ];

          if ($block == FALSE) {
            $form['registrations'][$registration->sid]['date_time'] = [
              '#type' => 'item',
              '#markup' => $date_markup
            ];
          }

          switch ($registration->registration_status) {
            case 'attending':
              $registration_status = 'Attending';
              break;
            case 'attended':
              $registration_status = 'Attended';
              break;
            case 'cancelled':
              $registration_status = 'Cancelled';
              break;
            case 'system_cancellation':
              $registration_status = 'System cancellation';
              break;
            case 'timetabled_by_course_team':
              $registration_status = 'Timetabled by course team';
              break;
            case 'waitlist':
              $registration_status = 'Wait listed';
              break;
            default:
              $registration_status = 'Not available';
          }

          if ($block == FALSE) {
            $form['registrations'][$registration->sid]['status'] = [
              '#type' => 'item',
              '#markup' => t(
                '@status',
                ['@status' => isset($registration_status) ? $registration_status : 'Not available']
              )
            ];
          }

          if ($start > new DrupalDateTime('now') && $registration_status == 'Attending') {
            $form['registrations'][$registration->sid]['cancel'] = [
              '#type' => 'item',
              '#markup' => Link::fromTextAndURL(
                'Cancel',
                Url::fromUri('base:/event/cancel/' . $registration->sid)
              )->toString()
            ];
          }
          else {
            $form['registrations'][$registration->sid]['cancel'] = [
              '#type' => 'item',
              '#markup' => t('')
            ];
          }

          if ($block == FALSE && $registration->type == 'tutorial') {
            try {
              $feedback_query = $this->database->select('webform_submission_data', 'wsd');
              $feedback_query->addField('wsd', 'value');
              $feedback_query->condition('wsd.sid', $registration->sid, '=');
              $feedback_query->condition('wsd.name', 'student_feedback', '=');
              $feedback = $feedback_query->execute()->fetchField();
            }
            catch (DatabaseExceptionWrapper $e) {
              $this->logger->error(
                'Database error retrieving student feedback. <br> <pre>@e<br>%t</pre>',
                [
                  '@e' => $e->getMessage(),
                  '%t' => $e->getTraceAsString()
                ]
              );
              $feedback = '';
            }
            if (!empty($feedback)) {

              $pattern = '@(http(s)?://)?(([a-zA-Z])([-\w]+\.)+([^\s\.]+[^\s]*)+[^,.\s])@';
              $output = preg_replace($pattern, '<a href="http$2://$3" target=\"_blank\" rel=\"nofollow\">$0</a>', $feedback);

              $form['registrations'][$registration->sid.'-feedback']['feedback'] = [
                '#type' => 'item',
                '#markup' => $output,
                '#wrapper_attributes' =>
                  [
                    'colspan' => 8,
                    'class' => ['border-top-0']
                  ],
                '#prefix' => t('<b>Tutor feedback:</b>'),
              ];
            }
          }

        }
      }
    }

    if ($block == FALSE) {
      $form['pager'] = array(
        '#type' => 'pager',
      );
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getValue(['filter_container', 'q', 'row0', 'type'])) &&
      preg_match("/^[a-z]+$/", $form_state->getValue(['filter_container', 'q', 'row0', 'type'])) == 0) {
      $form_state->setErrorByName('filter_container][q][row1][type', $this->t('Please use the pre-defined options.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = NestedArray::getValue($form_state->getValues(), ['filter_container', 'q']);
    $params = [];
    foreach($values as $key => $value) {
      foreach(array_filter($value) as $subkey => $subvalue) {
        if (is_array($subvalue)) {
          $params[$subkey] = implode('/', array_filter($subvalue));
        } else {
          $params[$subkey] = $subvalue;
        }
      }
    }

    $form_state->setRedirect(
      $this->getRouteMatch()->getRouteName(),
      $this->getRouteMatch()->getRawParameters()->all(),
      ['query' => array_filter($params)]
    );
  }

  public function getMyRegistrations($params, $header, $block) {
    try {
      $query = $this->database->select('webform_submission', 'ws');
      $query->join('webform_submission_data', 'wsd', "ws.sid = wsd.sid AND wsd.name = 'student_id'");
      $query->join('webform_submission_data', 'wsd2', "ws.sid = wsd2.sid AND wsd2.name = 'registration_status'");
      $query->join('node_field_data', 'nfd', "nfd.nid = ws.entity_id AND ws.entity_type = 'node'");
      $query->join('node__field_event_time', 'time', 'nfd.nid = time.entity_id');
      $query->leftJoin('node__field_event_tutor', 'tutor', 'nfd.nid = tutor.entity_id');
      $query->fields('nfd', ['nid', 'title', 'uid', 'type']);
      $query->addField('ws', 'sid', 'sid');
      $query->fields('time', [
        'field_event_time_value',
        'field_event_time_end_value'
      ]);
      $query->addField('tutor', 'field_event_tutor_target_id', 'tutor');
      $query->addField('wsd2', 'value', 'registration_status');
      $query->condition('wsd.value', $this->account->id(), '=');
      if ($block == TRUE) {
        $query->condition('wsd2.value', ['timetabled_by_course_team', 'attending'], 'IN');
        $currentTime= new DrupalDateTime('now');
        $currentTime->setTimezone(new \DateTimeZone('UTC'));
        $query->condition('time.field_event_time_end_value', $currentTime->format('Y-m-d\TH:i:s'), '>=');
//        $query->where(
//          "(STR_TO_DATE(time.field_event_time_value,'%Y-%m-%dT%TZ') >= NOW()
//                    AND STR_TO_DATE(time.field_event_time_value,'%Y-%m-%dT%TZ') <= NOW() + INTERVAL 7 DAY)");
        $query->orderBy('time.field_event_time_value', 'ASC');
        $query->range('0', '5');
        return $query->execute()->fetchAll();
      } else {
        if (isset($params['type']) && $params['type'] != 'all') $query->condition('nfd.type', $params['type'], '=');
        $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(30);
        $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);
        return $pager->execute()->fetchAll();
      }
    } catch (DatabaseExceptionWrapper $e) {
      $this->logger->error(
        '@e <br> <pre>%t</pre>',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return FALSE;
    }
  }
}
