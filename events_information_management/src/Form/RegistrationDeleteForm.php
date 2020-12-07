<?php

namespace Drupal\events_information_management\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class RegistrationDeleteForm.
 */
class RegistrationDeleteForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_type_manager;

  /**
   * The webform storage.
   *
   * @var \Drupal\webform\WebformEntityStorageInterface
   */
  protected $webformStorage;

  /**
   * The webform submission storage.
   *
   * @var \Drupal\webform\WebformSubmissionStorageInterface
   */
  protected $webformSubmissionStorage;

  /**
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructs a new BatchRegisterPeopleForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(
    Connection $database,
    LoggerInterface $logger,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager,
    PagerManagerInterface $pagerManager
  ) {
    $this->database = $database;
    $this->logger = $logger;
    $this->messenger = $messenger;
    $this->entity_type_manager = $entity_type_manager;
    $this->webformStorage = $this->entity_type_manager->getStorage('webform');
    $this->webformSubmissionStorage = $this->entity_type_manager->getStorage('webform_submission');
    $this->pagerManager = $pagerManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('logger.factory')->get('events_information_management'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('pager.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'registration_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $duplicate_count = $this->duplicateCount();

    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        "There are currently @dup_count suspected duplications.",
        [
          '@dup_count' => $duplicate_count,
        ]
      ),
    ];

    $header = [
      'node_id' => ['data' => t('Events'), 'class' => ['align-middle', 'py-4']],
    ];

    $form['registration_list'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t('No duplicate registrations found.'),
      '#prefix' => "<div class='table-responsive mt-3 row-full p-5'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => ['table-sm'],
        'style' => 'font-size: .85rem',
      ],
    ];

    $duplicates = $this->getDuplicateNodeIDs($duplicate_count);
    if ($duplicates) {
      foreach ($duplicates as $duplicate) {
        usort($duplicate['registration_data'], function ($a, $b) {
          return $a['student_uid'] <=> $b['student_uid'];
        });

        $form['registration_list']['node-' . $duplicate['node_id']]['node'] = [
          '#type' => 'item',
          '#markup' => Link::fromTextAndURL(
            $duplicate['node_title'] . ' (' . $duplicate['node_id'] . ')',
            Url::fromRoute('entity.node.canonical', ['node' => $duplicate['node_id']],
              ['attributes' => ['target' => '_blank']]
            )
          )->toString(),
          '#value' => $duplicate['node_id'],
          '#weight' => '0',
          '#wrapper_attributes' => [
            'class' => ['align-middle', 'my-2', 'p-0'],
            'colspan' => 6,
          ],
        ];
        $prev_uid = NULL;
        $prev_status = NULL;
        $first = TRUE;
        foreach ($duplicate['registration_data'] as $registration_data) {
          if ($registration_data['student_uid'] == $prev_uid && $registration_data['status'] == $prev_status) {
            $check = TRUE;
          }
          else {
            $check = FALSE;
          }
          $prev_uid = $registration_data['student_uid'];
          $prev_status = $registration_data['status'];
          $form['registration_list'][$registration_data['sid']]['check'] = [
            '#type' => 'checkbox',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'p-0', 'border-top-0'],
            ],
            '#attributes' => [
              'class' => ['m-0'],
              'style' => 'position:relative;',
            ],
            '#default_value' => $check ? TRUE : FALSE,
            '#prefix' => $first ? '<b>Select:</b>' : '',
          ];
          $form['registration_list'][$registration_data['sid']]['sid'] = [
            '#type' => 'item',
            '#markup' => $registration_data['sid'],
            '#value' => $registration_data['sid'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>SID:</b>' : '',
          ];
          $form['registration_list'][$registration_data['sid']]['uid'] = [
            '#type' => 'item',
            '#markup' => $registration_data['student_uid'],
            '#value' => $registration_data['student_uid'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>Drupal UID:</b>' : '',
          ];
          $user_extra_info = $this->getUserExtraInfo($registration_data['student_uid']);
          $form['registration_list'][$registration_data['sid']]['sits_id'] = [
            '#type' => 'item',
            '#markup' => $user_extra_info['sits_id'],
            '#value' => $user_extra_info['sits_id'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>SITS ID:</b>' : '',
          ];
          $form['registration_list'][$registration_data['sid']]['name'] = [
            '#type' => 'item',
            '#markup' => $user_extra_info['name'],
            '#value' => $user_extra_info['name'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>Name:</b>' : '',
          ];
          $form['registration_list'][$registration_data['sid']]['status'] = [
            '#type' => 'item',
            '#markup' => $registration_data['status'],
            '#value' => $registration_data['status'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>Registration status:</b>' : '',
          ];
          $created = DrupalDateTime::createFromTimestamp($registration_data['created'], 'UTC');
          $form['registration_list'][$registration_data['sid']]['created'] = [
            '#type' => 'item',
            '#markup' => $created->setTimezone(new \DateTimeZone('Europe/London'))
              ->format('d/m/Y - H:i:s'),
            '#value' => $registration_data['created'],
            '#weight' => '0',
            '#wrapper_attributes' => [
              'class' => ['align-middle', 'm-0', 'border-top-0'],
            ],
            '#prefix' => $first ? '<b>Creation date:</b>' : '',
          ];
          $first = FALSE;
        }
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['mb-4', 'd-flex', 'justify-content-center'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#default_value' => $this->t('Delete'),
      '#name' => 'register',
    ];

    $form['pager'] = ['#type' => 'pager'];

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
    $deleted = 0;
    $not_deleted = 0;
    $list = $form_state->getValue('registration_list');
    $registration_list = array_filter(
      $list,
      function ($element) {
        return (isset($element['check']) && $element['check'] == 1);
      }
    );
    foreach ($registration_list as $key => $value) {
      $webform_submission = $this->webformSubmissionStorage->load($key);
      if (!empty($webform_submission)) {
        try {
          $webform_submission->delete();
          $delete = TRUE;
        } catch (EntityStorageException $e) {
          $this->logger->error('<pre>%e<br>%t</pre>',
            [
              '%e' => $e->getMessage(),
              '%t' => $e->getTraceAsString(),
            ]
          );
          $delete = FALSE;
        }
        if ($delete) {
          $deleted++;
        }
        else {
          $this->messenger()->addError(
            $this->t("Could not delete submission ID @id", ['@id' => $key])
          );
          $not_deleted++;
        }
      }
    }
    $this->messenger()->addMessage(
      $this->t(
        "Deleted @deleted submissions with @not_deleted errors.",
        ['@deleted' => $deleted, '@not_deleted' => $not_deleted]
      )
    );
  }

  private function duplicateCount() {
    //get a rough list of duplicates
    try {
      $duplicates = $this->database->query(
        "select wsd.value as uid,
                ws.entity_id,
                count(*) as duplicates
              from {webform_submission} ws
               left join {webform_submission_data} wsd on ws.sid = wsd.sid AND wsd.name = :wsd_name
                where ws.webform_id = :webform_id
                 group by wsd.value, ws.entity_id
                  having duplicates > :duplicate_level
                   order by created asc",
        [
          ':wsd_name' => 'student_id',
          ':webform_id' => 'tutorial',
          ':duplicate_level' => 1,
        ]
      )->fetchAll();
    } catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('<pre>%e<br>%t</pre>',
        [
          '%e' => $e->getMessage(),
          '%t' => $e->getTraceAsString(),
        ]
      );
      $duplicates = [];
    }
    return count($duplicates);
  }

  private function getDuplicateNodeIDs($duplicates) {
    $num_per_page = 50;
    $pager = $this->pagerManager->createPager($duplicates, $num_per_page);
    $page = $pager->getCurrentPage();
    $offset = $num_per_page * $page;

    try {
      $duplicates_data = $this->database->query(
        "SELECT wsd.value AS uid,
              ws.entity_id,
              count(*) AS duplicates
            FROM {webform_submission} ws
             LEFT JOIN {webform_submission_data} wsd ON ws.sid = wsd.sid AND wsd.name = :wsd_name
              WHERE ws.webform_id = :webform_id
               GROUP BY wsd.value, ws.entity_id
                HAVING duplicates > :duplicate_level
                 LIMIT " . $offset . "," . $num_per_page . "",
        [
          ':wsd_name' => 'student_id',
          ':webform_id' => 'tutorial',
          ':duplicate_level' => 1,
        ]

      )->fetchAll();
    } catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('<pre>%e<br>%t</pre>',
        [
          '%e' => $e->getMessage(),
          '%t' => $e->getTraceAsString(),
        ]
      );
      $duplicates_data = FALSE;
    }

    if ($duplicates_data) {
      $registration_data = [];
      foreach ($duplicates_data as $duplicate_data) {
        $ws_submission_query = $this->webformSubmissionStorage->getQuery()
          ->condition('entity_id', $duplicate_data->entity_id)
          ->condition('webform_id', 'tutorial', '=');
        $ws_submission_ids = $ws_submission_query->execute();
        $ws_submissions = $this->webformSubmissionStorage->loadMultiple($ws_submission_ids);
        $submission_data = [];
        foreach ($ws_submissions as $ws_submission) {
          $submission_data[$ws_submission->id()] = [
            'sid' => $ws_submission->id(),
            'student_uid' => $ws_submission->getElementData('student_id'),
            'status' => $ws_submission->getElementData('registration_status'),
            'created' => $ws_submission->getCreatedTime(),
          ];
        }

        $registration_data[$duplicate_data->entity_id] = [
          'node_id' => $duplicate_data->entity_id,
          'node_title' => $this->getNodeTitle($duplicate_data->entity_id),
          'registration_data' => $submission_data,
        ];
      }
      return $registration_data;
    }
    return FALSE;
  }

  public function getNodeTitle($nid) {
    $title = 'n/a';
    try {
      $title = $this->database->query(
        "select title from {node_field_data} where nid = :nid", [':nid' => $nid]
      )->fetchField();
    } catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('<pre>%e<br>%t</pre>',
        [
          '%e' => $e->getMessage(),
          '%t' => $e->getTraceAsString(),
        ]
      );
    }
    return $title;
  }

  public function getUserExtraInfo($uid) {
    $user_info = [
      'sits_id' => 'n/a',
      'name' => 'n/a',
    ];
    try {
      $user_info = $this->database->query(
        "SELECT field_prof_sits_id_value AS 'sits_id', CONCAT(field_prof_first_name_value,' ', field_prof_last_name_value) AS 'name'
                FROM {profile}
                JOIN {profile__field_prof_sits_id} ON profile.profile_id = profile__field_prof_sits_id.entity_id
                JOIN {profile__field_prof_first_name} ON profile.profile_id = profile__field_prof_first_name.entity_id
                JOIN {profile__field_prof_last_name} ON profile.profile_id = profile__field_prof_last_name.entity_id
                WHERE profile.uid = :uid",
        [':uid' => $uid]
      )->fetchAssoc();
    } catch (DatabaseExceptionWrapper $e) {
      $this->logger->error('<pre>%e<br>%t</pre>',
        [
          '%e' => $e->getMessage(),
          '%t' => $e->getTraceAsString(),
        ]
      );
    }
    return $user_info;
  }

}
