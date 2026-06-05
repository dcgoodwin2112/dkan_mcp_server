<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Tools;

use Drupal\dkan_common\DataResource;
use Drupal\dkan_common\DatasetInfo;
use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_metastore\MetastoreService;
use Drupal\dkan_metastore\ResourceMapper;
use Drupal\dkan_mcp_server\Tools\ResourceTools;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests ResourceTools resolveResource output redaction.
 *
 * The resolveResource method walks resource perspectives and previously
 * returned each perspective's raw getFilePath(). For the local_file perspective
 * that is an absolute on-disk path that leaks the server layout, so the tool
 * now reduces absolute paths to a basename while leaving public URLs intact.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class ResourceToolsTest extends TestCase {

  /**
   * Absolute file paths are reduced to a basename; URLs are kept.
   */
  public function testResolveResourceRedactsAbsoluteFilePaths(): void {
    $mapper = $this->createMock(ResourceMapper::class);
    $mapper->method('get')->willReturnCallback(
      function (string $identifier, string $perspective, string $version): ?DataResource {
        $resource = $this->createMock(DataResource::class);
        $resource->method('getMimeType')->willReturn('text/csv');
        if ($perspective === 'local_file') {
          $resource->method('getFilePath')
            ->willReturn('/var/www/html/sites/default/files/resources/secret.csv');
          return $resource;
        }
        if ($perspective === 'source') {
          $resource->method('getFilePath')
            ->willReturn('https://example.com/public/data.csv');
          return $resource;
        }
        return NULL;
      }
    );

    // No datastore table, no owning dataset, simple import status.
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('not imported'));
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([]);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datastoreTools = $this->createMock(DatastoreTools::class);
    $datastoreTools->method('getImportStatus')->willReturn(['status' => 'done']);

    $tools = new ResourceTools($metastore, $mapper, $datastore, $datasetInfo, $datastoreTools);
    $result = $tools->resolveResource('res__1');

    $byName = [];
    foreach ($result['perspectives'] as $perspective) {
      $byName[$perspective['perspective']] = $perspective['file_path'];
    }
    // Absolute path reduced to basename.
    $this->assertSame('secret.csv', $byName['local_file']);
    // Public URL preserved.
    $this->assertSame('https://example.com/public/data.csv', $byName['source']);
  }

  /**
   * A thrown error yields a generic, non-leaking payload.
   */
  public function testResolveResourceErrorDoesNotLeak(): void {
    $mapper = $this->createMock(ResourceMapper::class);
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('x'));
    $datasetInfo = $this->createMock(DatasetInfo::class);
    // getImportStatus is not wrapped in an inner try, so its exception reaches
    // resolveResource's outer catch.
    $datastoreTools = $this->createMock(DatastoreTools::class);
    $datastoreTools->method('getImportStatus')->willThrowException(
      new \Exception('boom at /var/www/html/secret/path.php'),
    );

    $tools = new ResourceTools($metastore, $mapper, $datastore, $datasetInfo, $datastoreTools);
    $result = $tools->resolveResource('res__1');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Failed to resolve resource', $result['error']);
    $this->assertStringNotContainsString('/var/www', $result['error']);
  }

}
