<?php

namespace Drupal\events_register\Form;

use Drupal\collaborate_integration\CollaborateService;
use Drupal\collaborate_integration\Entity\CollaborateSession;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\profile\Entity\Profile;
use Drupal\ual_tools\FilterGroupsService;
use Drupal\ual_tools\GroupNameService;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\WebformOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class EventRegisterForm.
 */
class EventsRegisterForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Webform storage.
   *
   * @var \Drupal\webform\WebformEntityStorageInterface
   */
  protected $webformStorage;

  /**
   * The Webform submission storage.
   *
   * @var \Drupal\webform\WebformSubmissionStorageInterface
   */
  protected $webformSubmissionStorage;

  /**
   * The Webform request handler.
   *
   * @var \Drupal\webform\WebformRequestInterface
   */
  protected $requestHandler;

  /**
   * The Drupal account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager service.
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
   * @var \Drupal\ual_tools\FilterGroupsService
   */
  protected $filterGroups;

  /**
   * @var \Drupal\ual_tools\GroupNameService
   */
  protected $groupName;

  /**
   * The Collaborate API service.
   *
   * @var \Drupal\collaborate_integration\CollaborateService
   */
  protected $collaborate;

  /**
   * Constructs a new EventRegisterForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   * @param \Drupal\Core\Session\AccountInterface $account
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\ual_tools\FilterGroupsService $filterGroups
   * @param \Drupal\ual_tools\GroupNameService $groupName
   * @param \Drupal\collaborate_integration\CollaborateService $collaborate
   *   The Collaborate API service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(
    Connection $database,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $account,
    RequestStack $requestStack,
    LoggerInterface $logger,
    FilterGroupsService $filterGroups,
    GroupNameService $groupName,
    CollaborateService $collaborate
  ) {
    $this->database = $database;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->webformStorage = $this->entityTypeManager->getStorage('webform');
    $this->webformSubmissionStorage = $this->entityTypeManager->getStorage('webform_submission');
    $this->account = $account;
    $this->requestStack = $requestStack;
    $this->logger = $logger;
    $this->filterGroups = $filterGroups;
    $this->groupName = $groupName;
    $this->collaborate = $collaborate;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('events_register'),
      $container->get('ual_tools.filter_groups'),
      $container->get('ual_tools.group_name'),
      $container->get('collaborate_integration.collaborate')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'event_register';
  }

  /**
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
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

    $form['filters'] = $this->getFilters($params);

    // @todo: Display user selected filters
    // if (!empty($params)) {
    // }

    $form['empty_tutorials'] = $this->emptyTutorials($params);

    $header = $this->buildHeader();

    $form['register'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t('No submissions can be found for today.'),
      '#prefix' => "<div class='table-responsive mt-3 row-full p-5'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => ['table-sm'],
        'style' => 'font-size: .85rem',
      ]
    ];

    $results = $this->getReportData(0, $params, $header);
    if ($results) {
      // Get select box options once, rather than on each loop.
      $theme_opts = $this->getTaxonomyTerms('tutorial_themes');
      $referral_opts = $this->getTaxonomyTerms('tutorial_referrals');
      // Flag for if only workshops are visible then hide the tutorial headers.
      $only_workshops = TRUE;

      foreach ($results as $row) {
        if ($row->webform_id == 'tutorial') {
          $only_workshops = FALSE;
        }
        $form['register'][$row->sid] = $this->buildRow($row, $theme_opts, $referral_opts, $only_workshops);
      }

      // If viewing only workshops unset tutorial headers.
      if ($only_workshops === TRUE) {
        unset($form["register"]["#header"][5]);
        unset($form["register"]["#header"][6]);
        unset($form["register"]["#header"][7]);
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['text-center', 'fixed-bottom', 'bg-light', 'p-2']
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#name' => 'update',
      '#prefix' => "<p class='mb-n1'>Please don't forget to save any changes before leaving this page.</p><br>"
    ];

    $form['register_pager_0'] = [
      'pager' => [
        '#type' => 'pager',
        '#element' => 0,
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#name'] === 'filter') {
      if (!empty($form_state->getValue(['filter_container', 'q', 'row1', 'nid'])) &&
          !is_numeric($form_state->getValue(['filter_container', 'q', 'row1', 'nid']))) {
        $form_state->setErrorByName('filter_container][q][row1][nid', $this->t('Please enter a numerical value.'));
      }
      if (!empty($form_state->getValue(['filter_container', 'q', 'row1', 'sid'])) &&
          !is_numeric($form_state->getValue(['filter_container', 'q', 'row1', 'sid']))) {
        $form_state->setErrorByName('filter_container][q][row1][sid', $this->t('Please enter a numerical value.'));
      }
//      @TODO: Add validation for multi-choice options
      if (!empty($form_state->getValue(['filter_container', 'q', 'row1', 't'])) &&
          preg_match("/^[ a-zA-Z0-9]+$/", $form_state->getValue(['filter_container', 'q', 'row1', 't'])) != 1) {
        $form_state->setErrorByName('filter_container][q][row1][t', $this->t('Please enter only alphanumeric characters.'));
      }
//      @TODO: Add validation for dates
//      $d = DateTime::createFromFormat('Y-m-d', $form_state->getValue(['filter_container', 'q', 'sdr']));
//      if ($d && $d->format($format) !== $date) {
//      }
    } elseif ($form_state->getTriggeringElement()['#name'] === 'update') {
      $register_values = $form_state->getValues()['register'];
      if (empty($register_values)) {
        $form_state->setErrorByName("register", $this->t('Nothing to update.'));
      } else {
        $i = 0;
        foreach ($register_values as $key => $value) {
//          @TODO: Validate input without limiting tutors.

//            $start_date = $form_state->getValue(['register', $i, 'date_container', 'start_date_time']);
//            if ($form["register"][$i]['date_container']["start_date_time"]["#default_value"] != $start_date) {
//              $form_state->setErrorByName("register][$i][date_container][start_date_time", $this->t('Please enter a valid time.'));
//            }
//            $end_date = $form_state->getValue(['register', $i, 'date_container', 'end_date_time']);
//            if ($form["register"][$i]['date_container']["end_date_time"]["#default_value"] != $end_date) {
//              $form_state->setErrorByName("register][$i][date_container][end_date_time", $this->t('Please enter a valid time.'));
//            }
//          if (isset($value["tutorial_themes"])) {
//            $other_themes = $form_state->getValue(['register', $i, 'tutorial_themes', 'other_themes']);
//            if ($form['register'][$i]['tutorial_themes']['other_themes']['#default_value'] != $other_themes
//              && preg_match("/^[ a-zA-Z0-9'.,-]+$/", $other_themes) != 1) {
//              $form_state->setErrorByName("register][$i][tutorial_themes][other_themes", $this->t('Please enter only alphanumeric characters.'));
//            }
//            $student_feedback = $form_state->getValue(['register', $i, 'student_feedback']);
//            if ($form['register'][$i]['student_feedback']['#default_value'] != $student_feedback
//              && preg_match("/^[ a-zA-Z0-9'.,-]+$/", $student_feedback) != 1) {
//              $form_state->setErrorByName("register][$i][student_feedback", $this->t('Please enter only alphanumeric characters.'));
//            }
//          }

          $i++;
        }
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#name'] === 'filter') {
      $values = NestedArray::getValue(
        $form_state->getValues(),
        ['filter_container', 'q']
      );
      $params = [];
      foreach ($values as $key => $value) {
        foreach (array_filter($value) as $subkey => $subvalue) {
          if (is_array($subvalue)) {
            $params[$subkey] = implode('/', array_filter($subvalue));
          }
          else {
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
    elseif ($form_state->getTriggeringElement()['#name'] === 'update') {
      foreach ($form_state->getValues()['register'] as $key => $value) {
        $changes = [];
        $register_original = (object) [
          'start_time' => $form["register"][$key]['date_container']["start_date_time"]["#default_value"],
          'end_time' => $form["register"][$key]['date_container']["end_date_time"]["#default_value"],
          'registration_status' => $form["register"][$key]["status"]["#default_value"],
        ];
        $register_submit = (object) [
          'start_time' => $form_state->getValue(
            [
              'register',
              $key,
              'date_container',
              'start_date_time'
            ]
          ),
          'end_time' => $form_state->getValue(
            [
              'register',
              $key,
              'date_container',
              'end_date_time'
            ]
          ),
          'registration_status' => $form_state->getValue(
            [
              'register',
              $key,
              'status'
            ]
          ),
        ];

        if (($register_original->start_time != $register_submit->start_time)
            || ($register_original->end_time != $register_submit->end_time)) {
          $this->changeTime(
            $form_state->getValue(['register', $key, 'info', 'node_id']),
            $register_submit->start_time,
            $register_submit->end_time
          );
        }

        if ($register_original->registration_status != $register_submit->registration_status) {
          $changes['status'] = $form_state->getValue(
            [
              'register',
              $key,
              'status'
            ]
          );
        }

        if (isset($value["tutorial_themes"])) {
          $themes_original = (object) [
            'primary_theme' => $form['register'][$key]['tutorial_themes']['primary_theme']['#default_value'],
            'secondary_theme' => $form['register'][$key]['tutorial_themes']['secondary_theme']['#default_value'],
            'other_themes' => $form['register'][$key]['tutorial_themes']['other_themes']['#default_value'],
            'student_feedback' => $form['register'][$key]['student_feedback']['#default_value'],
            'referral' => $form['register'][$key]['referral']['#default_value'],
          ];
          $themes_submit = (object) [
            'primary_theme' => !empty(
              $form_state->getValue(
                [
                  'register',
                  $key,
                  'tutorial_themes',
                  'primary_theme'
                ]
              )
            ) ? $form_state->getValue(
              [
                'register',
                $key,
                'tutorial_themes',
                'primary_theme'
              ]
            ) : [],
            'secondary_theme' => !empty(
              $form_state->getValue(
                [
                  'register',
                  $key,
                  'tutorial_themes',
                  'secondary_theme'
                ]
              )
            ) ? $form_state->getValue(
              [
                'register',
                $key,
                'tutorial_themes',
                'secondary_theme'
              ]
            ) : [],
            'other_themes' => $form_state->getValue(
              [
                'register',
                $key,
                'tutorial_themes',
                'other_themes'
              ]
            ),
            'student_feedback' => $form_state->getValue(
              [
                'register',
                $key,
                'student_feedback'
              ]
            ),
            'referral' => !empty(
              $form_state->getValue(
                [
                  'register',
                  $key,
                  'referral'
                ]
              )
            ) ? $form_state->getValue(
              [
                'register',
                $key,
                'referral'
              ]
            ) : []
          ];

          if ($themes_original->primary_theme != $themes_submit->primary_theme) {
            $changes['primary_theme'] = $themes_submit->primary_theme;
          }

          if ($themes_original->secondary_theme != $themes_submit->secondary_theme) {
            $changes['secondary_theme'] = $themes_submit->secondary_theme;
          }

          if ($themes_original->other_themes != $themes_submit->other_themes) {
            $changes['other_themes'] = $themes_submit->other_themes;
          }

          if ($themes_original->student_feedback != $themes_submit->student_feedback) {
            $changes['student_feedback'] = $themes_submit->student_feedback;
          }

          if ($themes_original->referral != $themes_submit->referral) {
            $changes['referral'] = $themes_submit->referral;
          }
        }

        $this->registrationUpdate($form_state->getValue(
            ['register', $key, 'info', 'submission_id']
          ),
          $changes
        );
      }
    }
  }

  /**
   * Reset options.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function resetForm(array &$form, FormStateInterface $form_state) {
    //@TODO Check if we need to rebuild the form
    $form_state->setRedirect(
      $this->getRouteMatch()->getRouteName()
    );
  }

  /**
   * Builds the register table header.
   *
   * @return array[]
   *   Returns the table header.
   */
  public function buildHeader() {
    return [
      [
        'data' => $this->t('Event information'),
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Event date/time'),
        'field' => 'nfet.field_event_time_value',
        'sort' => 'asc',
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Student information'),
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Registration status'),
        'field' => 'registration_status',
        'sort' => 'asc',
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Themes'),
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Notes for students'),
        'class' => ['align-middle', 'py-4']
      ],
      [
        'data' => $this->t('Referral'),
        'class' => ['align-middle', 'py-4']
      ],
    ];
  }

  /**
   * Builds the row of the registration table.
   *
   * @param object $row
   *   The row of data from @see getReportData().
   * @param array $theme_opts
   *   The select options to populate the themes list.
   * @param array $referral_opts
   *   The select options to populate the referral list.
   *
   * @return array
   *   Returns the render array of the row.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function buildRow($row, array $theme_opts, array $referral_opts) {
    $submission = $this->webformSubmissionStorage->load($row->sid);
    $submission_data = $submission->getData();

    $start_date = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $row->field_event_time_value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    )->setTimezone(new \DateTimeZone('Europe/London'));
    $end_date = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $row->field_event_time_end_value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    )->setTimezone(new \DateTimeZone('Europe/London'));

    $form['info'] = [
      '#type' => 'container',
      '#wrapper_attributes' => [
        'class' => 'align-middle'
      ]
    ];

    if ($row->type == 'tutorial') {
      $clone = Link::fromTextAndURL(
        t('<br>Clone session: @nid', ['@nid' => $row->nid]),
        Url::fromUri('base:/node/' . $row->nid . '/clone',
          ['attributes' => ['target' => '_blank']])
      )->toString();
    }
    else {
      $clone = '';
    }

    $room = 'n/a';
    if (!empty($row->room)) {
      $room_term = $this->entityTypeManager->getStorage(
        'taxonomy_term'
      )->load($row->room);
      $room = $room_term->label();
    }

    $mod_link = '';
    $student_link = '';
    $guest_link = '';
    if ($row->collaborate_id) {
      $collaborate_entity = $this->entityTypeManager->getStorage('collaborate_session')
        ->load($row->collaborate_id);
      if ($collaborate_entity instanceof CollaborateSession) {
        $current_user_account = $this->entityTypeManager
          ->getStorage('user')
          ->load($this->account->id());
        $registree_account = $this->entityTypeManager
          ->getStorage('user')
          ->load($submission->getElementData('student_id'));
        $current_user_enrolment = $this->collaborate->getEnrolment($collaborate_entity, $current_user_account);
        if ($current_user_enrolment) {
          $mod_link = Link::fromTextAndUrl(
            'Your Collaborate link',
            Url::fromUri(
              $current_user_enrolment->permanentUrl,
              ['attributes' => ['target' => '_blank']]
            )
          )->toString();
        }
        $registree_enrolment = $this->collaborate->getEnrolment($collaborate_entity, $registree_account);
        if ($registree_enrolment) {
          $student_link = Link::fromTextAndUrl(
            'Registered user Collaborate link',
            Url::fromUri(
              $registree_enrolment->permanentUrl,
              ['attributes' => ['target' => '_blank']]
            )
          )->toString();
        }
        $guestURL = $collaborate_entity->get('guestURL')->value;
        if ($guestURL) {
          $guest_link = Link::fromTextAndUrl(
            'Guest link',
            Url::fromUri(
              $guestURL,
              ['attributes' => ['target' => '_blank']]
            )
          )->toString();
        }
      }
    }

    // @todo: refactor this into longer render array and don't display
    // items which aren't relevant.
    $form['info']['id_info'] = [
      '#type' => 'item',
      '#markup' => t('<b>Session:</b> @nid <br /> <b>Submission:</b> @sid <br /> <b>Title:</b> @title <br /> <b>Room:</b> @room <br /> @collab_mod <br />  @collab_student <br />@guest<br />@clone',
        [
          '@nid' => $row->nid,
          '@sid' => $row->sid,
          '@title' => Link::fromTextAndURL(
            $row->title,
            Url::fromRoute('entity.node.canonical', ['node' => $row->nid],
              ['attributes' => ['target' => '_blank']]
            )
          )->toString(),
          '@room' => $room,
          '@clone' => $clone,
          '@collab_mod' => $mod_link,
          '@collab_student' => $student_link,
          '@guest' => $guest_link
        ]
      ),
      '#weight' => '0',
    ];

    $form['info']['node_id'] = [
      '#type' => 'hidden',
      '#value' => $row->nid,
    ];

    $form['info']['submission_id'] = [
      '#type' => 'hidden',
      '#value' => $row->sid,
    ];

    $form['date_container'] = [
      '#type' => 'container',
      '#wrapper_attributes' => [
        'class' => 'align-middle'
      ]
    ];

    $form['date_container']['date'] = [
      '#type' => 'item',
      '#markup' => $start_date->format('d/m/Y'),
      '#value' => $start_date->format('d/m/Y'),
      '#weight' => '0',
    ];

    $form['date_container']['start_date_time'] = [
      '#type' => 'textfield',
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $start_date->format('H:i'),
      '#weight' => '0',
      '#attributes' => [
        'class' => ['form-control-sm', 'text-align-center']
      ]
    ];

    $form['date_container']['end_date_time'] = [
      '#type' => 'textfield',
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $end_date->format('H:i'),
      '#weight' => '0',
      '#attributes' => [
        'class' => ['form-control-sm', 'text-align-center']
      ]
    ];

    $student_profile = $this->getStudentProfile(
      $submission->getElementData('student_id')
    );

    $sits_id = '';
    $full_name = '';
    if ($student_profile instanceof Profile) {
      $sits_id = $student_profile->get('field_prof_sits_id')->value;
      $full_name = $student_profile->get('field_prof_first_name')->value . ' ' . $student_profile->get('field_prof_last_name')->value;
    }

    try {
      $student_user = $this->entityTypeManager->getStorage('user')->load(
        $submission->getElementData('student_id')
      );
      if ($student_user instanceof User) {
        $username = $student_user->getUsername();
        if ($username) {
          $username = Link::fromTextAndURL(
            $username,
            Url::fromUri('base:/tools/student-overview',
              [
                'query' => ['name' => $username],
                'attributes' => ['target' => '_blank']
              ]
            ))->toString();
        }
        $email = $student_user->getEmail();
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error interacting with student entity in buildForm()<br>>@e<br>%t',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
    }


    $form['student_information'] = [
      '#type' => 'item',
      '#markup' => t(
        "<b>Username:</b> @username <br />
                <b>SITS ID:</b> @sits <br />
                <b>Name:</b> @name <br />
                <b>Email:</b> <a href='mailto:@email'>@email</a> <br />
                <b>College:</b> @college <br />
                <b>Programme:</b> @programme <br />
                <b>Course:</b> @course <br />
                <b>Year:</b> @year <br /> <br />
                <b>Requested tutorial focus:</b> @focus",
        [
          '@username' => !empty($username) ? $username : 'n/a',
          '@sits' => !empty($sits_id) ? $sits_id : 'n/a',
          '@name' => !empty($full_name) ? $full_name : 'n/a',
          '@email' => !empty($email) ? $email : 'n/a',
          '@college' => !empty($submission_data['student_provider']) ? $this->groupName->groupName($submission_data['student_provider']) : 'n/a',
          '@programme' => !empty($submission_data['student_programme']) ? $this->groupName->groupName($submission_data['student_programme']) : 'n/a',
          '@course' => !empty($submission_data['student_course']) ? $this->groupName->groupName($submission_data['student_course']) : 'n/a',
          '@year' => !empty($submission_data['student_year']) ? $submission_data['student_year'] : 'n/a',
          '@focus' => !empty($submission_data['tutorial_focus']) ? $submission_data['tutorial_focus'] : 'n/a',
        ]
      ),
      '#weight' => '0',
      '#wrapper_attributes' => [
        'class' => 'align-middle',
      ]
    ];

    $element = ['#options' => 'registration_states'];
    $options = WebformOptions::getElementOptions($element);
    $form['status'] = [
      '#type' => 'select',
      '#options' => $options,
      '#default_value' => !empty($submission_data['registration_status']) ? $submission_data['registration_status'] : [],
      '#weight' => 0,
      '#attributes' => [
        'style' => 'min-width:135px',
      ],
      '#wrapper_attributes' => [
        'class' => 'align-middle'
      ]
    ];

    if ($row->webform_id == 'tutorial') {
      $form['tutorial_themes'] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'min-width:135px',
        ],
        '#wrapper_attributes' => [
          'class' => 'align-middle'
        ]
      ];

      $form['tutorial_themes']['primary_theme'] = [
        '#type' => 'select',
        '#options' => $theme_opts,
        '#empty_option' => $this->t('- Select a theme -'),
        '#default_value' => !empty($submission_data['primary_theme']) ? $submission_data['primary_theme'] : [],
        '#weight' => 0,
      ];

      $form['tutorial_themes']['secondary_theme'] = [
        '#type' => 'select',
        '#options' => $theme_opts,
        '#empty_option' => $this->t('- Select a theme -'),
        '#default_value' => !empty($submission_data['secondary_theme']) ? $submission_data['secondary_theme'] : [],
        '#weight' => 0,
      ];

      $form['tutorial_themes']['other_themes'] = [
        '#type' => 'textfield',
        '#size' => 20,
        '#maxlength' => 20,
        '#default_value' => !empty($submission_data['other_themes']) ? $submission_data['other_themes'] : '',
        '#weight' => '0',
      ];

      $form['student_feedback'] = [
        '#type' => 'textarea',
        '#default_value' => !empty($submission_data['student_feedback']) ? $submission_data['student_feedback'] : '',
        '#wrapper_attributes' => [
          'class' => 'align-middle'
        ]
      ];

      $form['referral'] = [
        '#type' => 'select',
        '#options' => $referral_opts,
        '#empty_option' => $this->t('- Select a referral -'),
        '#default_value' => !empty($submission_data['referral']) ? $submission_data['referral'] : [],
        '#weight' => 0,
        '#attributes' => [
          'style' => 'min-width:135px',
        ],
        '#wrapper_attributes' => [
          'class' => 'align-middle'
        ]
      ];
    }

    return $form;
  }

  /**
   * Gets taxonomy terms from a vocabulary in select option format.
   *
   * @param string $machine_name
   *   The machine name of the vocabulary.
   *
   * @return array
   *   Returns the taxonomy terms in a select list compatible array.
   *   Or a blank array if error.
   */
  private function getTaxonomyTerms($machine_name) {
    try {
      $vid = $machine_name;
      $terms = $this->entityTypeManager->getStorage(
        'taxonomy_term'
      )->loadTree($vid);
      $data = [];
      foreach ($terms as $term) {
        $data[$term->tid] = $term->name;
      }
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error getting taxonomy terms getTaxonomyTerms()<br>@e<br>%t',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return [];
    }
  }

  /**
   * @param string $sits
   *
   * @return \Drupal\profile\Entity\ProfileInterface|boolean
   *
   */
  private function getStudentUID($sits) {
    try {
      $profile_list = $this->entityTypeManager
        ->getStorage('profile')
        ->loadByProperties([
          'field_prof_sits_id' => $sits,
          'type' => 'student',
        ]);

      if (!empty($profile_list)) {
        $profile = reset($profile_list);
        return $profile->get('uid')->target_id;
      } else {
        $this->messenger()->addError(
          t('Could not find the ASO user ID from SITs ID: @sits',
            ['@sits' => $sits])
        );
        return FALSE;
      }
    } catch (\Exception $e) {
      $this->logger->error(
        'Error getting student UID in getStudentUID() <br> <pre>@e<br>%t</pre>',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return FALSE;
    }
  }

  /**
   * @param int $uid
   *
   * @return \Drupal\profile\Entity\Profile|boolean
   */
  private function getStudentProfile($uid) {
    try {
      $profile = $this->entityTypeManager
        ->getStorage('profile')
        ->loadByProperties([
          'uid' => $uid,
          'type' => 'student',
        ]);
      $profile = array_shift($profile);
      if (!empty($profile)) {
        return $profile;
      } else {
        return FALSE;
      }
    } catch (\Exception $e) {
      $this->logger->error(
        'Error getting student profile in getStudentProfile() <br> <pre>@e<br>%t</pre>',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return FALSE;
    }
  }

  /**
   * @param int $nid
   * @param string $start_time
   * @param string $end_time
   *
   * @return void
   */
  private function changeTime($nid, $start_time, $end_time) {
    // @TODO Check DST issues before launch.
    $start_arr = explode(':', $start_time);
    $end_arr = explode(':', $end_time);
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($node instanceof Node) {
        // Get dates in storage time zone and convert to Europe/London
        $start_obj = DrupalDateTime::createFromFormat(
          'Y-m-d\TH:i:s', $node->get('field_event_time')->value,
          DateTimeItemInterface::STORAGE_TIMEZONE
        )->setTimezone(new \DateTimeZone('Europe/London'));
        $end_obj = DrupalDateTime::createFromFormat(
          'Y-m-d\TH:i:s', $node->get('field_event_time')->end_value,
          DateTimeItemInterface::STORAGE_TIMEZONE
        )->setTimezone(new \DateTimeZone('Europe/London'));

        // Update time in Europe/London format and convert to storage format
        $start_obj->setTime($start_arr[0], $start_arr[1], '00')->setTimezone(new \DateTimeZone('UTC'));
        $end_obj->setTime($end_arr[0], $end_arr[1], '00')->setTimezone(new \DateTimeZone('UTC'));

        // Set and save fields
        $node->get('field_event_time')->value = $start_obj->format('Y-m-d\TH:i:s');
        $node->get('field_event_time')->end_value = $end_obj->format('Y-m-d\TH:i:s');
        $node->save();

        $this->messenger()->addMessage(
          t(
            'Updated the time for session ID: @nid to @start - @end',
            [
              '@nid' => $nid,
              '@start' => $start_obj->setTimezone(new \DateTimeZone('Europe/London'))->format('H:i'),
              '@end' =>$end_obj->setTimezone(new \DateTimeZone('Europe/London'))->format('H:i')
            ]
          )
        );
      } else {
        $this->messenger()->addError(
          t('It appears you don\'t have access to session ID: @nid', ['@nid' => $nid])
        );
      }
    } catch (\Exception $e) {
      $this->logger->error(
        'Error changing time on register @nid in changeTime() <br> <pre>@e<br>%t</pre>',
        [
          '@nid' => $nid,
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      $this->messenger()->addError(
        t('There has been an error whilst trying to up session ID: @nid', ['@nid' => $nid])
      );
    }
  }

  /**
   * @param int $sid
   * @param array $changes
   *
   * @return void
   */
  private function registrationUpdate($sid, $changes = []) {
    if ($sid && !empty($changes)) {

      try {
        $webform_submission = $this->webformSubmissionStorage->load($sid);

        if (isset($changes['status'])) {
          $webform_submission->setElementData('registration_status',  $changes['status']);
          $this->messenger()->addMessage(
            t(
              'Updated the registration status of submission ID: @sid to @registration_status',
              ['@sid' => $sid, '@registration_status' => $changes['status']]
            )
          );
        }
        if (isset($changes['primary_theme'])) {
          $webform_submission->setElementData('primary_theme', $changes['primary_theme']);
          $this->messenger()->addMessage(
            t(
              'Updated the primary theme of submission ID: @sid to @primary_theme',
              ['@sid' => $sid, '@primary_theme' => $changes['primary_theme']]
            )
          );

        }
        if (isset($changes['secondary_theme'])) {
          $webform_submission->setElementData('secondary_theme', $changes['secondary_theme']);
          $this->messenger()->addMessage(
            t(
              'Updated the secondary theme of submission ID: @sid to @secondary_theme',
              ['@sid' => $sid, '@secondary_theme' => $changes['secondary_theme']]
            )
          );
        }
        if (isset($changes['other_themes'])) {
          $webform_submission->setElementData('other_themes', $changes['other_themes']);
          $this->messenger()->addMessage(
            t(
              'Updated the other theme/s of submission ID: @sid to @other_themes',
              ['@sid' => $sid, '@other_themes' => $changes['other_themes']]
            )
          );
        }
        if (isset($changes['student_feedback'])) {
          $webform_submission->setElementData('student_feedback', $changes['student_feedback']);
          $this->messenger()->addMessage(
            t(
              'Updated the student feedback of submission ID: @sid to @student_feedback',
              ['@sid' => $sid, '@student_feedback' => $changes['student_feedback']]
            )
          );
        }
        if (isset($changes['referral'])) {
          $webform_submission->setElementData('referral', $changes['referral']);
          $this->messenger()->addMessage(
            t(
              'Updated the referral of submission ID: @sid to @referral',
              ['@sid' => $sid, '@referral' => $changes['referral']]
            )
          );
        }

        $webform_submission->save();

      } catch (\Exception $e) {
        $this->logger->error(
          'Error with Webform interaction in registrationUpdate() <br> <pre>@e<br>%t</pre>',
          [
            '@e'=> $e->getMessage(),
            '%t' => $e->getTraceAsString()
          ]
        );
        $this->messenger()->addError(
          t(
            'There has been an error whilst trying to up submission ID: @sid',
            ['@sid' => $sid]
          )
        );
      }
    }
  }

  public function emptyTutorials($params = FALSE) {
    $form['show_empties'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row-full', 'px-5'],
      ]
    ];

    $form['show_empties']['btn'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Show available tutorials <span class='badge badge-aso-accent text-dark'>beta</span>",
      '#attributes' => [
        'type' => 'button',
        'data-toggle' => 'collapse',
        'data-target' => '#filterCollapse',
        'aria-expanded' => 'false',
        'aria-controls' => 'filterCollapse',
        'class' => ['btn btn-outline-primary'],
        'id' => 'showAvailable'
      ],
      '#attached' => [
        'library' => [
          'events_register/unbooked_toggle',
        ],
      ],
      '#prefix' => '<div class="">',
      '#suffix' => '</div>'
    ];

    $form['show_empties']['empties_container'] = [
      '#type' => 'container',
      '#title' => t('Available tutorials'),
      '#weight' => 0,
      '#attributes' => [
        'class' => ['collapse'],
        'id' => 'filterCollapse'
      ]
    ];

    $empty_tutorials_header = [
      ['data' => $this->t('Event information'), 'class'=> ['align-middle', 'py-4']],
      ['data' => $this->t('Event date/time'), 'field' => 'event_time.field_event_time_value', 'sort' => 'asc', 'class'=> ['align-middle', 'py-4']],
      ['data' => $this->t('Register a student link'), 'class'=> ['align-middle', 'py-4']],
    ];

    $form['show_empties']['empties_container']['empties_table'] = [
      '#type' => 'table',
      '#header' => $empty_tutorials_header,
      '#empty' => t('There are no empty tutorials.'),
      '#prefix' => "<div class='table-responsive mt-3 p-3 border-bottom border-secondary'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => ['table-sm'],
        'style' => 'font-size: .85rem',
      ]
    ];

    $empty_tutorials = $this->getEmptyTutorialData(1, $params, $empty_tutorials_header);

    if ($empty_tutorials) {
      $i = 0;
      foreach ($empty_tutorials as $data) {
        $start_date = DrupalDateTime::createFromFormat(
          'Y-m-d\TH:i:s', $data->field_event_time_value,
          DateTimeItemInterface::STORAGE_TIMEZONE
        )->setTimezone(new \DateTimeZone('Europe/London'));
        $end_date = DrupalDateTime::createFromFormat(
          'Y-m-d\TH:i:s', $data->field_event_time_end_value,
          DateTimeItemInterface::STORAGE_TIMEZONE
        )->setTimezone(new \DateTimeZone('Europe/London'));


        $form['show_empties']['empties_container']['empties_table'][$i]['title'] = [
          '#type' => 'item',
          '#markup' => t(
            '@link',
            [
              '@link' => Link::fromTextAndURL(
                $data->title,
                Url::fromRoute('entity.node.canonical', ['node' => $data->nid],
                  ['attributes' => ['target' => '_blank']]
                )
              )->toString(),
            ]
          ),
          '#weight' => '0',
        ];

        $form['show_empties']['empties_container']['empties_table'][$i]['time'] = [
          '#type' => 'item',
          '#markup' => $start_date->format('d/m/Y H:i') . ' - ' . $end_date->format('H:i'),
          '#weight' => '0',
        ];

        $form['show_empties']['empties_container']['empties_table'][$i]['register'] = [
          '#type' => 'item',
          '#markup' => t(
            '@link',
            [
              '@link' => Link::fromTextAndURL(
                'register student',
                Url::fromRoute('events_batch_registration.batch_register_people_form',
                  ['node' => $data->nid],
                  ['attributes' => ['target' => '_blank']]
                )
              )->toString(),
            ]
          ),
          '#weight' => '0',
        ];

        $i++;
      }
    }

    $form['show_empties']['empties_container']['available_pager_1'] = [
      'pager' => [
        '#type' => 'pager',
        '#element' => 1,
      ],
    ];

    return $form;
  }

  public function getFilters($params = FALSE) {
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
      '#title' => $this->t('Event type'),
      '#description' => $this->t('Search for registrations by event type.'),
      '#options' => [
        'all' => 'All',
        'workshop' => t('Workshops'),
        'tutorial' => t('Tutorials'),
        'eas' => t('Legacy EAS')
      ],
      '#default_value' => isset($params['type']) ? $params['type'] : 'all',
      '#after_build' => ['events_register_inline_radios'],
      '#attributes' => [
        'class' => ['text-center', 'no-card', 'col-auto']
      ],
    ];

    $form['filter']['filter_container']['q']['row0']['status'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Registration status'),
      '#description' => $this->t('Limit the type of registrations that appear.'),
      '#options' => [
        'active' => t('Attending / Attended / Timetabled by course team'),
        'cancelled' => t('Cancelled'),
        'system_cancellation' => t('System cancelled')
      ],
      '#default_value' => isset($params['status']) ? explode('/', $params['status']) : ['active'],
      '#after_build' => ['events_register_inline_radios'],
      '#attributes' => [
        'class' => ['text-center', 'no-card', 'col-auto']
      ],
    ];

    $form['filter']['filter_container']['q']['row1'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['form-row', 'h-100', 'align-items-center']
      ]
    ];

    $form['filter']['filter_container']['q']['row1']['sdr'] = [
      '#type' => 'date',
      '#title' => 'From date',
      '#description' => $this->t('The start date for a range of events'),
      '#default_value' => isset($params['sdr']) ? $params['sdr'] : '',
      '#wrapper_attributes' => [
        'class' => ['col-auto']
      ],
    ];

    $form['filter']['filter_container']['q']['row1']['edr'] = [
      '#type' => 'date',
      '#title' => 'To date',
      '#description' => $this->t('The end date for a range of events'),
      '#default_value' => isset($params['edr']) ? $params['edr'] : '',
      '#wrapper_attributes' => [
        'class' => ['col-auto']
      ],
    ];

    $form['filter']['filter_container']['q']['row1']['t'] = [
      '#type' => 'textfield',
      '#size' => 30,
      '#maxlength' => 128,
      '#title' => $this->t('Title'),
      '#description' => $this->t('Search the titles of events.'),
      '#default_value' => isset($params['t']) ? $params['t'] : '',
      '#attributes' => [
        'class' => ['']
      ],
      '#wrapper_attributes' => [
        'class' => ['col-auto']
      ],
    ];

    $form['filter']['filter_container']['q']['row1']['nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Event ID'),
      '#description' => $this->t('Search for a specific event ID.'),
      '#default_value' => isset($params['nid']) ? $params['nid'] : '',
      '#min' => 0,
      '#max' => 999999999,
      '#size' => 5,
      '#maxlength' => 5,
      '#attributes' => [
        'class' => ['']
      ],
      '#wrapper_attributes' => [
        'class' => ['col-auto']
      ],
    ];

    if ($this->account->hasPermission('access others registers')) {
      $form['filter']['filter_container']['q']['row1']['other'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('<b>Other\'s register</b>'),
        '#description' => $this->t("View the register for another person."),
        '#target_type' => 'user',
        '#settings' => [
          'match_operator' => 'CONTAINS',
        ],
        '#selection_handler' => 'default:user',
        '#selection_settings' => [
          'include_anonymous' => FALSE,
        ],
        '#default_value' => isset($params['other']) ? $this->entityTypeManager->getStorage('user')
          ->load($params['other']) : '',
        '#wrapper_attributes' => [
          'class' => ['col-auto']
        ],
      ];
    }

    $form['filter']['filter_container']['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['mb-4', 'd-flex', 'justify-content-center']
      ]
    ];

    $form['filter']['filter_container']['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Filter'),
      '#name' => 'filter',
      '#attributes' => [
        'class' => ['mx-1']
      ]
    ];

    $form['filter']['filter_container']['actions']['reset'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Reset'),
      '#submit' => ['::resetForm'],
      '#attributes' => [
        'class' => ['mx-1']
      ]
    ];

