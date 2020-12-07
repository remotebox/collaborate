<?php

namespace Drupal\student_event_views\Plugin\Block;

use Drupal\collaborate_integration\CollaborateService;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'UpcomingRegistrationsBlock' block.
 *
 * @Block(
 *  id = "upcoming_registrations_block",
 *  admin_label = @Translation("Upcoming registrations block"),
 * )
 */
class UpcomingRegistrationsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Class Resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

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
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ControllerBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\collaborate_integration\CollaborateService $collaborate
   *   The Collaborate API service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager interface.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClassResolverInterface $class_resolver,
    AccountInterface $account,
    CollaborateService $collaborate,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->classResolver = $class_resolver;
    $this->account = $account;
    $this->collaborate = $collaborate;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('class_resolver'),
      $container->get('current_user'),
      $container->get('collaborate_integration.collaborate'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $controller = $this->classResolver->getInstanceFromDefinition(
      '\Drupal\student_event_views\Controller\RegistrationManagementController'
    );

    $header = [
      ['data' => t('Date/time'), 'class' => ['align-middle']],
      ['data' => t('Session'), 'class' => ['align-middle']],
      ['data' => t('Location'), 'class' => ['align-middle']],
      ['data' => t('Room'), 'class' => ['align-middle']],
      ['data' => t('Cancel?'), 'class' => ['align-middle']],
      ['data' => t('&nbsp;')],
    ];

    $build['registrations'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => t(
        "<p class='text-align-center'>You currently have no upcoming sessions. Please check out our <a href='/face-to-face-support'>face to face support</a>.</p>"
      ),
      '#prefix' => "<div class='table-responsive row-full px-5 py-3'>",
      '#suffix' => "</div>",
      '#attributes' => [
        'class' => [''],
      ],
      '#attached' => [
        'library' => ['collaborate_integration/change_status'],
      ],
    ];

    $registrations = $controller->getRegistrations([], $header, TRUE);
    if (!$registrations) {
      return $build;
    }

    $nodes = $controller->loadNodes($registrations);
    if (!$nodes) {
      return $build;
    }

    foreach ($registrations as $registration) {
      $now = new DrupalDateTime('now');
      $start = $controller->buildDate($registration->field_event_time_value);
      $end = $controller->buildDate($registration->field_event_time_end_value);

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
          $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> " . $start->format('M Y') . "</div>";
        }
        if ($hide_time == 1) {
          $date_markup = "<div class='mr-3 d-inline-block'><span class='far fa-calendar-alt' aria-hidden='true'></span> " . $start->format('d M Y') . "</div>";
        }
      }

      $build['registrations'][$registration->sid]['date_time'] = [
        '#type' => 'item',
        '#markup' => $date_markup,
      ];

      $event_link = Link::fromTextAndURL(
        $registration->title,
        Url::fromRoute('entity.node.canonical',
          ['node' => $registration->nid]
        )
      )->toString();

      $build['registrations'][$registration->sid]['title'] = [
        '#type' => 'item',
        '#markup' => $event_link,
      ];

      $taxonomy = $controller->loadTaxonomyTerms($nodes[$registration->nid]);

      $build['registrations'][$registration->sid]['building_location'] = [
        '#type' => 'item',
        '#markup' => t('@building_location',
          [
            '@building_location' => isset($taxonomy['buildings']) ? $taxonomy['buildings'] : 'Not available',
          ]
        ),
      ];

      $build['registrations'][$registration->sid]['room_location'] = [
        '#type' => 'item',
        '#markup' => t('@room_location',
          [
            '@room_location' => isset($taxonomy['rooms']) ? $taxonomy['rooms'] : 'Not available',
          ]
        ),
      ];

      if ($start > $now && $registration->registration_status == 'attending') {
        $build['registrations'][$registration->sid]['cancel'] = [
          '#type' => 'item',
          '#markup' => Link::fromTextAndURL(
            'Cancel',
            Url::fromUri('base:/event/cancel/' . $registration->sid)
          )->toString(),
        ];
      }
      else {
        $build['registrations'][$registration->sid]['cancel'] = [
          '#type' => 'item',
          '#markup' => t(''),
        ];
      }

      if ($nodes[$registration->nid]->hasField('field_collaborate_session_ref') && !empty($nodes[$registration->nid]->get('field_collaborate_session_ref')->target_id)) {
        $collaborate_session = $this->entityTypeManager
          ->getStorage('collaborate_session')
          ->load($nodes[$registration->nid]->get('field_collaborate_session_ref')->target_id);

        $enrolment = $this->collaborate->getEnrolment(
            $collaborate_session,
            $this->entityTypeManager->getStorage('user')
              ->load($this->account->id())
          );
        if (isset($enrolment->permanentUrl)) {
          if ($now <= $end && $now >= $start->modify('- 15 mins')) {
            $build['registrations'][$registration->sid]['join_link'] = [
              '#type' => 'item',
              '#markup' => Link::fromTextAndURL(
                'Join session',
                Url::fromUri(
                  $enrolment->permanentUrl,
                  [
                    'attributes' => [
                      'class' => ['collaborate-launch'],
                      'data-sid' => $registration->sid,
                      'data-status' => $registration->registration_status,
                    ],
                  ]
                )
              )->toString(),
            ];
          }
          else {
            $diff = $now->diff($start);
            if ($diff->h == 0) {
              $timeoutput = $diff->i . ' minutes' . $diff->s . ' seconds';
            }
            else {
              $timeoutput = $diff->h . ' hours ' . $diff->i . ' minutes';
            }
            $build['registrations'][$registration->sid]['join_link'] = [
              '#type' => 'item',
              '#markup' => $this->t('Link will open in @time.', ['@time' => $timeoutput]),
            ];
          }
        }
      }
      else {
        $build['registrations'][$registration->sid]['join_link'] = [
          '#type' => 'item',
          '#markup' => '&nbsp;',
        ];
      }
    }
    return $build;
  }

}
