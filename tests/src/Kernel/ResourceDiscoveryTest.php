<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_server\Plugin\ResourceProviderInterface;
use Drupal\mcp_server\Resource\CacheableResourceContent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Boots a real container and verifies the DKAN resource providers are wired.
 *
 * Proves the services.yml + DI wiring against real DKAN services: both
 * #[ResourceProvider] plugins discover, instantiate through dependency
 * injection (including the injected dkan_query_tools.metastore service),
 * advertise their dkan:// URIs, reject unknown URIs, and gate access on the
 * `access mcp server` permission. Content rendering over the wire is covered
 * by the live HTTP verification, not here — fetching it would exercise DKAN's
 * metastore, not this module's logic.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
#[RunTestsInSeparateProcesses]
class ResourceDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Mirrors ToolDiscoveryTest: every module whose services are referenced while
   * the container builds is listed explicitly (KernelTestBase resolves none).
   */
  protected static $modules = [
    'node',
    'user',
    'dkan_common',
    'dkan_metastore',
    'dkan_datastore',
    'dkan_harvest',
    'search_api',
    'dkan_metastore_search',
    'dkan_query_tools',
    'mcp_server',
    'dkan_mcp_server',
  ];

  /**
   * Resource provider plugin id => advertised URI.
   */
  private const PROVIDERS = [
    'dkan_catalog' => 'dkan://catalog',
    'dkan_schemas' => 'dkan://schemas',
  ];

  /**
   * Both providers discover and instantiate via DI.
   */
  public function testProvidersDiscoverAndInstantiate(): void {
    $definitions = $this->manager()->getDefinitions();
    foreach (array_keys(self::PROVIDERS) as $id) {
      $this->assertArrayHasKey($id, $definitions, "Provider '$id' was not discovered.");
      $plugin = $this->manager()->createInstance($id);
      $this->assertInstanceOf(ResourceProviderInterface::class, $plugin);
    }
  }

  /**
   * Each provider advertises exactly its dkan:// URI and rejects others.
   */
  public function testProvidersAdvertiseUriAndRejectUnknown(): void {
    foreach (self::PROVIDERS as $id => $uri) {
      $plugin = $this->manager()->createInstance($id);

      $resources = $plugin->getResources();
      $this->assertCount(1, $resources, "Provider '$id' should advertise one resource.");
      $this->assertSame($uri, $resources[0]['uri']);
      $this->assertSame('application/json', $resources[0]['mimeType']);

      $this->assertNull(
        $plugin->getResourceContent('dkan://does-not-exist'),
        "Provider '$id' must return NULL for a URI it does not handle.",
      );
    }
  }

  /**
   * The schema list is static content: permanent, no metastore cache tags.
   *
   * Exercised via dkan://schemas (the schema list needs no harvested data), so
   * this covers jsonContent() and the happy-path delegation deterministically.
   * Schema identifiers come from on-disk JSON files, so the content is cached
   * permanently with no node_list:data tag (it changes only on deploy).
   */
  public function testSchemaResourceContentShape(): void {
    $content = $this->manager()->createInstance('dkan_schemas')
      ->getResourceContent('dkan://schemas');
    $this->assertInstanceOf(CacheableResourceContent::class, $content);

    $payload = $content->toResourceContents();
    $this->assertSame('dkan://schemas', $payload['uri']);
    $this->assertSame('application/json', $payload['mimeType']);
    $this->assertIsArray(
      json_decode($payload['text'], TRUE),
      'Resource text must be valid JSON.',
    );

    $this->assertSame(Cache::PERMANENT, $content->metadata->getCacheMaxAge());
    $this->assertContains('user.permissions', $content->metadata->getCacheContexts());
    $this->assertNotContains(
      'node_list:data',
      $content->metadata->getCacheTags(),
      'The static schema list must not carry metastore data cache tags.',
    );
  }

  /**
   * Metastore-backed content is permanent and busted by DKAN data cache tags.
   *
   * Phase 3: rather than serving fresh, content caches permanently and
   * invalidates on the metastore list tag (node_list:data), matching DKAN's own
   * MetastoreApiResponse. Asserted on the tag wiring directly (dataCacheTags()
   * sources the tag; jsonContent() threads it into the DTO) so the test needs
   * no harvested data and stays deterministic.
   */
  public function testCatalogResourceCacheTags(): void {
    $provider = $this->manager()->createInstance('dkan_catalog');

    $tags = $this->invokeProtected($provider, 'dataCacheTags');
    $this->assertContains(
      'node_list:data',
      $tags,
      'dataCacheTags() must source the DKAN metastore list cache tag.',
    );

    $content = $this->invokeProtected(
      $provider,
      'jsonContent',
      ['dkan://catalog', ['catalog' => []], $tags],
    );
    $this->assertInstanceOf(CacheableResourceContent::class, $content);
    $this->assertContains(
      'node_list:data',
      $content->metadata->getCacheTags(),
      'Catalog content must invalidate on metastore data changes.',
    );
    $this->assertSame(Cache::PERMANENT, $content->metadata->getCacheMaxAge());
    $this->assertContains('user.permissions', $content->metadata->getCacheContexts());
  }

  /**
   * Invokes a protected method on a plugin instance.
   *
   * @param object $object
   *   The plugin instance.
   * @param string $method
   *   The protected method name.
   * @param array $args
   *   Positional arguments.
   *
   * @return mixed
   *   The method's return value.
   */
  private function invokeProtected(object $object, string $method, array $args = []): mixed {
    return (new \ReflectionMethod($object, $method))->invokeArgs($object, $args);
  }

  /**
   * Access requires the access mcp server permission; unknown URIs are denied.
   */
  public function testAccessGatedByPermission(): void {
    $permitted = $this->createMock(AccountInterface::class);
    $permitted->method('hasPermission')
      ->willReturnCallback(static fn (string $p): bool => $p === 'access mcp server');
    $anonymous = $this->createMock(AccountInterface::class);

    foreach (self::PROVIDERS as $id => $uri) {
      $plugin = $this->manager()->createInstance($id);
      $this->assertTrue(
        $plugin->checkAccess($uri, $permitted)->isAllowed(),
        "Provider '$id' should allow a user with 'access mcp server'.",
      );
      $this->assertFalse(
        $plugin->checkAccess($uri, $anonymous)->isAllowed(),
        "Provider '$id' should deny a user without 'access mcp server'.",
      );
      $this->assertTrue(
        $plugin->checkAccess('dkan://does-not-exist', $permitted)->isForbidden(),
        "Provider '$id' must forbid a URI it does not handle.",
      );
    }
  }

  /**
   * The install/update merge adds our providers without clobbering or dupes.
   *
   * Covers the upgrade path: hook_install() and dkan_mcp_server_update_10002()
   * share _dkan_mcp_server_register_resource_providers(), which must add only
   * the missing entries and be safe to run twice.
   */
  public function testResourceProviderRegistrationMerge(): void {
    \Drupal::moduleHandler()->loadInclude('dkan_mcp_server', 'install');

    // Start from a foreign-only entry to prove non-clobbering.
    \Drupal::configFactory()->getEditable('mcp_server.resource_providers')
      ->set('plugins', [
        ['id' => 'content_type_list', 'enabled' => TRUE, 'configuration' => ['enabled' => TRUE]],
      ])
      ->save();

    _dkan_mcp_server_register_resource_providers();
    $this->assertSame(
      ['content_type_list', 'dkan_catalog', 'dkan_schemas'],
      $this->registeredProviderIds(),
      'Merge must add our providers and keep the existing one.',
    );

    // Idempotent: a second run adds no duplicates.
    _dkan_mcp_server_register_resource_providers();
    $this->assertSame(
      ['content_type_list', 'dkan_catalog', 'dkan_schemas'],
      $this->registeredProviderIds(),
      'Re-running the merge must not duplicate entries.',
    );
  }

  /**
   * Sorted plugin IDs currently registered in mcp_server.resource_providers.
   *
   * @return string[]
   *   The registered resource provider plugin IDs, sorted.
   */
  private function registeredProviderIds(): array {
    $plugins = \Drupal::configFactory()
      ->get('mcp_server.resource_providers')
      ->get('plugins') ?? [];
    $ids = array_column($plugins, 'id');
    sort($ids);
    return $ids;
  }

  /**
   * The MCP resource provider plugin manager.
   */
  private function manager(): object {
    return $this->container->get('plugin.manager.mcp_server.resource_provider');
  }

}
