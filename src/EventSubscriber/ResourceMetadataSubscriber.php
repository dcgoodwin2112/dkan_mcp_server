<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_mcp_server\OAuth\DkanMcpScopes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Advertises DKAN MCP scopes in OAuth protected resource metadata.
 *
 * Mcp_server_oauth discovers scopes from mcp_tool_config entities. This module
 * uses native #[Tool] plugins instead, so there are no bridge config entities
 * for it to inspect. This subscriber fills that discovery gap while remaining
 * inert when simple_oauth_21 is not installed.
 */
final class ResourceMetadataSubscriber implements EventSubscriberInterface {

  /**
   * The simple_oauth_21 event constants class.
   */
  private const RESOURCE_METADATA_EVENTS = 'Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    if (!class_exists(self::RESOURCE_METADATA_EVENTS)) {
      return [];
    }

    return [
      constant(self::RESOURCE_METADATA_EVENTS . '::BUILD') => 'onBuild',
    ];
  }

  /**
   * Adds DKAN MCP scopes to the protected resource metadata event.
   *
   * The event class is optional at install time, so this method accepts an
   * object and uses the small ResourceMetadataEvent surface we need:
   * a public metadata array plus addMetadataField().
   */
  public function onBuild(object $event): void {
    if (!property_exists($event, 'metadata') || !method_exists($event, 'addMetadataField')) {
      return;
    }

    $dkan_scopes = $this->availableScopes();
    if ($dkan_scopes === []) {
      return;
    }

    $existing_scopes = $event->metadata['scopes_supported'] ?? [];
    if (!is_array($existing_scopes)) {
      $existing_scopes = [];
    }

    $scopes = array_values(array_unique(array_merge(
      $existing_scopes,
      $dkan_scopes,
    )));
    sort($scopes);

    $event->addMetadataField('scopes_supported', $scopes);
  }

  /**
   * Returns the DKAN MCP scopes that are actually issuable.
   *
   * Discovery must not advertise scopes simple_oauth cannot issue. Filters the
   * module's scope names against the oauth2_scope entities present on the site,
   * so deleting (or never importing) a scope drops it from the metadata. Falls
   * back to the full set when the entity type is unavailable — the event fires
   * only with simple_oauth_server_metadata enabled, which implies simple_oauth.
   *
   * @return string[]
   *   Advertisable scope names.
   */
  private function availableScopes(): array {
    $all = DkanMcpScopes::all();
    if (!$this->entityTypeManager->hasDefinition('oauth2_scope')) {
      return $all;
    }
    $present = [];
    foreach ($this->entityTypeManager->getStorage('oauth2_scope')->loadMultiple() as $scope) {
      $name = $scope->get('name');
      if (is_string($name)) {
        $present[$name] = TRUE;
      }
    }
    return array_values(array_filter($all, static fn (string $scope): bool => isset($present[$scope])));
  }

}
