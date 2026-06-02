<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Tools;

use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_metastore\MetastoreService;
use Drupal\dkan_mcp_server\Tools\WriteTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The generic metastore write tools must refuse the dataset schema.
 *
 * Datasets carry dedicated, fine-grained permissions (edit/delete datasets via
 * mcp). Without this guard a caller holding only "manage metastore items via
 * mcp" could create, patch, or delete datasets through the generic metastore
 * tools, bypassing that model. The guard is enforced at the service layer, so
 * the metastore service must never be touched for schemaId "dataset".
 */
final class WriteToolsDatasetGuardTest extends TestCase {

  /**
   * Build WriteTools whose metastore service must never be called.
   */
  private function writeTools(): WriteTools {
    // The metastore service must never be invoked for the dataset schema.
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('post');
    $metastore->expects($this->never())->method('patch');
    $metastore->expects($this->never())->method('delete');

    return new WriteTools(
      $metastore,
      $this->createMock(DatastoreService::class),
      new NullLogger(),
    );
  }

  /**
   * The post_metastore_item tool refuses schemaId "dataset".
   */
  public function testPostRejectsDatasetSchema(): void {
    $result = $this->writeTools()->postMetastoreItem('dataset', '{"title":"x"}');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('dataset-specific MCP tools', $result['error']);
  }

  /**
   * The patch_metastore_item tool refuses schemaId "dataset".
   */
  public function testPatchRejectsDatasetSchema(): void {
    $result = $this->writeTools()->patchMetastoreItem('dataset', 'uuid-1', '{"title":"x"}');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('dataset-specific MCP tools', $result['error']);
  }

  /**
   * The delete_metastore_item tool refuses schemaId "dataset".
   */
  public function testDeleteRejectsDatasetSchema(): void {
    $result = $this->writeTools()->deleteMetastoreItem('dataset', 'uuid-1');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('dataset-specific MCP tools', $result['error']);
  }

  /**
   * Non-dataset schemas still pass through to the metastore service.
   */
  public function testNonDatasetSchemaIsNotBlocked(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('delete')
      ->with('distribution', 'dist-1');

    $tools = new WriteTools($metastore, $this->createMock(DatastoreService::class), new NullLogger());
    $result = $tools->deleteMetastoreItem('distribution', 'dist-1');

    $this->assertSame('success', $result['status']);
  }

}
