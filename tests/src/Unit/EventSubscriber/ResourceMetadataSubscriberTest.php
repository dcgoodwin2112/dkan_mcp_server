<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\EventSubscriber;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_mcp_server\EventSubscriber\ResourceMetadataSubscriber;
use Drupal\dkan_mcp_server\OAuth\DkanMcpScopes;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DKAN MCP OAuth protected-resource metadata.
 */
final class ResourceMetadataSubscriberTest extends TestCase {

  /**
   * Builds a subscriber whose site has the given oauth2_scope entities.
   *
   * @param string[] $scope_names
   *   Names of the oauth2_scope entities present on the site.
   * @param bool $has_definition
   *   Whether the oauth2_scope entity type is defined.
   */
  private function subscriber(array $scope_names, bool $has_definition = TRUE): ResourceMetadataSubscriber {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->with('oauth2_scope')->willReturn($has_definition);

    if ($has_definition) {
      $entities = [];
      foreach ($scope_names as $name) {
        $scope = $this->createMock(ConfigEntityInterface::class);
        $scope->method('get')->with('name')->willReturn($name);
        $entities[] = $scope;
      }
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('loadMultiple')->willReturn($entities);
      $entity_type_manager->method('getStorage')->with('oauth2_scope')->willReturn($storage);
    }

    return new ResourceMetadataSubscriber($entity_type_manager);
  }

  /**
   * The subscriber only registers when simple_oauth_21 is installed.
   */
  public function testSubscribedEventsReflectOptionalDependency(): void {
    $events_class = 'Drupal\simple_oauth_server_metadata\Event\ResourceMetadataEvents';
    $events = ResourceMetadataSubscriber::getSubscribedEvents();

    if (!class_exists($events_class)) {
      $this->assertSame([], $events);
      return;
    }

    $this->assertSame('onBuild', $events[constant($events_class . '::BUILD')]);
  }

  /**
   * DKAN MCP scopes are added, deduplicated, and sorted.
   */
  public function testOnBuildAddsScopesSupported(): void {
    $event = new ResourceMetadataEventStub([
      'scopes_supported' => [
        DkanMcpScopes::WRITE,
        'existing:scope',
      ],
    ]);

    $this->subscriber([DkanMcpScopes::READ, DkanMcpScopes::WRITE])->onBuild($event);

    $this->assertSame([
      DkanMcpScopes::READ,
      DkanMcpScopes::WRITE,
      'existing:scope',
    ], $event->metadata['scopes_supported']);
  }

  /**
   * Malformed pre-existing metadata is replaced with the DKAN scopes.
   */
  public function testOnBuildHandlesMalformedExistingScopes(): void {
    $event = new ResourceMetadataEventStub([
      'scopes_supported' => 'not-an-array',
    ]);

    $this->subscriber([DkanMcpScopes::READ, DkanMcpScopes::WRITE])->onBuild($event);

    $this->assertSame(DkanMcpScopes::all(), $event->metadata['scopes_supported']);
  }

  /**
   * Only scopes whose oauth2_scope entity exists are advertised.
   */
  public function testAdvertisesOnlyExistingScopes(): void {
    $event = new ResourceMetadataEventStub([]);

    // The write scope entity has been deleted; only read remains issuable.
    $this->subscriber([DkanMcpScopes::READ])->onBuild($event);

    $this->assertSame([DkanMcpScopes::READ], $event->metadata['scopes_supported']);
  }

  /**
   * Existing metadata is left untouched when no DKAN scope entity exists.
   */
  public function testNoScopesAdvertisedWhenNonePresent(): void {
    $event = new ResourceMetadataEventStub([
      'scopes_supported' => ['existing:scope'],
    ]);

    $this->subscriber([])->onBuild($event);

    $this->assertSame(['existing:scope'], $event->metadata['scopes_supported']);
  }

  /**
   * Falls back to the full scope set when oauth2_scope is undefined.
   */
  public function testFallsBackWhenScopeEntityTypeMissing(): void {
    $event = new ResourceMetadataEventStub([]);

    $this->subscriber([], has_definition: FALSE)->onBuild($event);

    $this->assertSame(DkanMcpScopes::all(), $event->metadata['scopes_supported']);
  }

  /**
   * Unexpected events are ignored defensively.
   */
  public function testUnexpectedEventShapeIgnored(): void {
    $event = new \stdClass();

    $this->subscriber([DkanMcpScopes::READ, DkanMcpScopes::WRITE])->onBuild($event);

    $this->assertSame([], get_object_vars($event));
  }

}

/**
 * Minimal stand-in for simple_oauth_server_metadata's event.
 */
final class ResourceMetadataEventStub {

  /**
   * Constructs a resource metadata event stub.
   *
   * @param array<string, mixed> $metadata
   *   Initial metadata values.
   */
  public function __construct(
    public array $metadata = [],
  ) {}

  /**
   * Adds or replaces a metadata field.
   */
  public function addMetadataField(string $name, mixed $value): void {
    $this->metadata[$name] = $value;
  }

}
