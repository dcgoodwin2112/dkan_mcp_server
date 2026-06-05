<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Plugin\Tool;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\Plugin\Tool\GetCatalogTool;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Mcp\Server\ClientGateway;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for get_catalog result caching.
 *
 * Covers the caching this tool owns: a miss computes the catalog and stores it
 * permanently under the metastore list tag; a hit returns the cached payload
 * without recomputing. End-to-end tag invalidation is covered by the kernel
 * test (this asserts only the get/set wiring).
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class GetCatalogToolTest extends TestCase {

  /**
   * The shared cache id for the catalog payload.
   */
  private const CID = 'dkan_mcp_server:tool:get_catalog';

  /**
   * Builds the tool with the given metastore and cache doubles.
   */
  private function tool(MetastoreTools $metastore, CacheBackendInterface $cache): GetCatalogTool {
    return new GetCatalogTool(
      [],
      'get_catalog',
      [],
      $this->createMock(AccountProxyInterface::class),
      $metastore,
      $cache,
    );
  }

  /**
   * A miss computes the catalog and stores it permanently with the list tag.
   */
  public function testMissComputesAndCaches(): void {
    $catalog = ['catalog' => ['dataset' => [['title' => 'A']]]];

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->once())->method('getCatalog')->willReturn($catalog);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->with(self::CID)->willReturn(FALSE);
    $cache->expects($this->once())->method('set')->with(
      self::CID,
      $catalog,
      CacheBackendInterface::CACHE_PERMANENT,
      ['node_list:data'],
    );

    $result = $this->tool($metastore, $cache)->execute([], $this->createMock(ClientGateway::class));

    $this->assertSame($catalog, $result);
  }

  /**
   * A hit returns the stored payload and never recomputes the catalog.
   */
  public function testHitReturnsCachedWithoutRecomputing(): void {
    $cached = ['catalog' => ['dataset' => [['title' => 'cached']]]];

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->never())->method('getCatalog');

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) ['data' => $cached]);
    $cache->expects($this->never())->method('set');

    $result = $this->tool($metastore, $cache)->execute([], $this->createMock(ClientGateway::class));

    $this->assertSame($cached, $result);
  }

}
