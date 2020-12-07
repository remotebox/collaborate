<?php

namespace Drupal\collaborate_integration\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformException;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a callback for updating registration status on Collaborate join click.
 */
class ChangeStatusController implements ContainerInjectionInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a HTTP basic authentication provider object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger interface.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory')->get('collaborate_integration')
    );
  }

  /**
   * Update the registration status to attended for Collaborate sessions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request instance.
   *
   * @return array
   *   Returns empty array or render array if the page is visited by a user.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function updateStatus(Request $request) {
    $sid = $request->get('sid');
    $uid = $request->get('uid');
    if (is_numeric($sid) && is_numeric($uid)) {
      try {
        /** @var \Drupal\webform\WebformSubmissionInterface $webform_submission */
        $webform_submission = $this->entityTypeManager
          ->getStorage('webform_submission')->load($sid);
        if (!$webform_submission instanceof WebformSubmissionInterface) {
          return [];
        }
        $webform_data = $webform_submission->getData();
        if (isset($webform_data['registration_status']) && $webform_data['registration_status'] == 'attending') {
          if (isset($webform_data['student_id']) && $uid == $webform_data['student_id']) {
            $webform_submission->setElementData('registration_status', 'attended')
              ->save();
            return [];
          }
        }
      }
      catch (WebformException $e) {
        $this->logger->error(
          'Error changing registration: @sid, for user: @uid<br>%message',
          [
            '@sid' => $sid,
            '@uid' => $uid,
            '%message' => $e->getMessage(),
          ]
        );
        return [];
      }
    }
    return [
      '#type' => 'markup',
      '#markup' => 'Looks like you have stumbled upon this page.  Why not head to <a href="/explore">explore our content</a> instead.',
    ];
  }

}
