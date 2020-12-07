<?php

namespace Drupal\collaborate_integration;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Collaborate session entities.
 *
 * @ingroup collaborate_integration
 */
class CollaborateSessionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('Collaborate session ID');
    $header['name'] = $this->t('Name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var \Drupal\collaborate_integration\Entity\CollaborateSession $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.collaborate_session.edit_form',
      ['collaborate_session' => $entity->id()]
    );
    return $row + parent::buildRow($entity);
  }

}
