<?php

namespace Drupal\Tests\dkan_query_tools\Unit\Tool;

use Drupal\dkan_common\DatasetInfo;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_metastore\MetastoreService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;

/**
 * Tests the MetastoreTools query and shaping methods.
 *
 * @group dkan_query_tools
 */
#[Group('dkan_query_tools')]
class MetastoreToolsTest extends TestCase {

  /**
   * Builds a MetastoreTools instance with the given mocked dependencies.
   */
  protected function createTools(MetastoreService $metastore, ?DatasetInfo $datasetInfo = NULL): MetastoreTools {
    $datasetInfo = $datasetInfo ?? $this->createMock(DatasetInfo::class);
    return new MetastoreTools($metastore, $datasetInfo);
  }

  /**
   * Lists datasets and summarizes identifier, title, and distribution count.
   */
  public function testListDatasets(): void {
    $dataset1 = new RootedJsonData(json_encode([
      'identifier' => 'abc-123',
      'title' => 'Test Dataset',
      'description' => 'A test dataset',
      'distribution' => [['downloadURL' => 'http://example.com/data.csv']],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([$dataset1]);
    $metastore->method('count')->willReturn(1);

    $tools = $this->createTools($metastore);
    $result = $tools->listDatasets(0, 25);

    $this->assertArrayHasKey('datasets', $result);
    $this->assertArrayHasKey('total', $result);
    $this->assertEquals(1, $result['total']);
    $this->assertCount(1, $result['datasets']);
    $this->assertEquals('abc-123', $result['datasets'][0]['identifier']);
    $this->assertEquals('Test Dataset', $result['datasets'][0]['title']);
    $this->assertEquals(1, $result['datasets'][0]['distributions']);
  }

  /**
   * Clamps an oversized limit to the maximum of 100.
   */
  public function testListDatasetsClampLimit(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([]);
    $metastore->method('count')->willReturn(0);

    $tools = $this->createTools($metastore);
    $result = $tools->listDatasets(0, 999);
    $this->assertEquals(100, $result['limit']);
  }

  /**
   * Adjusts the total to the actual item count when all fit on one page.
   */
  public function testListDatasetsCountAdjustment(): void {
    $dataset1 = new RootedJsonData(json_encode([
      'identifier' => 'abc-123',
      'title' => 'Dataset 1',
      'distribution' => [],
    ]));
    $dataset2 = new RootedJsonData(json_encode([
      'identifier' => 'def-456',
      'title' => 'Dataset 2',
      'distribution' => [],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([$dataset1, $dataset2]);
    // count() returns more than actual valid items.
    $metastore->method('count')->willReturn(5);

    $tools = $this->createTools($metastore);
    $result = $tools->listDatasets(0, 25);

    // Total should be adjusted to actual item count since all fit in one page.
    $this->assertEquals(2, $result['total']);
  }

  /**
   * Returns a dataset and strips internal %Ref and %-prefixed keys.
   */
  public function testGetDataset(): void {
    $data = [
      'identifier' => 'abc-123',
      'title' => 'Test',
      'description' => 'Full description',
      '%Ref:keyword' => [['identifier' => 'kw-1', 'data' => 'health']],
      '%modified' => '2026-03-12T11:23:26-0400',
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->getDataset('abc-123');

    $this->assertArrayHasKey('dataset', $result);
    $this->assertEquals('abc-123', $result['dataset']['identifier']);
    $this->assertArrayNotHasKey('%Ref:keyword', $result['dataset']);
    $this->assertArrayNotHasKey('%modified', $result['dataset']);
  }

  /**
   * Returns an error payload when the dataset does not exist.
   */
  public function testGetDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willThrowException(new \Exception('Not found'));

    $tools = $this->createTools($metastore);
    $result = $tools->getDataset('nonexistent');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Lists a dataset's distributions with identifiers and media types.
   */
  public function testListDistributions(): void {
    $data = [
      'identifier' => 'abc-123',
      'distribution' => [
        [
          'title' => 'CSV File',
          'mediaType' => 'text/csv',
          'downloadURL' => 'http://example.com/data.csv',
        ],
      ],
      '%Ref:distribution' => [
        ['identifier' => 'dist-1'],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->listDistributions('abc-123');

    $this->assertCount(1, $result['distributions']);
    $this->assertEquals('dist-1', $result['distributions'][0]['identifier']);
    $this->assertNotNull($result['distributions'][0]['identifier']);
    $this->assertEquals('text/csv', $result['distributions'][0]['mediaType']);
  }

  /**
   * Exposes describedBy and describedByType when present on a distribution.
   */
  public function testListDistributionsExposesDescribedBy(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-1';
    $data = [
      'identifier' => 'abc-123',
      'distribution' => [
        [
          'title' => 'CSV File',
          'mediaType' => 'text/csv',
          'downloadURL' => 'http://example.com/data.csv',
          'describedBy' => $url,
          'describedByType' => 'application/vnd.tableschema+json',
        ],
      ],
      '%Ref:distribution' => [['identifier' => 'dist-1']],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->listDistributions('abc-123');

    $this->assertSame($url, $result['distributions'][0]['describedBy']);
    $this->assertSame('application/vnd.tableschema+json', $result['distributions'][0]['describedByType']);
  }

  /**
   * Omits describedBy keys when the distribution has none.
   */
  public function testListDistributionsOmitsDescribedByWhenAbsent(): void {
    $data = [
      'identifier' => 'abc-123',
      'distribution' => [['title' => 'CSV', 'mediaType' => 'text/csv', 'downloadURL' => 'http://x']],
      '%Ref:distribution' => [['identifier' => 'dist-1']],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->listDistributions('abc-123');

    $this->assertArrayNotHasKey('describedBy', $result['distributions'][0]);
    $this->assertArrayNotHasKey('describedByType', $result['distributions'][0]);
  }

  /**
   * Resolves data dictionaries from a dataset UUID via its describedBy link.
   */
  public function testGetDataDictionaryByDatasetUuid(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-1';
    $dataset = [
      'identifier' => 'abc-123',
      'distribution' => [
        [
          '%Ref:downloadURL' => [['data' => ['identifier' => 'res-1', 'version' => 'v1']]],
          'describedBy' => $url,
        ],
      ],
    ];
    $dictionary = [
      'identifier' => 'dict-1',
      'data' => [
        'title' => 'My Dictionary',
        'fields' => [['name' => 'col_a', 'type' => 'string', 'title' => 'Col A']],
      ],
    ];

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturnCallback(function ($schema, $id) use ($dataset, $dictionary) {
      if ($schema === 'dataset' && $id === 'abc-123') {
        return new RootedJsonData(json_encode($dataset));
      }
      if ($schema === 'data-dictionary' && $id === 'dict-1') {
        return new RootedJsonData(json_encode($dictionary));
      }
      throw new \Exception('not found');
    });

    $tools = $this->createTools($metastore);
    $result = $tools->getDataDictionary('abc-123');

    $this->assertArrayHasKey('dictionaries', $result);
    $key = 'res-1__v1';
    $this->assertArrayHasKey($key, $result['dictionaries']);
    $entry = $result['dictionaries'][$key];
    $this->assertSame('dict-1', $entry['identifier']);
    $this->assertSame($url, $entry['url']);
    $this->assertSame('My Dictionary', $entry['title']);
    $this->assertCount(1, $entry['fields']);
    $this->assertSame('col_a', $entry['fields'][0]['name']);
  }

  /**
   * Resolves a data dictionary from a resource id by scanning distributions.
   */
  public function testGetDataDictionaryByResourceId(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-2';
    $datasetList = [
      new RootedJsonData(json_encode([
        'distribution' => [
          [
            '%Ref:downloadURL' => [['data' => ['identifier' => 'res-1', 'version' => 'v1']]],
            'describedBy' => $url,
          ],
        ],
      ])),
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->with('dataset', 0, 200)->willReturn($datasetList);
    $metastore->method('get')->with('data-dictionary', 'dict-2')->willReturn(
      new RootedJsonData(json_encode([
        'identifier' => 'dict-2',
        'data' => ['title' => 'D2', 'fields' => [['name' => 'x', 'type' => 'integer']]],
      ])),
    );

    $tools = $this->createTools($metastore);
    $result = $tools->getDataDictionary('res-1__v1');

    $this->assertArrayHasKey('res-1__v1', $result['dictionaries']);
    $this->assertSame('dict-2', $result['dictionaries']['res-1__v1']['identifier']);
  }

  /**
   * Returns an error when the dataset has no linked data dictionary.
   */
  public function testGetDataDictionaryNotLinked(): void {
    $dataset = [
      'identifier' => 'abc-123',
      'distribution' => [['title' => 'CSV']],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($dataset)));

    $tools = $this->createTools($metastore);
    $result = $tools->getDataDictionary('abc-123');

    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Returns an error when no distribution matches the given resource id.
   */
  public function testGetDataDictionaryByResourceIdNoMatch(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([]);

    $tools = $this->createTools($metastore);
    $result = $tools->getDataDictionary('missing__v1');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('No distribution', $result['error']);
  }

  /**
   * Returns a distribution and strips the nested %Ref:downloadURL key.
   */
  public function testGetDistribution(): void {
    // Real DKAN structure: %Ref:downloadURL is nested inside 'data'.
    $data = [
      'identifier' => 'dist-1',
      'data' => [
        'downloadURL' => 'http://example.com/data.csv',
        'mediaType' => 'text/csv',
        '%Ref:downloadURL' => [
          ['identifier' => 'res-1', 'data' => ['url' => 'http://example.com']],
        ],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->getDistribution('dist-1');

    $this->assertArrayHasKey('distribution', $result);
    $this->assertEquals('dist-1', $result['distribution']['identifier']);
    $this->assertArrayNotHasKey('%Ref:downloadURL', $result['distribution']['data']);
    $this->assertEquals('http://example.com/data.csv', $result['distribution']['data']['downloadURL']);
  }

  /**
   * Returns the list of available metastore schema identifiers.
   */
  public function testListSchemas(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getSchemas')->willReturn([
      'dataset' => (object) [],
      'distribution' => (object) [],
      'keyword' => (object) [],
    ]);

    $tools = $this->createTools($metastore);
    $result = $tools->listSchemas();

    $this->assertEquals(['dataset', 'distribution', 'keyword'], $result['schemas']);
  }

  /**
   * Returns the catalog, truncating descriptions and stripping spatial data.
   */
  public function testGetCatalog(): void {
    $longDescription = str_repeat('A', 300);
    $catalog = (object) [
      '@type' => 'dcat:Catalog',
      'dataset' => [
        (object) [
          'title' => 'Test',
          'description' => $longDescription,
          'spatial' => (object) ['type' => 'Polygon', 'coordinates' => []],
        ],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getCatalog')->willReturn($catalog);

    $tools = $this->createTools($metastore);
    $result = $tools->getCatalog();

    $this->assertArrayHasKey('catalog', $result);
    $this->assertEquals('dcat:Catalog', $result['catalog']['@type']);
    // Description should be truncated to 200 chars.
    $this->assertEquals(200, mb_strlen($result['catalog']['dataset'][0]['description']));
    // Spatial should be stripped.
    $this->assertArrayNotHasKey('spatial', $result['catalog']['dataset'][0]);
  }

  /**
   * Returns dataset info but redacts internal IDs and absolute file paths.
   */
  public function testGetDatasetInfo(): void {
    $info = [
      'latest_revision' => [
        'uuid' => 'abc-123',
        'node_id' => 42,
        'revision_id' => 99,
        'title' => 'Test Dataset',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-1',
            'resource_id' => 'res-hash',
            'resource_version' => '1234567890',
            'source_path' => '/var/www/html/sites/default/files/uploaded/secret.csv',
          ],
          [
            'distribution_uuid' => 'dist-2',
            'resource_id' => 'res-hash-2',
            'source_path' => 'https://example.com/public/data.csv',
          ],
        ],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn($info);

    $tools = $this->createTools($metastore, $datasetInfo);
    $result = $tools->getDatasetInfo('abc-123');

    $revision = $result['dataset_info']['latest_revision'];
    // Public fields are preserved.
    $this->assertEquals('abc-123', $revision['uuid']);
    $this->assertEquals('res-hash', $revision['distributions'][0]['resource_id']);
    // Internal Drupal entity IDs are stripped.
    $this->assertArrayNotHasKey('node_id', $revision);
    $this->assertArrayNotHasKey('revision_id', $revision);
    // Absolute filesystem paths are reduced to a basename; URLs are kept.
    $this->assertSame('secret.csv', $revision['distributions'][0]['source_path']);
    $this->assertSame('https://example.com/public/data.csv', $revision['distributions'][1]['source_path']);
  }

  /**
   * Catches a thrown error and returns a generic, non-leaking payload.
   */
  public function testGetDatasetInfoCatchesError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willThrowException(
      new \TypeError('type error in /var/www/html/internal/path.php'),
    );

    $tools = $this->createTools($metastore, $datasetInfo);
    $result = $tools->getDatasetInfo('abc-123');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Failed to gather dataset info', $result['error']);
    $this->assertStringNotContainsString('/var/www', $result['error']);
  }

  /**
   * Returns an error naming the id when dataset info gathers only a notice.
   */
  public function testGetDatasetInfoNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn(['notice' => 'Not found']);

    $tools = $this->createTools($metastore, $datasetInfo);
    $result = $tools->getDatasetInfo('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Not found', $result['error']);
    $this->assertStringContainsString('nonexistent', $result['error']);
  }

  /**
   * Returns a named schema along with its id.
   */
  public function testGetSchema(): void {
    $schema = (object) [
      'type' => 'object',
      'properties' => (object) [
        'title' => (object) ['type' => 'string'],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getSchema')->with('dataset')->willReturn($schema);

    $tools = $this->createTools($metastore);
    $result = $tools->getSchema('dataset');

    $this->assertEquals('dataset', $result['schema_id']);
    $this->assertArrayHasKey('schema', $result);
    $this->assertEquals('object', $result['schema']['type']);
  }

  /**
   * Returns an error payload when the requested schema is not found.
   */
  public function testGetSchemaError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getSchema')
      ->willThrowException(new \Exception('Schema not found'));

    $tools = $this->createTools($metastore);
    $result = $tools->getSchema('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Schema not found', $result['error']);
  }

}
