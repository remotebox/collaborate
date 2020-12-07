<?php

namespace Drupal\collaborate_integration\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * Provides an interface for defining Collaborate session entities.
 *
 * @ingroup collaborate_integration
 */
interface CollaborateSessionInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface {

  /**
   * Add get/set methods for your configuration properties here.
   */

  /**
   * Gets the Collaborate session name.
   *
   * @return string
   *   Name of the Collaborate session.
   */
  public function getName();

  /**
   * Sets the Collaborate session name.
   *
   * @param string $name
   *   The Collaborate session name.
   *
   * @return \Drupal\collaborate_integration\Entity\CollaborateSessionInterface
   *   The called Collaborate session entity.
   */
  public function setName($name);

  /**
   * Gets the Collaborate session creation timestamp.
   *
   * @return int
   *   Creation timestamp of the Collaborate session.
   */
  public function getCreatedTime();

  /**
   * Sets the Collaborate session creation timestamp.
   *
   * @param int $timestamp
   *   The Collaborate session creation timestamp.
   *
   * @return \Drupal\collaborate_integration\Entity\CollaborateSessionInterface
   *   The called Collaborate session entity.
   */
  public function setCreatedTime($timestamp);

}
