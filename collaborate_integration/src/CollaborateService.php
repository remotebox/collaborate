<?php

namespace Drupal\collaborate_integration;

use Drupal\collaborate_integration\Entity\CollaborateSession;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\profile\Entity\Profile;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a service to interact with the Collaborate API.
 */
class CollaborateService {

  /**
   * The configuration object factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Token string.
   *
   * @var null
   */
  private $token = NULL;

  /**
   * Expiration date of token.
   *
   * @var null
   */
  private $tokenExpires = NULL;

  /**
   * Constructs a CollaborateService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger,
    ClientInterface $http_client,
    AccountInterface $account,
    EntityTypeManagerInterface $entityTypeManager) {
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
    $this->logger = $logger->get('collaborate_integration');
    $this->httpClient = $http_client;
    $this->account = $account;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('messenger')->get('collaborate_integration'),
      $container->get('logger.factory')->get('collaborate_integration'),
      $container->get('http_client'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Gets the settings to interact with the Collaborate API from confg.
   *
   * @return object
   *   The Collaborate API settings.
   */
  private function getConfig() {
    $config = $this->configFactory->get('collaborate_integration.auth_settings');
    return (object) [
      'url' => $config->get('url'),
      'key' => $config->get('key'),
      'secret' => $config->get('secret'),
    ];
  }

