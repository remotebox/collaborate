<?php

namespace Drupal\events_register\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
/**
 * Class RegisterTabController.
 */
class RegisterTabController extends ControllerBase {

  /**
   * Register Link.
   *
   * @param \Drupal\node\NodeInterface $node
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the event register with node id set.
   */
  public function registerLink (NodeInterface $node) {
    // @TODO: Check if this is best practice.
    return $this->redirect('events_register.events_register_form', ['nid' => $node->id()]);
  }

  /**
   * @param \Drupal\node\NodeInterface $node
   *
   * @return \Drupal\Core\Access\AccessResult
   */
  public function checkNode (NodeInterface $node) {
    return AccessResult::allowedif(
      $node->bundle() === 'tutorial' ||
      $node->bundle() === 'workshop'
    );
  }
}
