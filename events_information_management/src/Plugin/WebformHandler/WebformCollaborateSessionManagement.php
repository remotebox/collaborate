<?php

namespace Drupal\events_information_management\Plugin\WebformHandler;

use Drupal\collaborate_integration\CollaborateService;
use Drupal\collaborate_integration\Entity\CollaborateSession;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform Collaborate session management.
 *
 * @WebformHandler(
 *   id = "collaborate_session_management",
 *   label = @Translation("Manage Collaborate session registration."),
 *   category = @Translation("ual"),
 *   description = @Translation("Manages resitrations for Collaborate
 *   sessions."), cardinality =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission =
 *   \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class WebformCollaborateSessionManagement extends WebformHandlerBase {

  /**
   * The configuration object factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Messenger\MessengerInterface definition.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The Collaborate API service.
   *
   * @var \Drupal\collaborate_integration\CollaborateService
   */
  protected $collaborate;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * WebformCollaborateSessionManagement constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\webform\WebformSubmissionConditionsValidatorInterface $conditions_validator
   *   The webform submission conditions (#states) validator.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\collaborate_integration\CollaborateService $collaborate
   *   The Collaborate API service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entityTypeManager,
    WebformSubmissionConditionsValidatorInterface $conditions_validator,
    MessengerInterface $messenger,
    CollaborateService $collaborate,
    LoggerInterface $logger
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $logger_factory,
      $config_factory,
      $entityTypeManager,
      $conditions_validator,
      $messenger,
      $collaborate
    );
    $this->entityTypeManager = $entityTypeManager;
    $this->conditionsValidator = $conditions_validator;
    $this->messenger = $messenger;
    $this->collaborate = $collaborate;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('messenger'),
      $container->get('collaborate_integration.collaborate'),
      $container->get('logger.factory')->get('events_information_management')
    );
  }

  /**
   * Triggers a process on saving of a webform.
   *
   * If the user is attending then a Collaborate registration will be made.
   * If the user is cancelling then the Collaborate registration will be deleted.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Interface to a webform submission entity.
   * @param bool $update
   *   Whether the submission is an update or not.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\GuzzleHttp\Exception\GuzzleException
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $registration_status = $webform_submission->getElementData('registration_status');

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->getSessionNode($webform_submission);
    $is_node = $node instanceof Node;

    /** @var \Drupal\collaborate_integration\Entity\CollaborateSession $collaborate_session */
    $collaborate_session = $this->getCollaborateSession($node);
    if (!$collaborate_session) {
      // No Collaborate session set so don't do anything.
      return;
    }
    $is_collaborate_session = $collaborate_session instanceof CollaborateSession;

    if (!$is_node || !$is_collaborate_session) {
      $this->logger->error(
        "Could not proceed with registration cancellation because the conditions weren't met:<br>is_node = @is_node, is_collaborate_session = @is_collaborate_session.<br>%webform_submission",
        [
          '@is_node' => $is_node,
          '@is_collaborate_session' => $is_collaborate_session,
          '%webform_submission' => $webform_submission->getData()->toString(),
        ]
      );
      if ($registration_status === 'attending') {
        $this->messenger->addError(
          t("Unfortunately we could not create a Collaborate enrolment for you.  Please contact @email."),
          [
            '@email' => "<a href='mailto:academicsupportonline@arts.ac.uk'>academicsupportonline@arts.ac.uk</a>",
          ]
        );
      }
      return;
    }

    if ($registration_status === 'attending') {
      $this->processAttending($webform_submission, $collaborate_session);
    }

    if ($registration_status === 'cancelled' || $registration_status === 'system_cancellation') {
      $this->processCancellation($webform_submission, $collaborate_session);
    }
  }

  /**
   * Processes the Collaborate registration when registering for a session.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Interface to a webform submission entity.
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   The Collaborate session entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\GuzzleHttp\Exception\GuzzleException
   */
  private function processAttending(WebformSubmissionInterface $webform_submission, CollaborateSession $entity) {
    /** @var \Drupal\user\Entity\User $registrant_account */
    $registrant_account = $this->getRegistrantAccount($webform_submission);

    if (!$registrant_account instanceof User) {
      $this->logger->error(
        "Could not proceed with registration because a user entity could not be loaded.<br>%webform_submission",
        ['%webform_submission' => $webform_submission->getData()->toString()]
      );
      $this->messenger->addError(
        t("Unfortunately we could not create a Collaborate enrolment for you.  Please contact @email."),
        [
          '@email' => "<a href='mailto:academicsupportonline@arts.ac.uk'>academicsupportonline@arts.ac.uk</a>",
        ]
      );
      return;
    }

    // $enrolment returns stdClass for success
    // what for failure?
    $enrolment = $this->collaborate->enrolUser(
      $entity,
      // $collaborate_session->get('participantRole')->value,
      'presenter',
      $registrant_account
    );

    if (!$enrolment) {
      $this->messenger->addError(
        t("Unfortunately we could not create a Collaborate enrolment for you.  Please contact @email."),
        [
          '@email' => "<a href='mailto:academicsupportonline@arts.ac.uk'>academicsupportonline@arts.ac.uk</a>",
        ]
      );
    }
  }

  /**
   * Processes the Collaborate registration when cancelling registration.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Interface to a webform submission entity.
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   The Collaborate session entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException|\GuzzleHttp\Exception\GuzzleException
   */
  private function processCancellation(WebformSubmissionInterface $webform_submission, CollaborateSession $entity) {
    /** @var \Drupal\user\Entity\User $registrant_account */
    $registrant_account = $this->getRegistrantAccount($webform_submission);
    if (!$registrant_account instanceof User) {
      $this->logger->error(
        "Could not proceed with registration cancellation because a user entity could not be loaded.<br>%webform_submission",
        ['%webform_submission' => $webform_submission->getData()->toString()]
      );
      return;
    }

    $delete = $this->collaborate->deleteEnrolment(
      $entity,
      $registrant_account
    );

    if (!empty($delete)) {
      $this->logger->error(
        "Could not delete session.<br>%webform_submission",
        [
          '%webform_submission' => $webform_submission->getData()->toString(),
        ]
      );
    }
  }

  /**
   * Gets the Drupal user entity account from uid in the webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Interface to a webform submission entity.
   *
   * @return \Drupal\user\Entity\User|null
   *   Returns a Drupal user account or null.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getRegistrantAccount(WebformSubmissionInterface $webform_submission) {
    $registrant_id = $webform_submission->getElementData('student_id');
    return $this->entityTypeManager
      ->getStorage('user')
      ->load($registrant_id);
  }

  /**
   * Gets the node entity from the nid in the webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   Interface to a webform submission entity.
   *
   * @return \Drupal\node\Entity\Node|null
   *   Returns a node entity or null.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getSessionNode(WebformSubmissionInterface $webform_submission) {
    $nid = $webform_submission->getElementData('node_id');
    // @TODO: monitor webform bug.  Programmatically created submission return
    // token name, not the value.
    if (!is_numeric($nid)) {
      $ws_array = $webform_submission->toArray();
      $nid = $ws_array["entity_id"][0]["value"];
    }
    return $this->entityTypeManager->getStorage(
      'node'
    )->load($nid);
  }

  /**
   * Gets the Collaborate session entity.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   *
   * @return false|\Drupal\collaborate_integration\Entity\CollaborateSession|null
   *   Returns a Collaborate session entity, FALSE if no entity reference, or
   *   null on entity load fail.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getCollaborateSession(Node $node) {
    $collaborate_session_ref = $node->hasField('field_collaborate_session_ref') ? $node->get('field_collaborate_session_ref')->target_id : FALSE;
    if (!$collaborate_session_ref) {
      return FALSE;
    }
    else {
      return $this->entityTypeManager->getStorage(
        'collaborate_session'
      )->load($collaborate_session_ref);
    }
  }

  /**
   * CalculateDependencies method.
   */
  public function calculateDependencies() {
    // TODO: Implement calculateDependencies() method.
  }

}