//    $form['filter']['filter_container']['q']['row2'] = [
//      '#type' => 'container',
//      '#attributes' => [
//        'class' => ['form-row', 'h-100', 'align-items-center',]
//      ]
//    ];
//
//    $today = new DateTime('now');
//    $form['filter']['filter_container']['q']['row0']['sd'] = [
//      '#type' => 'date',
//      '#title' => $this->t('<b>Select a day to view</b>'),
//      '#description' => $this->t("Select a day to view registration for that day."),
//      '#default_value' => isset($params['sd']) ? $params['sd'] : $today->format('Y-m-d'),
//      '#wrapper_attributes' => [
//        'class' => ['col-auto']
//      ],
//    ];
//
//
//    $form['filter']['filter_container']['q']['row1']['sits'] = [
//      '#type' => 'textfield',
//      '#size' => 15,
//      '#maxlength' => 15,
//      '#title' => $this->t('Student ID'),
//      '#description' => $this->t('Search for a student by ID.'),
//      '#default_value' => isset($params['sits']) ? $params['sits'] : '',
//      '#attributes' => [
//        'class' => ['']
//      ],
//      '#wrapper_attributes' => [
//        'class' => ['col']
//      ],
//    ];

    return $form;
  }

  /**
   * Get the register data.
   *
   * @param int $element
   *   The id of the pager.
   * @param array $params
   *   The user selected filter options.
   * @param array $header
   *   The table header.
   *
   * @return object|false
   *   Returns the register data or false if error.
   */
  public function getReportData($element, array $params, array $header) {
    try {
      // Get records from the DB.
      $query = $this->database->select('webform_submission', 'ws');
      $query->join('node_field_data', 'nfd', 'ws.entity_id = nfd.nid');
      $query->join('node__field_event_time', 'nfet', 'nfd.nid = nfet.entity_id');
      $query->leftJoin('node__field_event_tutor', 'nfetu', 'nfd.nid = nfetu.entity_id');
      $query->leftJoin('node__field_event_additional_access', 'aa', 'nfd.nid = aa.entity_id');
      $query->leftJoin('node__field_event_room', 'room', 'nfd.nid = room.entity_id');
      $query->leftJoin('node__field_collaborate_session_ref', 'collaborate_ref', 'nfd.nid = collaborate_ref.entity_id');
      $query->join('webform_submission_data', 'wsd', 'wsd.sid = ws.sid');
      $query->join(
        'webform_submission_data',
        'wsd_status',
        'wsd_status.sid = ws.sid AND wsd_status.name = \'registration_status\''
      );
      $query->fields('ws', ['sid', 'webform_id']);
      $query->fields('nfd', ['nid', 'title', 'uid', 'type']);
      $query->fields(
        'nfet',
        ['field_event_time_value', 'field_event_time_end_value']
      );
      $query->fields('nfetu', ['field_event_tutor_target_id']);
      $query->addField('room', 'field_event_room_target_id', 'room');
      $query->addField('wsd_status', 'value', 'registration_status');
      $query->addField('collaborate_ref', 'field_collaborate_session_ref_target_id', 'collaborate_id');
      $ws_type = $query->orConditionGroup()
        ->condition('ws.webform_id', 'tutorial', '=')
        ->condition('ws.webform_id', 'workshop', '=');
      $query->condition($ws_type);
      // @TODO: Refactor access control and test integrity of checks
      if (!$this->account->hasPermission('access others registers')) {
        $access = $query->orConditionGroup()
          ->condition('nfetu.field_event_tutor_target_id', $this->account->id())
          ->condition('aa.field_event_additional_access_target_id', $this->account->id(), 'IN')
          ->condition('nfd.uid', $this->account->id());
        $query->condition($access);
      }
      if ($this->account->hasPermission('access others registers')) {
        if (!isset($params['other']) && !isset($params["nid"])) {
          $access = $query->orConditionGroup()
            ->condition('nfetu.field_event_tutor_target_id', $this->account->id())
            ->condition('aa.field_event_additional_access_target_id', $this->account->id())
            ->condition('nfd.uid', $this->account->id());
          $query->condition($access);
        }
        if (isset($params['other'])) {
          $access = $query->orConditionGroup()
            ->condition('nfetu.field_event_tutor_target_id', $params['other'])
            ->condition('aa.field_event_additional_access_target_id', $params['other'])
            ->condition('nfd.uid', $params['other']);
          $query->condition($access);
        }
      }
      if (isset($params['type']) && $params['type'] != 'all') {
        $query->condition('nfd.type', $params['type'], '=');
      }
      if (isset($params['nid'])) {
        $query->condition('nid', $params['nid'], '=');
      }
      if (isset($params['sid'])) {
        $query->condition('ws.sid', $params['sid'], '=');
      }
      if (isset($params['t'])) {
        $query->condition('title', '%' . $params['t'] . '%', 'LIKE');
      }
      if (isset($params['sdr'])) {
        $start = DrupalDateTime::createFromFormat(
          'Y-m-d', $params['sdr'],
          'Europe/London'
        );
        $start->setTime('00', '00', '00');
        $query->condition('nfet.field_event_time_value', $start->format('Y-m-d\TH:i:s'), '>=');
      }
      if (isset($params['edr'])) {
        $end = DrupalDateTime::createFromFormat(
          'Y-m-d', $params['edr'],
          'Europe/London'
        );
        $end->setTime('23', '59', '59');
        $query->condition('nfet.field_event_time_value', $end->format('Y-m-d\TH:i:s'), '<=');
      }
      if (!isset($params['sdr']) && !isset($params['nid'])) {
        $start = new \DateTime('now');
        $start->setTime(00, 00, 00);
        $end = new \DateTime('now + 5 days');
        $end->setTime(23, 59, 00);
        $query->condition('nfet.field_event_time_value', $start->format('Y-m-d\TH:i:s'), '>=');
        $query->condition('nfet.field_event_time_value', $end->format('Y-m-d\TH:i:s'), '<=');
      }
      $active = ['attending', 'attended', 'timetabled_by_course_team'];
      if (isset($params['status'])) {
        $status = explode('/', $params['status']);
        if (in_array('active', $status)) {
          $status = array_merge($status, $active);
        }
        $query->condition('wsd_status.value', $status, 'IN');
      }
      else {
        $query->condition('wsd_status.value', $active, 'IN');
      }
      $query->addExpression("GROUP_CONCAT(DISTINCT aa.field_event_additional_access_target_id SEPARATOR  '/')", 'field_event_additional_access_target_id');
      $query->addExpression("GROUP_CONCAT(DISTINCT CONCAT_WS('', wsd.name, ':', wsd.value) SEPARATOR '/')", 'wsd_data');
      $query->groupBy('ws.sid');
      $query->groupBy('ws.webform_id');
      $query->groupBy('nfd.nid');
      $query->groupBy('nfd.title');
      $query->groupBy('nfd.uid');
      $query->groupBy('nfd.type');
      $query->groupBy('nfet.field_event_time_value');
      $query->groupBy('nfet.field_event_time_end_value');
      $query->groupBy('nfetu.field_event_tutor_target_id');
      $query->groupBy('room.field_event_room_target_id');
      $query->groupBy('wsd_status.value');
      $query->groupBy('collaborate_ref.field_collaborate_session_ref_target_id');
      if (isset($params['sits'])) {
        $uid = $this->getStudentUID($params['sits']);
        if (!is_null($uid)) {
          $query->having("wsd_data LIKE '%student_id:$uid%'");
        }
      }
      $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);
      $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->element($element)->limit(100);
      return $pager->execute()->fetchAll();
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error with DB query in getReportData()<br>@e<br>%t',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return FALSE;
    }
  }

  /**
   * Get the empty tutorial data.
   *
   * Gets information about tutorials which haven't been booked.
   *
   * @param int $element
   *   The id of the pager.
   * @param array $params
   *   The user selected filter options.
   * @param array $header
   *   The table header.
   *
   * @return object|false
   *   Returns the register data or false if error.
   */
  public function getEmptyTutorialData($element, array $params, array $header) {
    try {
      $query = $this->database->select('node__field_event_registration', 'event_registration');
      $query->join('node_field_data', 'nfd', 'nfd.nid = event_registration.entity_id');
      $query->join('node__field_event_time', 'event_time', 'event_time.entity_id = nfd.nid');
      $query->leftJoin('node__field_event_tutor', 'event_tutor', 'nfd.nid = event_tutor.entity_id');
      $query->leftJoin('node__field_event_additional_access', 'event_additional', 'nfd.nid = event_additional.entity_id');
      $query->fields('nfd', ['nid', 'title', 'uid', 'type']);
      $query->fields(
        'event_time',
        ['field_event_time_value', 'field_event_time_end_value']
      );

      if (isset($params['sdr'])) {
        $start = DrupalDateTime::createFromFormat(
          'Y-m-d', $params['sdr'],
          'Europe/London'
        );
        $start->setTime('00', '00', '00');
        $query->condition('event_time.field_event_time_value', $start->format('Y-m-d\TH:i:s'), '>=');
      }

      if (isset($params['edr'])) {
        $end = DrupalDateTime::createFromFormat(
          'Y-m-d', $params['edr'],
          'Europe/London'
        );
        $end->setTime('23', '59', '59');
        $query->condition('event_time.field_event_time_value', $end->format('Y-m-d\TH:i:s'), '<=');
      }

      if (!isset($params['sdr'])) {
        $start = new \DateTime('now');
        $start->setTime(00, 00, 00);
        $end = new \DateTime('now');
        $end->setTime(23, 59, 00);
        $query->condition('event_time.field_event_time_value', $start->format('Y-m-d\TH:i:s'), '>=');
        $query->condition('event_time.field_event_time_value', $end->format('Y-m-d\TH:i:s'), '<=');
      }

      if (!isset($params['other']) && !isset($params["nid"])) {
        $access = $query->orConditionGroup()
          ->condition('event_tutor.field_event_tutor_target_id', $this->account->id())
          ->condition('event_additional.field_event_additional_access_target_id', $this->account->id())
          ->condition('nfd.uid', $this->account->id());
        $query->condition($access);
      }

      if (isset($params['other'])) {
        $access = $query->orConditionGroup()
          ->condition('event_tutor.field_event_tutor_target_id', $params['other'])
          ->condition('event_additional.field_event_additional_access_target_id', $params['other'])
          ->condition('nfd.uid', $params['other']);
        $query->condition($access);
      }

      $query->condition('event_registration.bundle', 'tutorial', '=');
      $query->condition(
        'event_registration.field_event_registration_status',
        ['open', 'scheduled'],
        'IN'
      );
      $query->groupBy('nfd.nid');
      $query->groupBy('nfd.title');
      $query->groupBy('nfd.uid');
      $query->groupBy('nfd.type');
      $query->groupBy('event_time.field_event_time_value');
      $query->groupBy('event_time.field_event_time_end_value');

      $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);
      $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->element($element)->limit(25);
      return $pager->execute()->fetchAll();

    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error with DB query in getEmptyTutorialData()<br>@e<br>%t',
        [
          '@e' => $e->getMessage(),
          '%t' => $e->getTraceAsString()
        ]
      );
      return FALSE;
    }
  }

}
