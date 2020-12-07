<?php

namespace Drupal\collaborate_integration;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the Collaborate session entity.
 *
 * @see \Drupal\collaborate_integration\Entity\CollaborateSession.
 */
class CollaborateSessionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\collaborate_integration\Entity\CollaborateSessionInterface $entity */
    switch ($operation) {
      case 'view':
        if (!$entity->isPublished()) {
          return AccessResult::allowedIfHasPermission($account, 'view unpublished collaborate session entities');
        }
        return AccessResult::allowedIfHasPermission($account, 'view published collaborate session entities');

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit collaborate session entities');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete collaborate session entities');
    }

    // Unknown operation, no opinion.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'add collaborate session entities');
  }

}