  /**
   * Generate an access toke for the Collaborate API.
   *
   * @return string|false
   *   The token string or false if failure.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function setToken() {
    $auth = $this->getConfig();

    $header = json_encode(["alg" => "HS256", "typ" => "JWT"]);

    // Encode Header to Base64Url String.
    $base64UrlHeader = str_replace(
      ['+', '/', '='],
      ['-', '_', ''],
      base64_encode($header)
    );
    $payload = json_encode(
      [
        "iss" => $auth->key,
        "sub" => $auth->key,
        "exp" => time() + 60 * 5,
      ]
    );

    // Encode Payload to Base64Url String.
    $base64UrlPayload = str_replace(
      ['+', '/', '='],
      ['-', '_', ''],
      base64_encode($payload)
    );
    $signature = hash_hmac(
      'sha256',
      $base64UrlHeader . "." . $base64UrlPayload,
      $auth->secret,
      TRUE
    );
    $base64UrlSignature = str_replace(
      ['+', '/', '='],
      ['-', '_', ''],
      base64_encode($signature)
    );

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    try {
      $response = $this->httpClient->request(
        'POST',
        $auth->url . '/token',
        [
          'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
          'query' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
          ],
          'verify' => FALSE,
        ]
      );
      $token = json_decode($response->getBody()->getContents());
      if (!empty($token->access_token)) {
        $this->tokenExpires = time() + $token->expires_in;
        $this->token = $token;
        return $token;
      }
      else {
        return FALSE;
      }
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $this->logger->critical(
        t(
          'Error getting access token: %status - @message<br>%trace',
          [
            '%status' => $response->getStatusCode(),
            '@message' => $response->getReasonPhrase(),
            '%trace' => $e->getTraceAsString(),
          ]
        )
      );
    }
  }

  /**
   * Makes the http request to the Collaborate server.
   *
   * @param string $verb
   *   The request type.
   * @param string $path
   *   The path for the request.
   * @param null|object $json
   *   The JSON payload.
   * @param array $querystring
   *   Parameters for querystring.
   *
   * @return false|null|object
   *   Returns false with error, object with success or null if no information
   *   e.g. DELETE calls.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function callCollaborate($verb, $path, $json = NULL, array $querystring = []) {
    $auth = $this->getConfig();

    if ($this->tokenExpires < time()) {
      $this->setToken();
    }

    try {
      $response = $this->httpClient->request(
        $verb,
        $auth->url . $path,
        [
          'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token->access_token,
          ],
          'json' => $json,
          'query' => $querystring,
          'verify' => FALSE,
        ]
      );
      return json_decode($response->getBody());
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $this->logger->critical(
        t(
          'Error calling Collaborate: %status - @message<br><b>Path:</b> %path<br><b>JSON:</b> %json<br><b>Querystring:</b> %query',
          [
            '%status' => $response->getStatusCode(),
            '@message' => $response->getReasonPhrase(),
            '%path' => $e->getRequest()->getUri()->getPath(),
            '%json' => print_r($json, TRUE),
            '%query' => print_r($querystring, TRUE)
          ]
        )
      );
      return FALSE;
    }
  }

  /**
   * Creates a session on the Collaborate servers.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   The Collaborate entity.
   *
   * @return false|null|object
   *   Returns false with error, object with success or null if no information
   *   e.g. DELETE calls.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function createSession(CollaborateSession $entity) {
    if (!$entity instanceof CollaborateSession) {
      $this->logger->error(
        'Cannot create Collab session due to lack of collaborate entity instances.'
      );
      return FALSE;
    }

    $start = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $entity->get('datetime')->value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    );
    $end = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $entity->get('datetime')->end_value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    );

    $json = (object) [
      "name" => $entity->get('name')->value,
      "description" => $entity->get("description")->value,
      "startTime" => $start->format("Y-m-d\TH:i:s"),
      "endTime" => $end->format("Y-m-d\TH:i:s"),
      "noEndDate" => ($entity->get("noEndDate")->value) ? TRUE : FALSE,
      "createdTimezone" => $entity->get("createdTimezone")->value,
      "boundaryTime" => $entity->get("boundaryTime")->value,
      "participantCanUseTools" => ($entity->get("participantCanUseTools")->value) ? TRUE : FALSE,
      "occurrenceType" => $entity->get("occurrenceType")->value,
      "allowInSessionInvitees" => ($entity->get("allowInSessionInvitees")->value) ? TRUE : FALSE,
      "allowGuest" => ($entity->get("allowGuest")->value) ? TRUE : FALSE,
      "guestRole" => $entity->get("guestRole")->value,
      "canAnnotateWhiteboard" => ($entity->get("canAnnotateWhiteboard")->value) ? TRUE : FALSE,
      "canDownloadRecording" => ($entity->get("canDownloadRecording")->value) ? TRUE : FALSE,
      "canPostMessage" => ($entity->get("canPostMessage")->value) ? TRUE : FALSE,
      "canShareAudio" => ($entity->get("canShareAudio")->value) ? TRUE : FALSE,
      "canShareVideo" => ($entity->get("canShareVideo")->value) ? TRUE : FALSE,
      "mustBeSupervised" => ($entity->get("mustBeSupervised")->value) ? TRUE : FALSE,
      "openChair" => ($entity->get("openChair")->value) ? TRUE : FALSE,
      "raiseHandOnEnter" => ($entity->get("raiseHandOnEnter")->value) ? TRUE : FALSE,
      "showProfile" => ($entity->get("showProfile")->value) ? TRUE : FALSE,
      "sessionExitUrl" => $entity->get("sessionExitUrl")->value,
    ];
    return $this->callCollaborate('POST', '/sessions', $json);
  }

  /**
   * Updates a session on the Collaborate servers.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   The Collaborate entity.
   *
   * @return false|null|object
   *   Returns false with error, object with success or null if no information
   *   e.g. DELETE calls.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function updateSession(CollaborateSession $entity) {
    if (!$entity instanceof CollaborateSession) {
      $this->logger->error(
        'Cannot update Collab session due to lack of collaborate entity instances.'
      );
      return FALSE;
    }

    $start = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $entity->get('datetime')->value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    );
    $end = DrupalDateTime::createFromFormat(
      'Y-m-d\TH:i:s', $entity->get('datetime')->end_value,
      DateTimeItemInterface::STORAGE_TIMEZONE
    );

    $json = (object) [
      "name" => $entity->get('name')->value,
      "description" => $entity->get("description")->value,
      "startTime" => $start->format("Y-m-d\TH:i:s"),
      "endTime" => $end->format("Y-m-d\TH:i:s"),
      "noEndDate" => ($entity->get("noEndDate")->value) ? TRUE : FALSE,
      "createdTimezone" => $entity->get("createdTimezone")->value,
      "boundaryTime" => $entity->get("boundaryTime")->value,
      "participantCanUseTools" => ($entity->get("participantCanUseTools")->value) ? TRUE : FALSE,
      "occurrenceType" => $entity->get("occurrenceType")->value,
      "allowInSessionInvitees" => ($entity->get("allowInSessionInvitees")->value) ? TRUE : FALSE,
      "allowGuest" => ($entity->get("allowGuest")->value) ? TRUE : FALSE,
      "guestRole" => $entity->get("guestRole")->value,
      "canAnnotateWhiteboard" => ($entity->get("canAnnotateWhiteboard")->value) ? TRUE : FALSE,
      "canDownloadRecording" => ($entity->get("canDownloadRecording")->value) ? TRUE : FALSE,
      "canPostMessage" => ($entity->get("canPostMessage")->value) ? TRUE : FALSE,
      "canShareAudio" => ($entity->get("canShareAudio")->value) ? TRUE : FALSE,
      "canShareVideo" => ($entity->get("canShareVideo")->value) ? TRUE : FALSE,
      "mustBeSupervised" => ($entity->get("mustBeSupervised")->value) ? TRUE : FALSE,
      "openChair" => ($entity->get("openChair")->value) ? TRUE : FALSE,
      "raiseHandOnEnter" => ($entity->get("raiseHandOnEnter")->value) ? TRUE : FALSE,
      "showProfile" => ($entity->get("showProfile")->value) ? TRUE : FALSE,
      "sessionExitUrl" => $entity->get("sessionExitUrl")->value,
    ];
    return $this->callCollaborate('PATCH', "/sessions/" . $entity->get('sessionId')->value, $json);
  }

  /**
   * Delete a session on the Collaborate servers.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   The Collaborate entity.
   *
   * @return false|null|object
   *   Returns false with error, object with success or null if no information
   *   e.g. DELETE calls.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function deleteSession(CollaborateSession $entity) {
    if (!$entity instanceof CollaborateSession) {
      $this->logger->error(
        'Cannot delete Collab session due to lack of collaborate entity instances.'
      );
      return FALSE;
    }
    return $this->callCollaborate('DELETE', "/sessions/" . $entity->get('sessionId')->value);
  }

  /**
   * Enrols a user on to a Collaborate session.
   *
   * Process creates a Collaborate user if they don't already exist.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   Collaborate entity consisting of session information.
   * @param string $role
   *   Role to be given the user.
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return false|object
   *   Returns false with error, or the enrolment object with success.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function enrolUser(CollaborateSession $entity, $role, User $account) {
    if (!$account instanceof User) {
      $this->logger->error(
        'Cannot enrol user due to lack of a user entity instance.'
      );
      return FALSE;
    }

    $sessionId = $entity->get('sessionId')->value;

    $collaborate_user = $this->getUser($account);
    if (empty($collaborate_user->extId)) {
      $collaborate_user = $this->createUser($account);
    }

    if (!$collaborate_user) {
      return FALSE;
    }

    $enrolment = $this->getEnrolment(
      $entity,
      $account
    );

    if ($enrolment === FALSE) {
      $json = (object) [
        "userId" => $collaborate_user->id,
        "launchingRole" => $role,
        "editingPermission" => $role === 'moderator' ? 'writer' : 'reader',
      ];
      return $this->callCollaborate(
        'POST',
        "/sessions/$sessionId/enrollments",
        $json
      );
    }
    else {
      return $enrolment;
    }
  }

  /**
   * Gets the enrolment for a user on a Collaborate session.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   Collaborate entity consisting of session information.
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return false|object
   *   Enrolment object or FALSE if no enrolment/error.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getEnrolment(CollaborateSession $entity, User $account) {
    $collaborate_user = $this->getUser($account);
    if (!$collaborate_user) {
      $this->logger->error(
        'Cannot get enrolment due to no matching collaborate user.'
      );
      return FALSE;
    }
    $result = $this->callCollaborate(
      'GET',
      "/sessions/" . $entity->get('sessionId')->value . "/enrollments",
      NULL,
      ['userId' => $collaborate_user->id]
    );
    if (isset($result->results)) {
      return reset($result->results);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Deletes enrolment for a registered user.
   *
   * @param \Drupal\collaborate_integration\Entity\CollaborateSession $entity
   *   Collaborate entity consisting of session information.
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return false|null
   *   Returns null if success, false if error.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function deleteEnrolment(CollaborateSession $entity, User $account) {
    if (!$account instanceof User) {
      $this->logger->error(
        'Cannot delete enrolment due to no valid user entity.'
      );
      return FALSE;
    }
    $sessionId = $entity->get('sessionId')->value;
    $enrolment = $this->getEnrolment(
      $entity,
      $account
    );

    if (isset($enrolment->id)) {
      $enrolmentId = $enrolment->id;
      $json = (object) [
        "sessionId" => $sessionId,
        "enrollmentId" => $enrolmentId,
      ];
      return $this->callCollaborate(
        'DELETE',
        "/sessions/$sessionId/enrollments/$enrolmentId",
        $json
      );
    }
    else {
      return FALSE;
    }
  }

  /**
   * Gets a users recordings.
   *
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return object|false
   *   Returns the results object if there are recordings.
   *   Otherwise it returns false.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getRecordings(User $account) {
    $collaborate_user = $this->getUser($account);
    if (!$collaborate_user) {
      return FALSE;
    }
    $query_params = [
      "userId" => $collaborate_user->id,
    ];
    $results = $this->callCollaborate(
      'GET',
      '/recordings',
      NULL,
      $query_params
    );
    if (!empty($results->results)) {
      return $results;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Gets the Collaborate recording link.
   *
   * @param string $recordingId
   *   The recording id.
   *
   * @return string|false
   *   The recording link string or false for error.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getRecordingLink($recordingId) {
    $result = $this->callCollaborate(
      'GET',
      "/recordings/$recordingId/url"
    );
    if ($result) {
      return $result->url;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get Collaborate user.
   *
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return object|false
   *   Returns the user object or false if no user.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function getUser(User $account) {
    $query_params = [
      "extId" => $account->getAccountName(),
    ];
    $result = $this->callCollaborate(
      'GET',
      '/users',
      NULL,
      $query_params
    );
    if (!empty($result->results)) {
      return reset($result->results);
    }
  }

  /**
   * Creates a user in Collaborate.
   *
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return mixed
   *   Returns the user object or false if error.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  private function createUser(User $account) {
    // @todo: Do we need to add a way to update accounts?
    $profile = $this->getUserProfile($account);
    if ($profile instanceof Profile) {
      // Get user profile fields.
      $user = (object) [
        "firstName" => $profile->get('field_prof_first_name')->value,
        "lastName" => $profile->get('field_prof_last_name')->value,
        "avatarUrl" => '',
        "displayName" => $profile->get('field_prof_first_name')->value . ' ' . $profile->get('field_prof_last_name')->value,
        "extId" => $account->getAccountName(),
        "email" => $account->getEmail(),
        "created" => time(),
        "modified" => time(),
      ];
      return $this->callCollaborate('POST', '/users', $user);
    }
    else {
      if (!empty($account->get('field_ldap_preferred_name')->value)) {
        $first = $account->get('field_ldap_preferred_name')->value;
      }
      else {
        $first = $account->get('field_ldap_given_name')->value;
      }
      $last = $account->get('field_ldap_last_name')->value;
      $user = (object) [
        "firstName" => $first,
        "lastName" => $last,
        "avatarUrl" => '',
        "displayName" => $first . ' ' . $last,
        "extId" => $account->getAccountName(),
        "email" => $account->getEmail(),
        "created" => time(),
        "modified" => time(),
      ];
      return $this->callCollaborate('POST', '/users', $user);
    }
  }

  /**
   * Gets the Drupal user profile information.
   *
   * @param \Drupal\user\Entity\User $account
   *   The user account to get information from.
   *
   * @return \Drupal\profile\Entity\ProfileInterface|false
   *   Returns a Drupal profile entity or false if error.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getUserProfile(User $account) {
    $profile_query = $this->entityTypeManager->getStorage('profile')
      ->loadByProperties(['uid' => $account->id()]);
    return $profile_query ? reset($profile_query) : FALSE;
  }

}
