<?php

namespace Drupal\collaborate_integration\Entity;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Defines the Collaborate session entity.
 *
 * @ingroup collaborate_integration
 *
 * @ContentEntityType(
 *   id = "collaborate_session",
 *   label = @Translation("Collaborate session"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\collaborate_integration\CollaborateSessionListBuilder",
 *     "views_data" = "Drupal\collaborate_integration\Entity\CollaborateSessionViewsData",
 *
 *     "form" = {
 *       "default" = "Drupal\collaborate_integration\Form\CollaborateSessionForm",
 *       "add" = "Drupal\collaborate_integration\Form\CollaborateSessionForm",
 *       "edit" = "Drupal\collaborate_integration\Form\CollaborateSessionForm",
 *       "delete" = "Drupal\collaborate_integration\Form\CollaborateSessionDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\collaborate_integration\CollaborateSessionHtmlRouteProvider",
 *     },
 *     "access" = "Drupal\collaborate_integration\CollaborateSessionAccessControlHandler",
 *   },
 *   base_table = "collaborate_session",
 *   translatable = FALSE,
 *   admin_permission = "administer collaborate session entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/collaborate_session/{collaborate_session}",
 *     "add-form" = "/admin/structure/collaborate_session/add",
 *     "edit-form" = "/admin/structure/collaborate_session/{collaborate_session}/edit",
 *     "delete-form" = "/admin/structure/collaborate_session/{collaborate_session}/delete",
 *     "collection" = "/admin/structure/collaborate_session",
 *   },
 *   field_ui_base_route = "collaborate_session.settings"
 * )
 */
class CollaborateSession extends ContentEntityBase implements CollaborateSessionInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Created from: Entity type'))
      ->setDescription(t('The entity type to which this Collaborate entity was submitted from.'))
      ->setSetting('is_ascii', TRUE)
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH);

    $fields['entity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Submitted to: Entity ID'))
      ->setDescription(t('The ID of the entity of which this Collaborate entity was created from.'))
      ->setSetting('max_length', 255);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the Collaborate session entity.  This will be created from the session title.'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setRequired(TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('The description of the session.  This will be created from the session intro field.'))
      ->setRequired(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 0,
        'settings' => [
          'rows' => 12,
        ],
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['datetime'] = BaseFieldDefinition::create('daterange')
      ->setLabel(t('Date/time'))
      ->setDescription(t('The start/end date/time of the session.  This will be created from the session date/time field.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'daterange_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['noEndDate'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('No end date?'))
      ->setDescription(t('Does the room have an end date.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['createdTimezone'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Timezone'))
      ->setDescription(t('The timezone to create the room in.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDefaultValue("Europe/London")
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['boundaryTime'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('Join time'))
      ->setDescription(t('The number of minutes a user can join the session before the start time.'))
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'normal')
      ->setSettings([
        'allowed_values' => [
          0 => '0',
          15 => '15',
          30 => '30',
          45 => '45',
          60 => '60',
        ],
      ])
      ->setDefaultValue(15)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['participantCanUseTools'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Participants can use tools?'))
      ->setDescription(t('Allow/disallow participant access to tools such as application sharing, screen sharing, timer and polls.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    // @todo: Make this read only for the time being and add relevant properties:
    // recurrenceRule, recurrenceEndType, daysOfTheWeek, recurrenceType, interval,
    // numberOfOccurrences, endDate.
    $fields['occurrenceType'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Occurrence type'))
      ->setDescription(t('Is the session a single-use or perpetual.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 1)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setSettings([
        'allowed_values' => [
          'S' => 'Single',
          'P' => 'Perpetual',
        ],
      ])
      ->setDefaultValue("S")
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setReadOnly(TRUE);

    $fields['allowInSessionInvitees'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Allow in session invites?'))
      ->setDescription(t('Allow/disallow the presenter sending invites from within the session.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['allowGuest'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Allow guests?'))
      ->setDescription(t('Allow/disallow guests from attending a session.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['guestRole'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Guest role'))
      ->setDescription(t('The role assigned to guest attendees.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 1)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setSettings([
        'allowed_values' => [
          'participant' => 'Participant',
          'presenter' => 'Presenter',
          'moderator' => 'Moderator',
        ],
      ])
      ->setDefaultValue("presenter")
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['participantRole'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Participant role'))
      ->setDescription(t('The default role assigned to people who register to attend.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 1)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setSettings([
        'allowed_values' => [
          'participant' => 'Participant',
          'presenter' => 'Presenter',
          'moderator' => 'Moderator',
        ],
      ])
      ->setDefaultValue("presenter")
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['canAnnotateWhiteboard'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users can annotate the whiteboard?'))
      ->setDescription(t('Allow/disallow users from annotating the whiteboard.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['canDownloadRecording'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users can download the recording?'))
      ->setDescription(t('Allow/disallow users from downloading the recording.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['canPostMessage'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users can post chat messages?'))
      ->setDescription(t('Allow/disallow users from posting chat messages.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['canShareAudio'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users can share their audio?'))
      ->setDescription(t('Allow/disallow users from sharing their audio.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['canShareVideo'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users can share their video?'))
      ->setDescription(t('Allow/disallow users from sharing their video.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['mustBeSupervised'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Users must be moderated for chat?'))
      ->setDescription(t('Allow/disallow users from having moderated/un-moderated chat.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['openChair'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Open chair?'))
      ->setDescription(t('Choose if all users that join be made a moderator.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['raiseHandOnEnter'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Raise hand on enter?'))
      ->setDescription(t('Choose if users should automatically raise their hand on joining the session.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['showProfile'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Allow profile sharing?'))
      ->setDescription(t('Allow/disallow whether users can share their profiles with other users.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'label' => 'above',
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['sessionExitUrl'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Exit URL'))
      ->setDescription(t('The link to redirect users to after the session.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['sessionId'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Session ID'))
      ->setDescription(t('Generated after creation.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setReadOnly(TRUE);

    $fields['guestURL'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Guest URL'))
      ->setDescription(t('Generated after creation.'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setReadOnly(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

}
