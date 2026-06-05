<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_server\Plugin\ResourceTemplateProviderInterface;
use Mcp\Exception\ResourceNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Boots a real container and verifies the DKAN resource templates are wired.
 *
 * Proves the services.yml + DI wiring against real DKAN services: every
 * #[ResourceTemplateProvider] plugin discovers, instantiates through dependency
 * injection (including the injected dkan_query_tools.* services), advertises
 * its dkan:// URI template, matches concrete URIs, rejects unrelated ones, and
 * gates access on `access mcp server`. Happy-path content needs
 * harvested data, so it is covered by live HTTP verification, not here; this
 * test covers the deterministic well-formed-but-missing path (backing call
 * returns an error payload, so getResourceContent() throws
 * ResourceNotFoundException) and the unmatched-URI path (returns NULL).
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
#[RunTestsInSeparateProcesses]
class ResourceTemplateDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Mirrors ResourceDiscoveryTest: every module whose services are referenced
   * while the container builds is listed explicitly.
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
   * Template plugin id => [advertised uriTemplate, a matching concrete URI].
   */
  private const TEMPLATES = [
    'dkan_dataset' => ['dkan://dataset/{id}', 'dkan://dataset/abc-123'],
    'dkan_distribution' => ['dkan://distribution/{id}', 'dkan://distribution/abc-123'],
    'dkan_dataset_dictionary' => ['dkan://dataset/{id}/dictionary', 'dkan://dataset/abc-123/dictionary'],
    'dkan_datastore_schema' => ['dkan://datastore/{resourceId}/schema', 'dkan://datastore/abc__1/schema'],
  ];

  /**
   * Every template plugin discovers and instantiates via DI.
   */
  public function testTemplatesDiscoverAndInstantiate(): void {
    $definitions = $this->manager()->getDefinitions();
    foreach (array_keys(self::TEMPLATES) as $id) {
      $this->assertArrayHasKey($id, $definitions, "Template '$id' was not discovered.");
      $plugin = $this->manager()->createInstance($id);
      $this->assertInstanceOf(ResourceTemplateProviderInterface::class, $plugin);
    }
  }

  /**
   * Each template advertises exactly its uriTemplate as a JSON resource.
   */
  public function testTemplatesAdvertiseUriTemplate(): void {
    foreach (self::TEMPLATES as $id => [$uriTemplate]) {
      $plugin = $this->manager()->createInstance($id);
      $this->assertSame($uriTemplate, $plugin->getUriTemplate());

      $resources = $plugin->getResources();
      $this->assertCount(1, $resources, "Template '$id' should advertise one resource.");
      $this->assertSame($uriTemplate, $resources[0]['uri']);
      $this->assertSame('application/json', $resources[0]['mimeType']);
    }
  }

  /**
   * A well-formed URI for a missing id throws ResourceNotFoundException.
   *
   * The backing dkan_query_tools call returns an ['error' => ...] array for an
   * unknown id; the base must translate that to a ResourceNotFoundException so
   * the SDK reports a clean resource-not-found, not the generic internal error
   * that returning NULL would trigger via mcp_server's RuntimeException path.
   */
  public function testMissingIdThrowsResourceNotFound(): void {
    foreach (self::TEMPLATES as $id => [, $concreteUri]) {
      $plugin = $this->manager()->createInstance($id);
      try {
        $plugin->getResourceContent($concreteUri);
        $this->fail("Template '$id' must throw for a well-formed but missing id.");
      }
      catch (ResourceNotFoundException $e) {
        $this->assertStringContainsString(
          $concreteUri,
          $e->getMessage(),
          "Template '$id' not-found message should name the URI.",
        );
      }
    }
  }

  /**
   * A URI matching none of a provider's templates resolves to NULL.
   *
   * Distinct from a missing id: the provider has nothing to say about an
   * unrelated URI, so it returns NULL rather than claiming not-found.
   */
  public function testUnmatchedUriReturnsNull(): void {
    foreach (array_keys(self::TEMPLATES) as $id) {
      $plugin = $this->manager()->createInstance($id);
      $this->assertNull(
        $plugin->getResourceContent('dkan://does-not-exist'),
        "Template '$id' must return NULL for a URI it does not match.",
      );
    }
  }

  /**
   * Access allows a matching URI under permission; unmatched URIs are denied.
   */
  public function testAccessGatedByPermissionAndUriShape(): void {
    $permitted = $this->createMock(AccountInterface::class);
    $permitted->method('hasPermission')
      ->willReturnCallback(static fn (string $p): bool => $p === 'access mcp server');
    $anonymous = $this->createMock(AccountInterface::class);

    foreach (self::TEMPLATES as $id => [, $concreteUri]) {
      $plugin = $this->manager()->createInstance($id);
      $this->assertTrue(
        $plugin->checkAccess($concreteUri, $permitted)->isAllowed(),
        "Template '$id' should allow a matching URI for a permitted user.",
      );
      $this->assertFalse(
        $plugin->checkAccess($concreteUri, $anonymous)->isAllowed(),
        "Template '$id' should deny a user without 'access mcp server'.",
      );
      $this->assertTrue(
        $plugin->checkAccess('dkan://does-not-exist', $permitted)->isForbidden(),
        "Template '$id' must forbid a URI it does not match.",
      );
    }
  }

  /**
   * The install/update merge adds our templates without clobbering or dupes.
   *
   * Covers dkan_mcp_server_update_10003() and the shared merge helper against
   * the separate mcp_server.resource_template_providers config object.
   */
  public function testTemplateRegistrationMerge(): void {
    \Drupal::moduleHandler()->loadInclude('dkan_mcp_server', 'install');

    // Start from a foreign-only entry to prove non-clobbering.
    \Drupal::configFactory()->getEditable('mcp_server.resource_template_providers')
      ->set('plugins', [
        ['id' => 'content_entity', 'enabled' => TRUE, 'configuration' => ['enabled' => TRUE]],
      ])
      ->save();

    $expected = [
      'content_entity',
      'dkan_dataset',
      'dkan_dataset_dictionary',
      'dkan_datastore_schema',
      'dkan_distribution',
    ];

    _dkan_mcp_server_register_resource_template_providers();
    $this->assertSame(
      $expected,
      $this->registeredTemplateIds(),
      'Merge must add our templates and keep the existing one.',
    );

    // Idempotent: a second run adds no duplicates.
    _dkan_mcp_server_register_resource_template_providers();
    $this->assertSame(
      $expected,
      $this->registeredTemplateIds(),
      'Re-running the merge must not duplicate entries.',
    );
  }

  /**
   * Sorted plugin IDs in mcp_server.resource_template_providers.
   *
   * @return string[]
   *   The registered template provider plugin IDs, sorted.
   */
  private function registeredTemplateIds(): array {
    $plugins = \Drupal::configFactory()
      ->get('mcp_server.resource_template_providers')
      ->get('plugins') ?? [];
    $ids = array_column($plugins, 'id');
    sort($ids);
    return $ids;
  }

  /**
   * The MCP resource template provider plugin manager.
   */
  private function manager(): object {
    return $this->container->get('plugin.manager.mcp_server.resource_template_provider');
  }

}
