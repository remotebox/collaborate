<?php

namespace Drupal\student_event_views\Controller;

use Drupal\collaborate_integration\CollaborateService;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Driver\mysql\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\ual_tools\BootstrapElementsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class RegistrationManagementController.
 */
class RegistrationManagementController extends ControllerBase {

  /**
   * Drupal\Core\Form\FormBuilderInterface definition.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Drupal\Core\Database\Connection definition.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The Collaborate API service.
   *
   * @var \Drupal\collaborate_integration\CollaborateService
   */
  protected $collaborate;

  /**
   * The Bootstrap element service.
   *
   * @var \Drupal\ual_tools\BootstrapElementsService
   */
  protected $bootstrap;

  /**
   * Constructs a new RegistrationManagementController object.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param \Drupal\collaborate_integration\CollaborateService $collaborate
   *   The Collaborate API service.
   * @param \Drupal\ual_tools\BootstrapElementsService $bootstrap
   *   The Bootstrap element service.
   */
  public function __construct(
    FormBuilderInterface $form_builder,
    RequestStack $request_stack,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    AccountInterface $account,
    CollaborateService $collaborate,
    BootstrapElementsService $bootstrap
  ) {
    $this->formBuilder = $form_builder;
    $this->requestStack = $request_stack;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->messenger = $messenger;
    $this->account = $account;
    $this->collaborate = $collaborate;
    $this->bootstrap = $bootstrap;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('request_stack'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('student_event_views'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('collaborate_integration.collaborate'),
      $container->get('ual_tools.bootstrap_elements')
    );
  }

  /**
   * Show registrations.
   *
   * @param bool $block
   *   Whether the information is display in page or via front page block.
   *
   * @return array
   *   The registration table in render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\GuzzleHttp\Exception\GuzzleException
   */
  public function showRegistrations($block = FALSE) {
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

    $build['filters'] = $this->formBuilder->getForm('Drupal\student_event_views\Form\RegistrationFilterForm');

    $header = $this->buildHeader();

    $teaching_format = 'tutorials or workshops';
    if (isset($params['type'])) {
      if ($params['type'] == 'tutorial') {
        $teaching_format = 'tutorials';
      }
      if ($params['type'] == 'workshop') {
        $teaching_format = 'workshops';
      }
    }

    $build['registrations'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t(
        "<p class='text-align-center'>You currently have no upcoming $teaching_format. Please check out our <a href='/face-to-face-support'>face to face support</a>.</p>"
      ),
      '#prefix' => "<div class='table-responsive row-full px-5 py-3'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => [''],
      ],
      '#attached' => [
        'library' => ['collaborate_integration/change_status']
      ]
    ];

    $registrations = $this->getRegistrations($params, $header, $block);
    if (!$registrations) {
      return $build;
    }

    $nodes = $this->loadNodes($registrations);
    if (!$nodes) {
      return $build;
    }

    foreach ($registrations as $registration) {
      $build['registrations'][$registration->sid] = $this->buildRow(
        $nodes[$registration->nid],
        $registration
      );
      $build['registrations'][$registration->sid . '-actions'] = $this->buildRowActions(
        $nodes[$registration->nid],
        $registration
      );
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];
    return $build;
  }

  /**
   * Builds the table header.
   *
   * @return array
   *   The table header array.
   */
  public function buildHeader() {
    return [
      ['data' => t('Teaching format'), 'class' => ['align-middle']],
      ['data' => t('Event'), 'class' => ['align-middle']],
      ['data' => t('Tutor'), 'class' => ['align-middle']],
      ['data' => t('Location'), 'class' => ['align-middle']],
      ['data' => t('Room'), 'class' => ['align-middle']],
      [
        'data' => t('Date/time'),
        'field' => 'time.field_event_time_value',
        'sort' => 'desc',
        'class' => ['align-middle'],
      ],
      ['data' => t('Registration status'), 'class' => ['align-middle']],
    ];
  }

