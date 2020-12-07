<?php

namespace Drupal\collaborate_integration\Entity;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for Collaborate session entities.
 */
class CollaborateSessionViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Additional information for Views integration, such as table joins, can be
    // put here.
    return $data;
  }

}
