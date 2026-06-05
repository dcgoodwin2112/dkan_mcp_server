<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the get_catalog tool's permanent cache busts on a dataset write.
 *
 * GetCatalogToolTest asserts the tool tags its entry with node_list:data; this
 * proves the load-bearing integration claim that a real dataset write actually
 * invalidates that tag (codex review F4 — going beyond "the tag is attached").
 * DKAN stores datasets as 'data'-bundle nodes (dkan_metastore NodeData:
 * entityType 'node', bundle 'data'), and core invalidates node_list:data on any
 * save/delete of that bundle, so a 'data' node write reproduces the
 * invalidation a dataset create/update/publish/unpublish triggers. The cid and
 * tag mirror GetCatalogTool; no metastore data is created, keeping the test
 * deterministic.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
#[RunTestsInSeparateProcesses]
class GetCatalogCacheInvalidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node'];

  /**
   * Cache id and tag used by GetCatalogTool (kept in sync with that class).
   */
  private const CID = 'dkan_mcp_server:tool:get_catalog';
  private const TAG = 'node_list:data';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'data', 'name' => 'Data'])->save();
  }

  /**
   * A dataset write invalidates the permanently-cached catalog payload.
   */
  public function testDatasetWriteInvalidatesCachedCatalog(): void {
    $cache = $this->container->get('cache.default');
    $cache->set(self::CID, ['catalog' => ['dataset' => []]], CacheBackendInterface::CACHE_PERMANENT, [self::TAG]);
    $this->assertNotFalse($cache->get(self::CID), 'Catalog payload is cached.');

    // A dataset is a 'data'-bundle node; saving one invalidates node_list:data.
    Node::create(['type' => 'data', 'title' => 'A dataset'])->save();

    $this->assertFalse(
      $cache->get(self::CID),
      'A dataset write must invalidate the cached catalog via node_list:data.',
    );
  }

}
