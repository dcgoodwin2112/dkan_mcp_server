<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_server\Plugin\ToolPluginInterface;
use Drupal\mcp_server\Plugin\ToolPluginManager;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Boots a real container and verifies the DKAN tool plugins are wired.
 *
 * Where the unit tests mock the plugin manager, this proves the services.yml
 * wiring against real DKAN services: every #[Tool] plugin discovers,
 * instantiates through dependency injection, and is enabled by default. It also
 * checks the access matrix resolves through the real plugins and permission
 * system for the anonymous user.
 *
 * @group dkan_mcp_server
 */
#[RunTestsInSeparateProcesses]
class ToolDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * KernelTestBase does not resolve dependencies, so every module whose
   * services are referenced while the container builds is listed explicitly.
   * The set mirrors DKAN's own datastore/harvest kernel tests plus this
   * module's mcp_server and dkan_query_tools dependencies.
   */
  protected static $modules = [
    'node',
    'user',
    'dkan_common',
    'dkan_metastore',
    'dkan_datastore',
    'dkan_harvest',
    'dkan_query_tools',
    'mcp_server',
    'dkan_mcp_server',
  ];

  /**
   * Read tools: open to any client (no extra permission).
   */
  private const READ_TOOLS = [
    'list_datasets', 'get_dataset', 'list_distributions', 'get_distribution',
    'list_schemas', 'get_catalog', 'get_schema', 'get_data_dictionary',
    'get_dataset_info', 'query_datastore', 'query_datastore_join',
    'get_datastore_schema', 'search_columns', 'get_datastore_stats',
    'get_import_status', 'search_datasets', 'list_harvest_plans',
    'get_harvest_plan', 'get_harvest_runs', 'get_harvest_run_result',
    'resolve_resource', 'get_site_status', 'get_queue_status',
    'sample_rows', 'distinct_values',
  ];

  /**
   * Write tools: gated behind a fine-grained permission.
   */
  private const WRITE_TOOLS = [
    'import_resource', 'update_dataset', 'patch_dataset', 'delete_dataset',
    'publish_dataset', 'unpublish_dataset', 'post_metastore_item',
    'patch_metastore_item', 'delete_metastore_item', 'drop_datastore',
    'register_harvest', 'run_harvest', 'deregister_harvest',
  ];

  /**
   * All 38 tools discover, instantiate via DI, and default to enabled.
   */
  public function testToolsDiscoverInstantiateAndEnabled(): void {
    $definitions = $this->manager()->getDefinitions();
    $expected = array_merge(self::READ_TOOLS, self::WRITE_TOOLS);
    // Guard the fixture itself: 38 unique ids.
    $this->assertCount(38, $expected);
    $this->assertSame($expected, array_values(array_unique($expected)));

    foreach ($expected as $id) {
      $this->assertArrayHasKey($id, $definitions, "Tool '$id' was not discovered.");
      $plugin = $this->manager()->createInstance($id);
      $this->assertInstanceOf(ToolPluginInterface::class, $plugin, "Tool '$id' is not a tool plugin.");
      $this->assertTrue($plugin->isEnabled(), "Tool '$id' is not enabled by default.");
    }
  }

  /**
   * The module contributes exactly these 38 tools and no others.
   */
  public function testModuleContributesExactlyThirtyEight(): void {
    $ours = array_filter(
      $this->manager()->getDefinitions(),
      static fn ($definition): bool => $definition->getProvider() === 'dkan_mcp_server',
    );
    $this->assertCount(38, $ours);
    $expected = array_merge(self::READ_TOOLS, self::WRITE_TOOLS);
    sort($expected);
    $actual = array_keys($ours);
    sort($actual);
    $this->assertSame($expected, $actual);
  }

  /**
   * The access matrix resolves through real plugins for the anonymous user.
   */
  public function testAnonymousAccessMatrix(): void {
    $account = $this->container->get('current_user');
    foreach (self::READ_TOOLS as $id) {
      $this->assertTrue(
        $this->manager()->createInstance($id)->checkToolAccess($id, $account)->isAllowed(),
        "Read tool '$id' should be open to the anonymous user.",
      );
    }
    foreach (self::WRITE_TOOLS as $id) {
      $this->assertFalse(
        $this->manager()->createInstance($id)->checkToolAccess($id, $account)->isAllowed(),
        "Write tool '$id' must be denied to the anonymous user.",
      );
    }
  }

  /**
   * The MCP tool plugin manager.
   */
  private function manager(): ToolPluginManager {
    return $this->container->get('plugin.manager.mcp_server.tool');
  }

}