  /**
   * Gets registrations for the user via db select call.
   *
   * @param array $params
   *   Query string submitted options.
   * @param array $header
   *   The table header.
   * @param bool $block
   *   Whether this is the front page block or controller displaying.
   *
   * @return object|false
   *   Returns the database information in object form or FALSE if error.
   */
  public function getRegistrations(array $params, array $header, bool $block) {
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
        'field_event_time_end_value',
      ]);
      $query->addField(
        'tutor',
        'field_event_tutor_target_id',
        'tutor'
      );
      $query->addField('wsd2', 'value', 'registration_status');
      $query->condition('wsd.value', $this->account->id(), '=');
      if ($block == TRUE) {
        $query->condition('wsd2.value', [
          'timetabled_by_course_team',
          'attending',
        ], 'IN');
        $currentTime = new DrupalDateTime('now');
        $currentTime->setTimezone(new \DateTimeZone('UTC'));
        $query->condition('time.field_event_time_end_value', $currentTime->format('Y-m-d\TH:i:s'), '>=');
        $query->orderBy('time.field_event_time_value', 'ASC');
        $query->range('0', '5');
        return $query->execute()->fetchAll();
      }
      else {
        if (isset($params['type']) && $params['type'] != 'all') {
          $query->condition('nfd.type', $params['type'], '=');
        }
        $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')
          ->limit(30);
        $query->extend('Drupal\Core\Database\Query\TableSortExtender')
          ->orderByHeader($header);
        return $pager->execute()->fetchAll();
      }
    }
    catch (DatabaseExceptionWrapper $e) {
      $this->logger->error(
        '@e <br> <pre>%t</pre>',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Load multiple nodes.
   *
   * @param array $nids
   *   An array of node IDs.
   *
   * @return array
   *   Returns an array of loaded nodes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadNodes(array $nids) {
    return $this->entityTypeManager->getStorage('node')
      ->loadMultiple(
        array_column($nids, 'nid')
      );
  }

  /**
   * Builds the table row.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   * @param object $registration
   *   Registration information @return array
   *   Returns row display render array.
   *
   * @return array
   *   Returns row display render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @see getRegistrations()
   */
  public function buildRow(Node $node, object $registration) {
    $taxonomy = $this->loadTaxonomyTerms($node);

    $tutor_name = $this->getUserFullName($node, $registration);

    $now = new DrupalDateTime('now');
    $start = $this->buildDate($registration->field_event_time_value);
    $end = $this->buildDate($registration->field_event_time_end_value);

    $date = $start->format('d M Y');
    $time = $start->format('H:i') . ' - ' . $end->format('H:i');
    $date_markup = "<div class='mr-3 d-inline-block'>
                      <span class='far fa-clock' aria-hidden='true'></span> $time
                    </div>
                    <div class='mr-3 d-inline-block'>
                      <span class='far fa-calendar-alt' aria-hidden='true'></span> $date
                    </div>";

    if ($registration->type == 'workshop') {
      $hide_day = $node->hasField('field_hide_day') ? $node->get('field_hide_day')->value : 0;
      $hide_time = $node->hasField('field_hide_time') ? $node->get('field_hide_time')->value : 0;
      if ($hide_day == 1) {
        $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> " . $start->format('M Y') . "</div>";
      }
      if ($hide_time == 1) {
        $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> " . $start->format('d M Y') . "</div>";
      }
    }

    $build['teaching_format'] = [
      '#type' => 'item',
      '#markup' => t('@node_type', ['@node_type' => ucfirst($registration->type)]),
    ];

    $event_link = Link::fromTextAndURL(
      $registration->title,
      Url::fromRoute('entity.node.canonical',
        ['node' => $registration->nid]
      )
    )->toString();

    $build['title'] = [
      '#type' => 'item',
      '#markup' => $event_link,
    ];


    $build['tutor'] = [
      '#type' => 'item',
      '#markup' => t('@tutor', ['@tutor' => isset($tutor_name) ? $tutor_name : 'Not available']),
    ];

    $build['building_location'] = [
      '#type' => 'item',
      '#markup' => t('@building_location',
        [
          '@building_location' => isset($taxonomy['buildings']) ? $taxonomy['buildings'] : 'Not available',
        ]
      ),
    ];

    $build['room_location'] = [
      '#type' => 'item',
      '#markup' => t('@room_location',
        [
          '@room_location' => isset($taxonomy['rooms']) ? $taxonomy['rooms'] : 'Not available',
        ]
      )
    ];


    $build['date_time'] = [
      '#type' => 'item',
      '#markup' => $date_markup,
    ];

    $registration_status = $this->prettyRegistrationStatus($registration);
    $build['status'] = [
      '#type' => 'item',
      '#markup' => t(
        '@status',
        ['@status' => $registration_status]
      ),
    ];
    return $build;
  }

  /**
   * Builds the table row actions.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   * @param object $registration
   *   Registration information @return array
   *   Returns row display render array.
   *
   * @return array
   *   Returns row display render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @see getRegistrations()
   */
  public function buildRowActions(Node $node, object $registration) {
    $build = [
      '#type' => 'item',
      '#attributes' => [
        'class' => [''],
      ],
    ];

    $build['wrapper'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['text-align-center', 'pb-2'],
      ],
      '#wrapper_attributes' => [
        'colspan' => 7,
        'class' => ['border-top-0'],
      ],
    ];

    $now = new DrupalDateTime('now');
    $start = $this->buildDate($registration->field_event_time_value);
    $end = $this->buildDate($registration->field_event_time_end_value);

    $registration_status = $this->prettyRegistrationStatus($registration);
    $is_attending = $registration_status == 'Attending';
    $has_attended = $registration_status == 'Attended';

    $user_entity = $this->entityTypeManager->getStorage('user')
      ->load($this->account->id());

    // Cancel button.
    // @todo: if future content types are added, modify wording check.
    if ($start >= $now && $is_attending) {
      $build['wrapper']['cancel'] = $this->bootstrap->buttonLink(
        'far fa-times-circle',
        ($node->bundle() == 'workshop') ? 'Cancel attendance' : 'Cancel meeting',
        Url::fromUri('base:/event/cancel/' . $registration->sid)->toString()
      );
    }

    if ($now <= $end) {
      // Join button.
      $has_reference = !empty($node->get('field_collaborate_session_ref')->target_id);
      if ($is_attending && $has_reference) {
        $collaborate_session = $this->entityTypeManager
          ->getStorage('collaborate_session')
          ->load($node->get('field_collaborate_session_ref')->target_id);

        $enrolment = $this->collaborate->getEnrolment(
          $collaborate_session,
          $user_entity
        );

        if (isset($enrolment->permanentUrl)) {
          $build['wrapper']['join_link'] = $this->bootstrap->buttonLink(
            'fas fa-chalkboard-teacher',
            'Join session',
            Url::fromUri($enrolment->permanentUrl)->toString(),
            ($now <= $end && $now >= $start->modify('- 15 mins')) ? FALSE : TRUE,
            'join-' . $registration->sid,
            ['collaborate-launch'],
            [
              'data-sid' => $registration->sid,
              'data-status' => $registration->registration_status
            ]
          );
          $build['wrapper']['join_info'] = [
            '#type' => 'markup',
            '#markup' => "<p class='small mt-2'><span class='fas fa-info-circle icon-accent mr-1' aria-hidden='true'></span>Collaborate session links activate 15 minutes before the start of the session.</p>",
            '#weight' => 100,
          ];
        }
      }
    }

    // Attendee information button.
    if ($is_attending && $node->id() == $registration->nid) {
      if (trim(strip_tags($node->get('field_event_attendee_information')->value))) {
        $build['wrapper']['attendee_info'] =
          $this->bootstrap->modal(
            'far fa-file-alt',
            'Session information',
            'ai-' . $registration->sid,
            'ai-title-' . $registration->sid,
            'Session information',
            $node->get('field_event_attendee_information')->value
          );
      }
    }

    // Follow up information button.
    $has_attended_content = $node->get('field_resource_builder')->target_id;
    if ($has_attended && $node->id() == $registration->nid && $has_attended_content) {
      $build['wrapper']['post_session_resource'] = $this->bootstrap->buttonLink(
        'fas fa-book',
        'Follow up information',
        Url::fromRoute(
          'entity.node.canonical',
          ['node' => $node->id()],
          ['fragment' => 'attended-info', 'target' => '_blank']
        )->toString()
      );
    }

    // Session recording button.
    if ($has_attended) {
      $recordings = $this->collaborate->getRecordings(
        $user_entity
      );

      if (isset($recordings->results)) {
        foreach ($recordings->results as $recording) {
          $match = ($recording->sessionStartTime === $start->format('Y-m-d\TH:i:s.\0\0\0\Z'));
          $not_protected = ($recording->restricted === FALSE);
          $done = ($recording->status === 'DONE');
          if ($match && $not_protected && $done) {
            $recording_link = $this->collaborate->getRecordingLink($recording->id);
            if ($node->id() == $registration->nid  && $recording_link !== FALSE) {
              $build['wrapper']['post_session_recording'] = $this->bootstrap->buttonLink(
                'fas fa-video',
                'View recording',
                Url::fromUri($recording_link)->toString()
              );
            }
          }
        }
      }
    }

    // Tutor feedback button.
    if ($registration->type == 'tutorial') {
      $feedback = $this->getStudentFeedback($registration);
      if (!empty($feedback)) {
        $build['wrapper']['feedback'] = [
          '#type' => 'item',
          '#wrapper_attributes' => [
            'class' => ['mr-3', 'border-top-0'],
          ],
          $this->bootstrap->modal(
            'far fa-file-alt',
            'Tutor feedback',
            'tf-' . $registration->sid,
            'tf-title-' . $registration->sid,
            'Tutor feedback',
            $feedback
          )
        ];
      }
    }
    return $build;
  }

  /**
   * Returns the label strings for taxonomy terms.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   *
   * @return array
   *   Returns row display render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadTaxonomyTerms(Node $node) {
    $tids = [];
    $tids[] = $node->get('field_event_building')->target_id;
    $tids[] = $node->get('field_event_room')->target_id;
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadMultiple(array_filter($tids));
    $taxonomy = [];
    foreach ($terms as $term) {
      $taxonomy[$term->bundle()] = $term->label();
    }
    return $taxonomy;
  }

  /**
   * Returns the name string from a profile.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   *
   * @return string
   *   Returns a concatenated name string.
   */
  public function getUserFullName(Node $node) {
    $tutor_id = $node->get('field_event_tutor')->target_id;
    if ($tutor_id) {
      try {
        $dq = $this->database->select('profile', 'profile');
        $dq->addField('f_name', 'field_prof_first_name_value', 'fname');
        $dq->addField('l_name', 'field_prof_last_name_value', 'lname');
        $dq->join('profile__field_prof_first_name', 'f_name', 'profile.profile_id = f_name.entity_id');
        $dq->join('profile__field_prof_last_name', 'l_name', 'profile.profile_id = l_name.entity_id');
        $dq->condition('profile.uid', $tutor_id, '=');
        $dq->condition('profile.type', 'employee', '=');
        $tutor = $dq->execute()->fetchAssoc();
        if (!empty($tutor['fname']) && !empty($tutor['lname'])) {
          return trim($tutor['fname']) . ' ' . trim($tutor['lname']);
        }
        else {
          return '';
        }
      }
      catch (DatabaseExceptionWrapper $e) {
        $this->logger->error(
          '@e <br> <pre>%t</pre>',
          [
            '@e' => $e->getMessage(),
            '%t' => $e->getTraceAsString(),
          ]
        );
        return '';
      }
    }
  }

  /**
   * Create a date object in Europe/London timezone.
   *
   * @param string $date_field
   *   The date string to convert (YYYY-MM-DDTH:i:s).
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime
   *   Returns the date in a DrupalDateTime object.
   */
  public function buildDate($date_field) {
    return DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $date_field,
      DateTimeItemInterface::STORAGE_TIMEZONE
    )->setTimezone(new \DateTimeZone('Europe/London'));
  }

  /**
   * Convert registration status to readable string.
   *
   * @param object $registration
   *   Registration information @see getRegistrations().
   *
   * @return string
   *   Readable version of the registration status.
   */
  public function prettyRegistrationStatus($registration) {
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
    return $registration_status;
  }

  /**
   * Retrieve any feedback from a tutor.
   *
   * @param object $registration
   *   Registration information @see getRegistrations().
   *
   * @return string|false
   *   Tutor feedback comments.
   */
  private function getStudentFeedback($registration) {
    $feedback_query = $this->database->select('webform_submission_data', 'wsd');
    $feedback_query->addField('wsd', 'value');
    $feedback_query->condition('wsd.sid', $registration->sid, '=');
    $feedback_query->condition('wsd.name', 'student_feedback', '=');
    $feedback = $feedback_query->execute()->fetchField();
    if (!empty($feedback)) {
      $pattern = '@(http(s)?://)?(([a-zA-Z])([-\w]+\.)+([^\s\.]+[^\s]*)+[^,.\s])@';
      return preg_replace(
        $pattern,
        '<a href="http$2://$3" target=\"_blank\" rel=\"nofollow\">$0</a>',
        $feedback
      );
    }
    else {
      return FALSE;
    }
  }

}
