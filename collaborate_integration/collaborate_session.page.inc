<?php

/**
 * @file
 * Contains collaborate_session.page.inc.
 *
 * Page callback for Collaborate session entities.
 */

use Drupal\Core\Render\Element;

/**
 * Prepares variables for Collaborate session templates.
 *
 * Default template: collaborate_session.html.twig.
 *
 * @param array $variables
 *   An associative array containing:
 *   - elements: An associative array containing the user information and any
 *   - attributes: HTML attributes for the containing element.
 */
function template_preprocess_collaborate_session(array &$variables) {
  // Fetch CollaborateSession Entity Object.
  $collaborate_session = $variables['elements']['#collaborate_session'];

  // Helpful $content variable for templates.
  foreach (Element::children($variables['elements']) as $key) {
    $variables['content'][$key] = $variables['elements'][$key];
  }
}
