<?php

namespace Drupal\Tests\dkan_query_tools\Unit\Tool;

use Drupal\dkan_common\DatasetInfo;
use Drupal\dkan_common\Storage\DatabaseTableInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_datastore\Service\Info\ImportInfo;
use Drupal\dkan_datastore\Service\Query;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_metastore\MetastoreService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RootedData\RootedJsonData;

/**
 * Tests the DatastoreTools query, schema, stats, and sampling methods.
 */
class DatastoreToolsTest extends TestCase {

  /**
   * Builds a DatastoreTools instance with optional mocked dependencies.
   */
  protected function createTools(
    ?DatastoreService $datastore = NULL,
    ?Query $query = NULL,
    ?MetastoreService $metastore = NULL,
    ?DatasetInfo $datasetInfo = NULL,
    ?Connection $database = NULL,
    ?ImportInfo $importInfo = NULL,
  ): DatastoreTools {
    $datastore = $datastore ?? $this->createMock(DatastoreService::class);
    $query = $query ?? $this->createMock(Query::class);
    $metastore = $metastore ?? $this->createMock(MetastoreService::class);
    $datasetInfo = $datasetInfo ?? $this->createMock(DatasetInfo::class);
    $database = $database ?? $this->createMock(Connection::class);
    return new DatastoreTools($datastore, $query, $metastore, $datasetInfo, $database, new NullLogger(), $importInfo);
  }

  /**
   * Build an ImportInfo mock whose getItem returns the given stage statuses.
   */
  private function importInfoReturning(string $importerStatus, string $fileFetcherStatus = 'done', ?string $importerError = NULL): ImportInfo {
    $importInfo = $this->createMock(ImportInfo::class);
    $importInfo->method('getItem')->willReturn((object) [
      'fileFetcherStatus' => $fileFetcherStatus,
      'importerStatus' => $importerStatus,
      'importerError' => $importerError,
    ]);
    return $importInfo;
  }

  /**
   * Tests a basic query returns results with normalized count fields.
   */
  public function testQueryDatastoreBasic(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['name' => 'Alice', 'age' => '30']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test-resource');

    $this->assertArrayHasKey('results', $result);
    $this->assertCount(1, $result['results']);
    $this->assertEquals(1, $result['result_count']);
    $this->assertEquals(1, $result['total_rows']);
    $this->assertArrayNotHasKey('schema', $result);
    $this->assertArrayNotHasKey('count', $result);
  }

  /**
   * Tests a query with column, condition, sort, and limit arguments.
   */
  public function testQueryDatastoreWithFilters(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $conditions = json_encode([['property' => 'state', 'value' => 'CA', 'operator' => '=']]);
    $result = $tools->queryDatastore('test', 'state', $conditions, 'state', 'asc', 50, 0);

    $this->assertArrayHasKey('results', $result);
    $this->assertEquals(50, $result['limit']);
  }

  /**
   * Tests an oversized limit is clamped to the 500-row maximum.
   */
  public function testQueryDatastoreClampLimit(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test', limit: 9999);
    $this->assertEquals(500, $result['limit']);
  }

  /**
   * Tests an oversized offset is clamped to the deep-pagination ceiling.
   */
  public function testQueryDatastoreClampOffset(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(new RootedJsonData('{"results":[],"count":0}'));

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test', offset: 99999999);
    $this->assertSame(100000, $result['offset']);
  }

  /**
   * Tests an oversized column list is rejected before query assembly.
   */
  public function testQueryDatastoreRejectsTooManyColumns(): void {
    $tools = $this->createTools();
    $columns = implode(',', array_map(static fn(int $i): string => "c$i", range(1, 201)));
    $result = $tools->queryDatastore('test', columns: $columns);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Too many columns', $result['error']);
  }

  /**
   * Tests an oversized condition list is rejected before query assembly.
   */
  public function testQueryDatastoreRejectsTooManyConditions(): void {
    $tools = $this->createTools();
    $conditions = json_encode(array_map(
      static fn(int $i): array => ['property' => 'c', 'value' => $i, 'operator' => '='],
      range(1, 101),
    ));
    $result = $tools->queryDatastore('test', conditions: $conditions);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Too many conditions', $result['error']);
  }

  /**
   * Tests an oversized expression list is rejected (single-resource query).
   */
  public function testQueryDatastoreRejectsTooManyExpressions(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn(['fields' => ['c' => ['type' => 'int']]]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);
    $expressions = json_encode(array_map(
      static fn(int $i): array => ['operator' => 'sum', 'operands' => ['c'], 'alias' => "a$i"],
      range(1, 201),
    ));
    $result = $tools->queryDatastore('test__1', expressions: $expressions);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Too many expressions', $result['error']);
  }

  /**
   * Tests an oversized expression list is rejected (join query).
   */
  public function testQueryDatastoreJoinRejectsTooManyExpressions(): void {
    $tools = $this->createTools();
    $expressions = json_encode(array_map(
      static fn(int $i): array => ['operator' => 'sum', 'operands' => ['c'], 'alias' => "a$i"],
      range(1, 201),
    ));
    $result = $tools->queryDatastoreJoin('a__1', 'b__1', 'c=c', expressions: $expressions);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Too many expressions', $result['error']);
  }

  /**
   * Tests a query exception is returned as a generic, non-leaking error.
   *
   * The raw exception message must not reach the client (it can carry SQL
   * fragments and the datastore table name); only a generic message plus the
   * resource id are returned.
   */
  public function testQueryDatastoreError(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willThrowException(
      new \Exception("SQLSTATE[42S02]: Base table or view not found: datastore_abc123def"),
    );

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('nonexistent');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('datastore query could not be completed', $result['error']);
    // The raw DB message and table identifier must not leak to the client.
    $this->assertStringNotContainsString('SQLSTATE', $result['error']);
    $this->assertStringNotContainsString('datastore_abc123def', $result['error']);
    $this->assertSame('nonexistent', $result['resource_id']);
  }

  /**
   * Tests a MySQL unknown-column error maps to an unknown_column payload.
   */
  public function testQueryDatastoreUnknownColumnMysqlError(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'varchar'],
        'rate' => ['type' => 'decimal'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willThrowException(new \Exception(
      "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rate_per_100k' in 'field list'",
    ));
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $result = $tools->queryDatastore('test__1');
    $this->assertSame('unknown_column', $result['error']);
    $this->assertSame('rate_per_100k', $result['column']);
    $this->assertEqualsCanonicalizing(['state', 'rate'], $result['available_columns']);
    $this->assertSame('test__1', $result['resource_id']);
  }

  /**
   * Tests a DKAN "Bad query property" error maps to unknown_column.
   */
  public function testQueryDatastoreUnknownColumnDkanQueryFactoryError(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn(['fields' => ['x' => ['type' => 'int']]]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willThrowException(new \Exception('Bad query property.'));
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $result = $tools->queryDatastore('test__1');
    $this->assertSame('unknown_column', $result['error']);
    $this->assertSame('(unknown)', $result['column']);
  }

  /**
   * Tests column, condition, and sort names are corrected to schema casing.
   */
  public function testQueryDatastoreCanonicalizesColumnCase(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'City' => ['type' => 'varchar'],
        'Violent_Crimes' => ['type' => 'int'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $tools->queryDatastore(
      'test__1',
      columns: 'city,violent_crimes',
      conditions: json_encode([['property' => 'CITY', 'value' => 'Houston', 'operator' => '=']]),
      sortField: 'VIOLENT_crimes',
    );
    $this->assertNotNull($captured);
    $decoded = json_decode($captured, TRUE);
    $this->assertSame(['City', 'Violent_Crimes'], $decoded['properties']);
    $this->assertSame('City', $decoded['conditions'][0]['property']);
    $this->assertSame('Violent_Crimes', $decoded['sorts'][0]['property']);
  }

  /**
   * Tests grouping column names are corrected to schema casing.
   */
  public function testQueryDatastoreCanonicalizesGroupings(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['record_number' => ['type' => 'serial'], 'State' => ['type' => 'varchar']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $tools->queryDatastore('test__1', groupings: 'state');
    $decoded = json_decode($captured, TRUE);
    $this->assertSame('State', $decoded['groupings'][0]['property']);
  }

  /**
   * Tests case correction is skipped when two columns differ only by case.
   */
  public function testQueryDatastoreLeavesAmbiguousCaseAlone(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    // Two columns differing only by case → ambiguous, no auto-correct.
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'Date' => ['type' => 'date'],
        'date' => ['type' => 'date'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $tools->queryDatastore('test__1', columns: 'DATE');
    $decoded = json_decode($captured, TRUE);
    $this->assertSame(['DATE'], $decoded['properties']);
  }

  /**
   * Tests distinctValues corrects a lowercase column to its schema casing.
   */
  public function testDistinctValuesCanonicalizesColumnCase(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'State' => ['type' => 'varchar'],
      ],
    ]);
    $storage->method('getTableName')->willReturn('datastore_test');
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['CA', 'TX']);
    $select = $this->createMock(SelectInterface::class);
    $select->method('addField')->willReturnSelf();
    $select->method('distinct')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $tools = $this->createTools(datastore: $datastore, database: $database);
    // Lowercase input on a "State"-cased column should auto-correct.
    $result = $tools->distinctValues('test__1', 'state');
    $this->assertArrayNotHasKey('error', $result);
    $this->assertSame('State', $result['column']);
  }

  /**
   * Tests getDatastoreStats corrects a lowercase column to schema casing.
   */
  public function testGetDatastoreStatsCanonicalizesColumnCase(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'State' => ['type' => 'varchar'],
        'Population' => ['type' => 'int'],
      ],
    ]);
    $storage->method('getTableName')->willReturn('datastore_test');
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'total_rows' => 50,
      'State__non_null' => 50,
      'State__distinct' => 50,
      'State__min' => 'AK',
      'State__max' => 'WY',
    ]);
    $select = $this->createMock(SelectInterface::class);
    $select->method('addExpression')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $tools = $this->createTools(datastore: $datastore, database: $database);
    // Lowercase input on a "State"-cased column should auto-correct, no error.
    $result = $tools->getDatastoreStats('test__1', 'state');
    $this->assertArrayNotHasKey('error', $result);
    $this->assertSame('State', $result['columns'][0]['name']);
  }

  /**
   * Tests sanity flags report zero_rows for an empty result set.
   */
  public function testSanityFlagsZeroRows(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(new RootedJsonData('{"results":[],"count":0}'));
    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test__1');
    $this->assertTrue($result['sanity_flags']['zero_rows']);
    $this->assertFalse($result['sanity_flags']['row_cap_hit']);
    $this->assertSame([], $result['sanity_flags']['all_null_columns']);
    $this->assertNull($result['sanity_flags']['coverage_warning']);
  }

  /**
   * Tests sanity flags report row_cap_hit when results fill the limit.
   */
  public function testSanityFlagsRowCapHit(): void {
    $rows = array_fill(0, 50, ['x' => '1']);
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(
      new RootedJsonData(json_encode(['results' => $rows, 'count' => 1234]))
    );
    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test__1', limit: 50);
    $this->assertTrue($result['sanity_flags']['row_cap_hit']);
    $this->assertFalse($result['sanity_flags']['zero_rows']);
  }

  /**
   * Tests sanity flags list columns that are null across all rows.
   */
  public function testSanityFlagsAllNullColumns(): void {
    $rows = [
      ['name' => 'Alice', 'middle' => NULL, 'age' => 30],
      ['name' => 'Bob', 'middle' => NULL, 'age' => 25],
    ];
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(
      new RootedJsonData(json_encode(['results' => $rows, 'count' => 2]))
    );
    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test__1');
    $this->assertSame(['middle'], $result['sanity_flags']['all_null_columns']);
  }

  /**
   * Tests a coverage warning is set for zero rows on a date-field filter.
   */
  public function testCoverageWarningWhenZeroRowsAndDateFilter(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['report_year' => ['type' => 'year']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(new RootedJsonData('{"results":[],"count":0}'));
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $conditions = json_encode([['property' => 'report_year', 'value' => '1492', 'operator' => '=']]);
    $result = $tools->queryDatastore('test__1', conditions: $conditions);
    $this->assertTrue($result['sanity_flags']['zero_rows']);
    $this->assertNotNull($result['sanity_flags']['coverage_warning']);
    $this->assertStringContainsString('report_year', $result['sanity_flags']['coverage_warning']);
  }

  /**
   * Tests no coverage warning is set for zero rows on a non-date filter.
   */
  public function testCoverageWarningSkippedForNonDateFilter(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['city' => ['type' => 'varchar']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn(new RootedJsonData('{"results":[],"count":0}'));
    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $conditions = json_encode([['property' => 'city', 'value' => 'Atlantis', 'operator' => '=']]);
    $result = $tools->queryDatastore('test__1', conditions: $conditions);
    $this->assertTrue($result['sanity_flags']['zero_rows']);
    $this->assertNull($result['sanity_flags']['coverage_warning']);
  }

  /**
   * Tests getDatastoreSchema returns columns and omits record_number.
   */
  public function testGetDatastoreSchema(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'name' => ['type' => 'varchar', 'description' => 'Full name'],
        'age' => ['type' => 'int'],
      ],
    ]);

    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getDatastoreSchema('test-resource');

    $this->assertArrayHasKey('columns', $result);
    $this->assertCount(2, $result['columns']);
    $this->assertEquals('name', $result['columns'][0]['name']);
    $this->assertEquals('varchar', $result['columns'][0]['type']);
    $this->assertEquals('Full name', $result['columns'][0]['description']);
    // Column without description should not have the key.
    $this->assertArrayNotHasKey('description', $result['columns'][1]);
  }

  /**
   * Tests getDatastoreSchema returns an error when storage lookup fails.
   */
  public function testGetDatastoreSchemaError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('Not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getDatastoreSchema('bad-id');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Helpers: build fixtures for dictionary-enrichment tests.
   */
  protected function buildDatasetWithDistribution(string $resId, string $version, ?string $describedBy): RootedJsonData {
    $dist = [
      '%Ref:downloadURL' => [['data' => ['identifier' => $resId, 'version' => $version]]],
      'title' => 'Sample',
    ];
    if ($describedBy !== NULL) {
      $dist['describedBy'] = $describedBy;
      $dist['describedByType'] = 'application/vnd.tableschema+json';
    }
    return new RootedJsonData(json_encode([
      'identifier' => 'dataset-' . $resId,
      'distribution' => [$dist],
    ]));
  }

  /**
   * Builds a data-dictionary RootedJsonData fixture with the given fields.
   */
  protected function buildDictionary(string $id, array $fields): RootedJsonData {
    return new RootedJsonData(json_encode([
      'identifier' => $id,
      'data' => [
        'title' => 'Test Dictionary',
        'fields' => $fields,
      ],
    ]));
  }

  /**
   * Builds a DatastoreService mock whose storage schema has the given fields.
   */
  protected function buildDatastoreMockWithFields(array $fields): DatastoreService {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn(['fields' => $fields]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    return $datastore;
  }

  /**
   * Tests schema columns are enriched with matching data-dictionary fields.
   */
  public function testGetDatastoreSchemaWithDictionary(): void {
    $resId = 'abc123';
    $version = 'v1';
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-uuid';

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->with('dataset', 0, 200)->willReturn([
      $this->buildDatasetWithDistribution($resId, $version, $url),
    ]);
    $metastore->method('get')->with('data-dictionary', 'dict-uuid')->willReturn(
      $this->buildDictionary('dict-uuid', [
        ['name' => 'name', 'type' => 'string', 'title' => 'Full Name', 'description' => "Person's full name"],
        ['name' => 'age', 'type' => 'integer'],
      ]),
    );

    $datastore = $this->buildDatastoreMockWithFields([
      'record_number' => ['type' => 'serial'],
      'name' => ['type' => 'varchar'],
      'age' => ['type' => 'int'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema($resId . '__' . $version);

    $this->assertSame('dict-uuid', $result['dictionary_identifier']);
    $this->assertSame($url, $result['dictionary_url']);
    $this->assertCount(2, $result['columns']);
    $name = $result['columns'][0];
    $this->assertSame('name', $name['name']);
    $this->assertSame('Full Name', $name['dictionary_title']);
    $this->assertSame("Person's full name", $name['dictionary_description']);
    $this->assertSame('string', $name['dictionary_type']);
    // DB-derived type stays untouched.
    $this->assertSame('varchar', $name['type']);
    $age = $result['columns'][1];
    $this->assertSame('age', $age['name']);
    $this->assertSame('integer', $age['dictionary_type']);
    $this->assertArrayNotHasKey('dictionary_title', $age);
    $this->assertArrayNotHasKey('dictionary_description', $age);
  }

  /**
   * Tests no dictionary fetch occurs when the distribution has no describedBy.
   */
  public function testGetDatastoreSchemaNoDictionary(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([
      $this->buildDatasetWithDistribution('abc', 'v1', NULL),
    ]);
    // get() must not be called when no describedBy is found.
    $metastore->expects($this->never())->method('get');

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('abc__v1');

    $this->assertArrayNotHasKey('dictionary_identifier', $result);
    $this->assertArrayNotHasKey('dictionary_url', $result);
    $this->assertSame('name', $result['columns'][0]['name']);
    $this->assertArrayNotHasKey('dictionary_title', $result['columns'][0]);
  }

  /**
   * Tests columns absent from the dictionary get no enrichment keys.
   */
  public function testGetDatastoreSchemaDictionaryMissingField(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-uuid';
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([
      $this->buildDatasetWithDistribution('abc', 'v1', $url),
    ]);
    // Dictionary documents 'name' but not 'unmapped'.
    $metastore->method('get')->willReturn(
      $this->buildDictionary('dict-uuid', [
        ['name' => 'name', 'type' => 'string', 'title' => 'Full Name'],
      ]),
    );

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
      'unmapped' => ['type' => 'int'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('abc__v1');

    $this->assertSame('Full Name', $result['columns'][0]['dictionary_title']);
    $this->assertSame('unmapped', $result['columns'][1]['name']);
    $this->assertArrayNotHasKey('dictionary_title', $result['columns'][1]);
    $this->assertArrayNotHasKey('dictionary_type', $result['columns'][1]);
  }

  /**
   * Tests schema degrades gracefully when the dictionary fetch throws.
   */
  public function testGetDatastoreSchemaDictionaryFetchFails(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-uuid';
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([
      $this->buildDatasetWithDistribution('abc', 'v1', $url),
    ]);
    $metastore->method('get')->willThrowException(new \Exception('Dictionary item not found'));

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('abc__v1');

    // Degrades silently to today's shape.
    $this->assertArrayNotHasKey('dictionary_identifier', $result);
    $this->assertArrayNotHasKey('dictionary_title', $result['columns'][0]);
    $this->assertSame('name', $result['columns'][0]['name']);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests the service-level flag disables dictionary enrichment entirely.
   */
  public function testGetDatastoreSchemaServiceFlagDisablesEnrichment(): void {
    $metastore = $this->createMock(MetastoreService::class);
    // Flag off → no metastore lookup at all.
    $metastore->expects($this->never())->method('getAll');
    $metastore->expects($this->never())->method('get');

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $tools->setDictionaryEnrichmentEnabled(FALSE);
    $result = $tools->getDatastoreSchema('abc__v1');

    $this->assertArrayNotHasKey('dictionary_identifier', $result);
    $this->assertArrayNotHasKey('dictionary_title', $result['columns'][0]);
  }

  /**
   * Tests re-enabling the flag restores enrichment after a disable.
   */
  public function testGetDatastoreSchemaServiceFlagReEnablement(): void {
    $url = 'https://site.example/api/1/metastore/schemas/data-dictionary/items/dict-uuid';
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([
      $this->buildDatasetWithDistribution('abc', 'v1', $url),
    ]);
    $metastore->method('get')->willReturn(
      $this->buildDictionary('dict-uuid', [
        ['name' => 'name', 'type' => 'string', 'title' => 'Name'],
      ]),
    );

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $tools->setDictionaryEnrichmentEnabled(FALSE);
    $tools->setDictionaryEnrichmentEnabled(TRUE);
    $result = $tools->getDatastoreSchema('abc__v1');

    // Re-enabled: dictionary fields appear, cache cleared on disable.
    $this->assertSame('dict-uuid', $result['dictionary_identifier']);
    $this->assertSame('Name', $result['columns'][0]['dictionary_title']);
  }

  /**
   * Tests the includeDictionary=FALSE argument skips dictionary lookups.
   */
  public function testGetDatastoreSchemaIncludeDictionaryFalse(): void {
    $metastore = $this->createMock(MetastoreService::class);
    // Neither getAll nor get may be called when opt-out is set.
    $metastore->expects($this->never())->method('getAll');
    $metastore->expects($this->never())->method('get');

    $datastore = $this->buildDatastoreMockWithFields([
      'name' => ['type' => 'varchar'],
    ]);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('abc__v1', includeDictionary: FALSE);

    $this->assertArrayNotHasKey('dictionary_identifier', $result);
    $this->assertSame('name', $result['columns'][0]['name']);
  }

  /**
   * Tests non-JSON conditions return an "Invalid conditions" error.
   */
  public function testQueryDatastoreInvalidConditions(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore('test-resource', conditions: 'not valid json');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid conditions', $result['error']);
  }

  /**
   * Tests a JSON object (not array) of conditions returns an error.
   */
  public function testQueryDatastoreConditionsObject(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore('test-resource', conditions: '{"property":"x","value":"y","operator":"="}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('must be a JSON array', $result['error']);
  }

  /**
   * Tests an empty condition operator returns a friendly guidance error.
   */
  public function testQueryDatastoreEmptyOperatorReturnsFriendlyError(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore(
      'test-resource',
      conditions: '[{"property":"population","value":"1000000","operator":""}]'
    );

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('property "population"', $result['error']);
    $this->assertStringContainsString('is empty', $result['error']);
    $this->assertStringContainsString('<', $result['error']);
    $this->assertStringContainsString('like', $result['error']);
  }

  /**
   * Tests an unrecognized condition operator returns a friendly error.
   */
  public function testQueryDatastoreUnrecognizedOperatorReturnsFriendlyError(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore(
      'test-resource',
      conditions: '[{"property":"city","value":"Houston","operator":"equals"}]'
    );

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('property "city"', $result['error']);
    $this->assertStringContainsString("'equals'", $result['error']);
    $this->assertStringContainsString('Operator must be one of', $result['error']);
  }

  /**
   * HTML-encoded operators get rescued by canonicalize before validate runs.
   *
   * Regression guard: validateOperators must NOT fire for &gt; / &lt; etc.
   * because canonicalizeOperators decodes them first.
   */
  public function testQueryDatastoreHtmlEncodedOperatorPassesValidation(): void {
    $tools = $this->createTools();
    // Valid query with encoded operator. We're not asserting on the
    // (mocked) result content — just that we don't get the friendly
    // operator-validation error.
    $result = $tools->queryDatastore(
      'test-resource',
      conditions: '[{"property":"city","value":"Houston","operator":"&gt;"}]'
    );
    if (isset($result['error'])) {
      $this->assertStringNotContainsString('Operator must be one of', $result['error']);
    }
    else {
      $this->assertArrayNotHasKey('error', $result);
    }
  }

  /**
   * Tests operator validation descends into nested condition groups.
   */
  public function testQueryDatastoreOperatorValidationWalksNestedGroups(): void {
    $tools = $this->createTools();
    $nested = json_encode([
      [
        'groupOperator' => 'or',
        'conditions' => [
          ['property' => 'state', 'value' => 'CA', 'operator' => '='],
          ['property' => 'population', 'value' => '5000', 'operator' => ''],
        ],
      ],
    ]);
    $result = $tools->queryDatastore('test-resource', conditions: $nested);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('property "population"', $result['error']);
    $this->assertStringContainsString('is empty', $result['error']);
  }

  /**
   * Tests getImportStatus reports done with row and column counts.
   */
  public function testGetImportStatus(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('summary')
      ->with('abc123__456')
      ->willReturn([
        'numOfRows' => 100,
        'numOfColumns' => 5,
      ]);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('abc123__456');

    $this->assertEquals('abc123__456', $result['resource_id']);
    $this->assertEquals('done', $result['status']);
    $this->assertEquals(100, $result['num_of_rows']);
    $this->assertEquals(5, $result['num_of_columns']);
  }

  /**
   * Tests getImportStatus handles an object summary as well as an array.
   */
  public function testGetImportStatusWithObject(): void {
    $summary = (object) ['numOfRows' => 50, 'numOfColumns' => 3];
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willReturn($summary);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('abc123__456');

    $this->assertEquals('done', $result['status']);
    $this->assertEquals(50, $result['num_of_rows']);
    $this->assertEquals(3, $result['num_of_columns']);
  }

  /**
   * Tests aggregation builds grouped properties and a grouping clause.
   */
  public function testQueryDatastoreWithAggregation(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA', 'total' => '500']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $this->assertArrayHasKey('properties', $queryJson);
      $this->assertArrayHasKey('groupings', $queryJson);

      // Grouped column auto-included first, then expression.
      $this->assertEquals('state', $queryJson['properties'][0]);
      $expressionProp = $queryJson['properties'][1];
      $this->assertEquals('sum', $expressionProp['expression']['operator']);
      $this->assertEquals(['amount'], $expressionProp['expression']['operands']);
      $this->assertEquals('total', $expressionProp['alias']);

      $this->assertEquals([['property' => 'state']], $queryJson['groupings']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore(
      'test-resource',
      expressions: '[{"operator":"sum","operands":["amount"],"alias":"total"}]',
      groupings: 'state',
    );

    $this->assertArrayHasKey('results', $result);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests an explicit grouped column is not duplicated by auto-inclusion.
   */
  public function testQueryDatastoreAggregationWithColumns(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA', 'total' => '500']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $properties = $queryJson['properties'];

      // 'state' from columns, not duplicated by groupings auto-include.
      $this->assertCount(2, $properties);
      $this->assertEquals('state', $properties[0]);
      $this->assertIsArray($properties[1]);
      $this->assertEquals('count', $properties[1]['expression']['operator']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore(
      'test-resource',
      columns: 'state',
      expressions: '[{"operator":"count","operands":["state"],"alias":"state_count"}]',
      groupings: 'state',
    );

    $this->assertArrayHasKey('results', $result);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests invalid, incomplete, and bad-operator expressions return errors.
   */
  public function testQueryDatastoreInvalidExpressions(): void {
    $tools = $this->createTools();

    // Non-JSON string.
    $result = $tools->queryDatastore('test', expressions: 'not json');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid expressions', $result['error']);

    // Missing required fields.
    $result = $tools->queryDatastore('test', expressions: '[{"operator":"sum"}]');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('string operator', $result['error']);

    // Invalid operator.
    $result = $tools->queryDatastore('test', expressions: '[{"operator":"invalid","operands":["col"],"alias":"a"}]');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid operator', $result['error']);
  }

  /**
   * Tests wrong-typed expression fields return errors instead of throwing.
   */
  public function testQueryDatastoreRejectsMalformedExpressionTypes(): void {
    $tools = $this->createTools();

    // Wrong-typed fields from decoded JSON must return a structured error, not
    // throw (e.g. count() on a scalar operands, or array access on a scalar).
    foreach ([
      '[{"operator":"sum","operands":"col","alias":"a"}]',
      '[{"operator":"sum","operands":5,"alias":"a"}]',
      '[{"operator":["sum"],"operands":["col"],"alias":"a"}]',
      '[{"operator":"sum","operands":["col"],"alias":["a"]}]',
      '["just a string"]',
      '[5]',
    ] as $expressions) {
      $result = $tools->queryDatastore('test', expressions: $expressions);
      $this->assertArrayHasKey('error', $result, "Expected error for expressions: $expressions");
      $this->assertStringContainsString('string operator', $result['error']);
    }
  }

  /**
   * Tests an expression alias colliding with a column or grouping errors.
   */
  public function testQueryDatastoreAliasConflict(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
        'city' => ['type' => 'text'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $tools = $this->createTools(datastore: $datastore);

    // Alias conflicts with a column name.
    $result = $tools->queryDatastore(
      'test__1',
      columns: 'state',
      expressions: '[{"operator":"count","operands":["city"],"alias":"state"}]',
      groupings: 'state',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);

    // Alias conflicts with a grouping (no columns specified).
    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"count","operands":["city"],"alias":"state"}]',
      groupings: 'state',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);
  }

  /**
   * Tests an alias conflicting with a schema column errors without columns set.
   */
  public function testQueryDatastoreAliasConflictWithSchemaColumn(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
        'city' => ['type' => 'text'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);

    // Alias "state" conflicts with schema column even without explicit
    // columns/groupings.
    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"count","operands":["city"],"alias":"state"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);
  }

  /**
   * Tests two expressions sharing the same alias are rejected.
   */
  public function testQueryDatastoreAliasConflictDuplicateAliases(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'city' => ['type' => 'text'],
        'amount' => ['type' => 'int'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);

    // Two expressions with the same alias — second should be rejected.
    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"count","operands":["city"],"alias":"total"},{"operator":"sum","operands":["amount"],"alias":"total"}]',
      groupings: 'city',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);
  }

  /**
   * Tests alias checking falls back gracefully when schema lookup fails.
   */
  public function testQueryDatastoreAliasSchemaLookupFailure(): void {
    // When schema lookup fails (resource not found), alias check falls back
    // to columns + groupings only — no error from the schema lookup itself.
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')
      ->willThrowException(new \Exception('Resource not found'));

    $queryResult = new RootedJsonData(json_encode([
      'results' => [],
      'count' => 0,
    ]));
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(datastore: $datastore, query: $queryService);

    // "novel_alias" doesn't conflict with anything — should proceed to
    // query execution (which also fails, but that's a different error path).
    // Since getStorage throws, getSchemaColumnNames returns [], so the alias
    // check passes. Then runQuery proceeds normally with our mock.
    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"count","operands":["city"],"alias":"novel_alias"}]',
    );
    // No alias conflict error — the query runs (or fails downstream).
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests record_number is allowed as an alias since it is not a schema column.
   */
  public function testQueryDatastoreAliasRecordNumber(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'city' => ['type' => 'text'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(datastore: $datastore, query: $queryService);

    // "record_number" is excluded from schema columns, so it's allowed as
    // an alias. This is acceptable — record_number is a synthetic column
    // not normally in results.
    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"count","operands":["city"],"alias":"record_number"}]',
    );
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests groupings alone auto-include the grouped columns as properties.
   */
  public function testQueryDatastoreGroupingsOnly(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA'], ['state' => 'TX']],
      'count' => 2,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $this->assertArrayHasKey('groupings', $queryJson);
      $this->assertEquals([['property' => 'state'], ['property' => 'year']], $queryJson['groupings']);
      // Grouped columns auto-included in properties.
      $this->assertArrayHasKey('properties', $queryJson);
      $this->assertContains('state', $queryJson['properties']);
      $this->assertContains('year', $queryJson['properties']);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore(
      'test-resource',
      groupings: 'state,year',
    );

    $this->assertArrayHasKey('results', $result);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a basic join builds two aliased resources and a join condition.
   */
  public function testQueryDatastoreJoinBasic(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA', 'asthma' => '10', 'smoking' => '15']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      // Two resources with correct aliases.
      $this->assertCount(2, $queryJson['resources']);
      $this->assertEquals('t', $queryJson['resources'][0]['alias']);
      $this->assertEquals('j', $queryJson['resources'][1]['alias']);

      // Join condition.
      $this->assertCount(1, $queryJson['joins']);
      $join = $queryJson['joins'][0];
      $this->assertEquals('j', $join['resource']);
      $this->assertEquals('t', $join['condition']['resource']);
      $this->assertEquals('state', $join['condition']['property']);
      $this->assertEquals(['resource' => 'j', 'property' => 'state'], $join['condition']['value']);

      // No properties when columns not specified.
      $this->assertArrayNotHasKey('properties', $queryJson);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'state=state');

    $this->assertArrayHasKey('results', $result);
    $this->assertCount(1, $result['results']);
    $this->assertEquals(1, $result['total_rows']);
  }

  /**
   * Tests qualified join columns map to resource-qualified properties.
   */
  public function testQueryDatastoreJoinWithColumns(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      $this->assertCount(2, $queryJson['properties']);
      $this->assertEquals(['resource' => 't', 'property' => 'state'], $queryJson['properties'][0]);
      $this->assertEquals(['resource' => 'j', 'property' => 'smoking_rate'], $queryJson['properties'][1]);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'state=state', 't.state,j.smoking_rate');

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests unqualified join columns default to the primary resource.
   */
  public function testQueryDatastoreJoinUnqualifiedColumns(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      // Unqualified columns default to primary resource "t".
      $this->assertEquals(['resource' => 't', 'property' => 'state'], $queryJson['properties'][0]);
      $this->assertEquals(['resource' => 't', 'property' => 'population'], $queryJson['properties'][1]);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'state=state', 'state,population');

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests join conditions pass through their resource, property, and value.
   */
  public function testQueryDatastoreJoinWithConditions(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      $this->assertCount(1, $queryJson['conditions']);
      $this->assertEquals('j', $queryJson['conditions'][0]['resource']);
      $this->assertEquals('year', $queryJson['conditions'][0]['property']);
      $this->assertEquals('2020', $queryJson['conditions'][0]['value']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $conditions = json_encode([['resource' => 'j', 'property' => 'year', 'value' => '2020', 'operator' => '=']]);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'state=state', conditions: $conditions);

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a JSON join_on with qualified sides builds the join condition.
   */
  public function testQueryDatastoreJoinJsonCondition(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      $join = $queryJson['joins'][0];
      $this->assertEquals('j', $join['resource']);
      $this->assertEquals('t', $join['condition']['resource']);
      $this->assertEquals('state_code', $join['condition']['property']);
      $this->assertEquals(['resource' => 'j', 'property' => 'abbreviation'], $join['condition']['value']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $joinOn = json_encode(['left' => 't.state_code', 'right' => 'j.abbreviation', 'operator' => '=']);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests an unqualified JSON right side defaults to the joined resource.
   */
  public function testQueryDatastoreJoinJsonUnqualifiedRightDefaultsToJoined(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $join = json_decode((string) $datastoreQuery, TRUE)['joins'][0];
      // An unqualified JSON right column must resolve to the joined resource
      // 'j' (a t->j join), not self-join the primary 't'.
      $this->assertSame('j', $join['resource']);
      $this->assertSame('t', $join['condition']['resource']);
      $this->assertSame('id', $join['condition']['property']);
      $this->assertSame(['resource' => 'j', 'property' => 'id'], $join['condition']['value']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $joinOn = json_encode(['left' => 'id', 'right' => 'id']);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a JSON join "like" operator is normalized to case-sensitive LIKE.
   */
  public function testQueryDatastoreJoinJsonOperatorPassedThrough(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) {
      $join = json_decode((string) $datastoreQuery, TRUE)['joins'][0];
      // The documented non-equality operator must reach the join condition,
      // and "like" must be normalized to DKAN's case-sensitive LIKE.
      $this->assertSame('LIKE', $join['condition']['operator']);
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(query: $queryService);
    $joinOn = json_encode(['left' => 't.name', 'right' => 'j.name', 'operator' => 'like']);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests invalid JSON join operators return an "Invalid join operator" error.
   */
  public function testQueryDatastoreJoinJsonInvalidOperatorReturnsError(): void {
    $tools = $this->createTools();
    foreach (['foo', 'DROP', '; --', ['>'], 5] as $op) {
      $joinOn = json_encode(['left' => 't.a', 'right' => 'j.b', 'operator' => $op]);
      $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);
      $this->assertArrayHasKey('error', $result, "Expected error for operator: " . json_encode($op));
      $this->assertStringContainsString('Invalid join operator', $result['error']);
    }
  }

  /**
   * Tests a "j.x=t.y" join still attaches the second resource, not the primary.
   */
  public function testQueryDatastoreJoinCrossQualifiedJoinsSecondResource(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) {
      $join = json_decode((string) $datastoreQuery, TRUE)['joins'][0];
      // "j.x=t.y": the join must still attach the second resource 'j', not
      // re-join the primary 't' under its own alias.
      $this->assertSame('j', $join['resource']);
      $this->assertSame('j', $join['condition']['resource']);
      $this->assertSame('x', $join['condition']['property']);
      $this->assertSame(['resource' => 't', 'property' => 'y'], $join['condition']['value']);
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'j.x=t.y');

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests malformed join_on strings return descriptive errors.
   */
  public function testQueryDatastoreJoinInvalidJoinOn(): void {
    $tools = $this->createTools();

    // No equals sign.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'invalid');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid join_on', $result['error']);

    // Empty sides.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', '=');
    $this->assertArrayHasKey('error', $result);

    // Invalid JSON.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', '{bad json}');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid JSON join_on', $result['error']);
  }

  /**
   * Tests non-string JSON join_on sides return an error, not a TypeError.
   */
  public function testQueryDatastoreJoinRejectsNonStringJsonSides(): void {
    $tools = $this->createTools();

    // left/right as a JSON array or number must return a structured error,
    // not throw an uncaught TypeError from parseQualifiedField(string).
    foreach ([
      '{"left":["a","b"],"right":"j.col"}',
      '{"left":5,"right":"j.col"}',
      '{"left":"t.col","right":{"x":1}}',
      '{"left":true,"right":"j.col"}',
    ] as $joinOn) {
      $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);
      $this->assertArrayHasKey('error', $result, "Expected error for join_on: $joinOn");
      $this->assertStringContainsString('Invalid JSON join_on', $result['error']);
    }
  }

  /**
   * Tests a qualified join sort field resolves to its resource and property.
   */
  public function testQueryDatastoreJoinSortWithAlias(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);

      $this->assertCount(1, $queryJson['sorts']);
      $this->assertEquals('j', $queryJson['sorts'][0]['resource']);
      $this->assertEquals('rate', $queryJson['sorts'][0]['property']);
      $this->assertEquals('desc', $queryJson['sorts'][0]['order']);

      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'state=state', sortField: 'j.rate', sortDirection: 'desc');

    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a join query exception is returned as a generic, non-leaking error.
   */
  public function testQueryDatastoreJoinError(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willThrowException(
      new \Exception('SQLSTATE[42S02]: table datastore_join_secret not found'),
    );

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('bad__1', 'bad__2', 'col=col');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('could not be completed', $result['error']);
    $this->assertStringNotContainsString('datastore_join_secret', $result['error']);
    $this->assertStringNotContainsString('SQLSTATE', $result['error']);
  }

  /**
   * Edge case: whitespace in simple join_on is trimmed correctly.
   */
  public function testQueryDatastoreJoinWhitespaceInJoinOn(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $join = $queryJson['joins'][0];
      $this->assertEquals('state', $join['condition']['property']);
      $this->assertEquals('state', $join['condition']['value']['property']);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', ' state = state ');
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Edge case: multi-dot column names split only on first dot.
   */
  public function testQueryDatastoreJoinMultiDotColumnNames(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      // "t.some.col" should parse as resource=t, property=some.col.
      $this->assertEquals(['resource' => 't', 'property' => 'some.col'], $queryJson['properties'][0]);
      $this->assertEquals(['resource' => 'j', 'property' => 'other.field'], $queryJson['properties'][1]);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'id=id', 't.some.col,j.other.field');
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Edge case: empty columns string treated as no columns.
   */
  public function testQueryDatastoreJoinEmptyColumnsString(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      // Empty string is falsy, so no properties key should be set.
      $this->assertArrayNotHasKey('properties', $queryJson);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'id=id', '');
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Edge case: non-array JSON conditions for join.
   */
  public function testQueryDatastoreJoinInvalidJsonConditions(): void {
    $tools = $this->createTools();

    // Object instead of array.
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      conditions: '{"property":"x","value":"y","operator":"="}',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('must be a JSON array', $result['error']);

    // Invalid JSON string.
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      conditions: 'not json at all',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('must be a JSON array', $result['error']);
  }

  /**
   * Edge case: limit clamping for join queries (0 becomes 1, 9999 becomes 500).
   */
  public function testQueryDatastoreJoinLimitClamping(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);

    // 0 should clamp to 1.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'id=id', limit: 0);
    $this->assertEquals(1, $result['limit']);

    // 9999 should clamp to 500.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'id=id', limit: 9999);
    $this->assertEquals(500, $result['limit']);

    // Negative should clamp to 1.
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'id=id', limit: -5);
    $this->assertEquals(1, $result['limit']);
  }

  /**
   * Edge case: qualified fields in simple join_on (e.g. "t.state=j.state").
   *
   * Simple format parses alias prefixes via parseQualifiedField(), so
   * "t.state=j.state" correctly resolves resource and property.
   */
  public function testQueryDatastoreJoinQualifiedFieldsInSimpleFormat(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $join = $queryJson['joins'][0];
      $this->assertEquals('t', $join['condition']['resource']);
      $this->assertEquals('state', $join['condition']['property']);
      $this->assertEquals('j', $join['condition']['value']['resource']);
      $this->assertEquals('state', $join['condition']['value']['property']);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 't.state=j.state');
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Edge case: empty string join_on returns error.
   */
  public function testQueryDatastoreJoinEmptyStringJoinOn(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', '');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid join_on', $result['error']);
  }

  /**
   * Edge case: JSON join_on missing 'right' field.
   */
  public function testQueryDatastoreJoinJsonMissingRight(): void {
    $tools = $this->createTools();
    $joinOn = json_encode(['left' => 't.col']);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('non-empty string "left" and "right"', $result['error']);
  }

  /**
   * Edge case: JSON join_on missing 'left' field.
   */
  public function testQueryDatastoreJoinJsonMissingLeft(): void {
    $tools = $this->createTools();
    $joinOn = json_encode(['right' => 'j.col']);
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', $joinOn);
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('non-empty string "left" and "right"', $result['error']);
  }

  /**
   * Edge case: join_on with only equals sign and whitespace ("  =  ").
   */
  public function testQueryDatastoreJoinOnlyEqualsWithWhitespace(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', '  =  ');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('both sides of "=" must be non-empty', $result['error']);
  }

  /**
   * Edge case: join_on with left side empty ("=col").
   */
  public function testQueryDatastoreJoinOnLeftEmpty(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', '=col');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('both sides of "=" must be non-empty', $result['error']);
  }

  /**
   * Edge case: join_on with right side empty ("col=").
   */
  public function testQueryDatastoreJoinOnRightEmpty(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastoreJoin('res1__1', 'res2__1', 'col=');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('both sides of "=" must be non-empty', $result['error']);
  }

  /**
   * Tests getImportStatus reports not_imported when the summary throws.
   */
  public function testGetImportStatusNotImported(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('nonexistent__123');

    $this->assertEquals('nonexistent__123', $result['resource_id']);
    $this->assertEquals('not_imported', $result['status']);
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Tests a zero-row imported table reports done, not pending.
   */
  public function testGetImportStatusZeroRowsReportsDone(): void {
    // A header-only CSV imports to a valid table with zero data rows. It must
    // report 'done' (table exists), not 'pending' forever.
    $summary = (object) ['numOfRows' => 0, 'numOfColumns' => 5];
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willReturn($summary);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('headeronly__1');

    $this->assertSame('done', $result['status']);
    $this->assertSame(0, $result['num_of_rows']);
    $this->assertSame(5, $result['num_of_columns']);
  }

  /**
   * Tests a negative offset is clamped to zero in the built query.
   */
  public function testQueryDatastoreClampsNegativeOffset(): void {
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(query: $queryService);
    $tools->queryDatastore('test__1', offset: -5);

    $decoded = json_decode($captured, TRUE);
    $this->assertSame(0, $decoded['offset'] ?? 0);
  }

  /**
   * Tests searchColumns clamps a non-positive limit to one match.
   */
  public function testSearchColumnsClampsNonPositiveLimit(): void {
    [$datasets, $gatherResults, $schemas] = array_values($this->getSearchColumnsFixtures());
    $tools = $this->createSearchColumnsTools($datasets, $gatherResults, $schemas);

    // Limit <= 0 must clamp to 1, not break on the first match with a >= 0
    // comparison; the call still returns a well-formed result with matches.
    $result = $tools->searchColumns('a', 'name', 0);

    $this->assertArrayNotHasKey('error', $result);
    $this->assertArrayHasKey('matches', $result);
    $this->assertLessThanOrEqual(1, count($result['matches']));
  }

  /**
   * Tests a queued deferred import with no table reports pending.
   */
  public function testGetImportStatusQueuedDeferredImportReportsPending(): void {
    // A deferred import: file fetched (localized), datastore import queued, no
    // table yet, importer still WAITING. Must be 'pending', not 'not_imported'.
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willThrowException(new \Exception('No datastore storage found'));

    $tools = $this->createTools(
      datastore: $datastore,
      importInfo: $this->importInfoReturning('waiting', 'done'),
    );
    $result = $tools->getImportStatus('queued__1');

    $this->assertSame('pending', $result['status']);
  }

  /**
   * Tests a re-import over a stale table reports pending, not done.
   */
  public function testGetImportStatusQueuedReimportDoesNotMaskAsDone(): void {
    // A re-import queued over an older table: importer WAITING, fetcher done,
    // a stale table exists. Must report 'pending', not 'done'.
    $summary = (object) ['numOfRows' => 10, 'numOfColumns' => 3];
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willReturn($summary);

    $tools = $this->createTools(
      datastore: $datastore,
      importInfo: $this->importInfoReturning('waiting', 'done'),
    );
    $result = $tools->getImportStatus('reimport__1');

    $this->assertSame('pending', $result['status']);
  }

  /**
   * Tests a DONE zero-row import via ImportInfo reports done.
   */
  public function testGetImportStatusViaImportInfoZeroRowsReportsDone(): void {
    // Production branch (ImportInfo present): a completed zero-row import is
    // 'done' on the importer's authoritative DONE state.
    $summary = (object) ['numOfRows' => 0, 'numOfColumns' => 5];
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willReturn($summary);

    $tools = $this->createTools(
      datastore: $datastore,
      importInfo: $this->importInfoReturning('done'),
    );
    $result = $tools->getImportStatus('headeronly__1');

    $this->assertSame('done', $result['status']);
    $this->assertSame(0, $result['num_of_rows']);
  }

  /**
   * Tests an importer error via ImportInfo reports error with its message.
   */
  public function testGetImportStatusViaImportInfoErrorReportsError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willThrowException(new \Exception('No datastore storage found'));

    $tools = $this->createTools(
      datastore: $datastore,
      importInfo: $this->importInfoReturning('error', 'done', 'chunk 3 failed'),
    );
    $result = $tools->getImportStatus('broken__1');

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString('chunk 3 failed', $result['error']);
  }

  /**
   * Tests both stages waiting with no table reports not_imported.
   */
  public function testGetImportStatusNeverImportedViaImportInfoReportsNotImported(): void {
    // Neither stage started (fetcher + importer WAITING) and no table: the
    // resource is genuinely un-queued.
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willThrowException(new \Exception('No datastore storage found'));

    $tools = $this->createTools(
      datastore: $datastore,
      importInfo: $this->importInfoReturning('waiting', 'waiting'),
    );
    $result = $tools->getImportStatus('untouched__1');

    $this->assertSame('not_imported', $result['status']);
  }

  /**
   * Helper to set up mocks for searchColumns tests.
   */
  protected function createSearchColumnsTools(
    array $datasets,
    array $gatherResults,
    array $schemas,
  ): DatastoreTools {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willReturn(count($datasets));
    $metastore->method('getAll')->willReturn(
      array_map(fn($d) => new RootedJsonData(json_encode($d)), $datasets),
    );

    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturnCallback(
      fn($uuid) => $gatherResults[$uuid] ?? [],
    );

    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturnCallback(
      function ($id, $version) use ($schemas) {
        $key = $id . '__' . $version;
        if (!isset($schemas[$key])) {
          throw new \Exception('Not found');
        }
        $storage = $this->createMock(DatabaseTableInterface::class);
        $storage->method('getSchema')->willReturn($schemas[$key]);
        return $storage;
      },
    );

    return $this->createTools(
      datastore: $datastore,
      metastore: $metastore,
      datasetInfo: $datasetInfo,
    );
  }

  /**
   * Default test fixtures for searchColumns.
   */
  protected function getSearchColumnsFixtures(): array {
    $datasets = [
      ['identifier' => 'uuid-1', 'title' => 'Asthma Data'],
      ['identifier' => 'uuid-2', 'title' => 'Gold Prices'],
    ];

    $gatherResults = [
      'uuid-1' => [
        'latest_revision' => [
          'distributions' => [
            [
              'resource_id' => 'res1',
              'resource_version' => '100',
              'importer_status' => 'done',
            ],
          ],
        ],
      ],
      'uuid-2' => [
        'latest_revision' => [
          'distributions' => [
            [
              'resource_id' => 'res2',
              'resource_version' => '200',
              'importer_status' => 'done',
            ],
          ],
        ],
      ],
    ];

    $schemas = [
      'res1__100' => [
        'fields' => [
          'record_number' => ['type' => 'serial'],
          'state' => ['type' => 'text', 'description' => 'State name'],
          'prevalence' => ['type' => 'numeric', 'description' => 'Asthma prevalence rate'],
        ],
      ],
      'res2__200' => [
        'fields' => [
          'record_number' => ['type' => 'serial'],
          'date' => ['type' => 'text', 'description' => 'Trading date'],
          'price' => ['type' => 'numeric', 'description' => 'Gold price in USD'],
        ],
      ],
    ];

    return [$datasets, $gatherResults, $schemas];
  }

  /**
   * Tests searchColumns matches a column by name across resources.
   */
  public function testSearchColumnsBasicNameMatch(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('state');

    $this->assertArrayNotHasKey('error', $result);
    $this->assertEquals(1, $result['total_matches']);
    $this->assertEquals(2, $result['resources_searched']);
    $this->assertEquals('state', $result['matches'][0]['column_name']);
    $this->assertEquals('Asthma Data', $result['matches'][0]['dataset_title']);
    $this->assertEquals('uuid-1', $result['matches'][0]['dataset_uuid']);
    $this->assertEquals('res1__100', $result['matches'][0]['resource_id']);
    // search_in defaults to "name", so matched_in is always "name".
    $this->assertEquals('name', $result['matches'][0]['matched_in']);
  }

  /**
   * Tests searchColumns matches against column descriptions.
   */
  public function testSearchColumnsDescriptionMatch(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('USD', 'description');

    $this->assertEquals(1, $result['total_matches']);
    $this->assertEquals('price', $result['matches'][0]['column_name']);
    $this->assertEquals('description', $result['matches'][0]['matched_in']);
  }

  /**
   * Tests searchColumns reports "both" when name and description match.
   */
  public function testSearchColumnsBothMatch(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    // "state" matches column name "state" and description "State name".
    $result = $tools->searchColumns('state', 'both');

    $this->assertEquals(1, $result['total_matches']);
    $this->assertEquals('both', $result['matches'][0]['matched_in']);
  }

  /**
   * Tests searchColumns returns no matches for an absent term.
   */
  public function testSearchColumnsNoMatches(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('nonexistent');

    $this->assertEmpty($result['matches']);
    $this->assertEquals(0, $result['total_matches']);
    $this->assertEquals(2, $result['resources_searched']);
  }

  /**
   * Tests searchColumns matching is case-insensitive.
   */
  public function testSearchColumnsCaseInsensitive(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('STATE');

    $this->assertEquals(1, $result['total_matches']);
    $this->assertEquals('state', $result['matches'][0]['column_name']);
  }

  /**
   * Tests searchColumns skips distributions that are not imported.
   */
  public function testSearchColumnsSkipsNonImported(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    // Change uuid-1 distribution to waiting status.
    $gather['uuid-1']['latest_revision']['distributions'][0]['importer_status'] = 'waiting';
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('state');

    // State column is in uuid-1 which is now skipped.
    $this->assertEquals(0, $result['total_matches']);
    $this->assertEquals(1, $result['resources_searched']);
  }

  /**
   * Tests searchColumns never matches the synthetic record_number column.
   */
  public function testSearchColumnsSkipsRecordNumber(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    $result = $tools->searchColumns('record');

    $this->assertEquals(0, $result['total_matches']);
  }

  /**
   * Tests searchColumns caps returned matches at the given limit.
   */
  public function testSearchColumnsLimit(): void {
    [$datasets, $gather, $schemas] = $this->getSearchColumnsFixtures();
    $tools = $this->createSearchColumnsTools($datasets, $gather, $schemas);

    // Search for "e" which matches multiple columns (prevalence, date, price).
    $result = $tools->searchColumns('e', 'name', 2);

    $this->assertCount(2, $result['matches']);
    $this->assertEquals(2, $result['total_matches']);
  }

  /**
   * Tests searchColumns rejects empty and whitespace-only search terms.
   */
  public function testSearchColumnsEmptySearchTerm(): void {
    $tools = $this->createTools();

    $result = $tools->searchColumns('');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('cannot be empty', $result['error']);

    // Whitespace-only also treated as empty.
    $result = $tools->searchColumns('   ');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Tests searchColumns rejects an invalid search_in argument.
   */
  public function testSearchColumnsInvalidSearchIn(): void {
    $tools = $this->createTools();

    $result = $tools->searchColumns('test', 'invalid');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid search_in', $result['error']);
  }

  /**
   * Tests searchColumns returns a generic, non-leaking error when it throws.
   */
  public function testSearchColumnsError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willThrowException(new \Exception('Service unavailable at /var/secret'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->searchColumns('test');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('column-search operation failed', $result['error']);
    $this->assertStringNotContainsString('/var/secret', $result['error']);
  }

  /**
   * Helper to create DatastoreTools with mocked DB for stats tests.
   */
  protected function createStatsTools(array $schemaFields, array $dbRow): DatastoreTools {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn(['fields' => $schemaFields]);
    $storage->method('getTableName')->willReturn('datastore_test123');

    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($dbRow);

    $selectQuery = $this->createMock(SelectInterface::class);
    $selectQuery->method('addExpression')->willReturnSelf();
    $selectQuery->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($selectQuery);

    return $this->createTools(datastore: $datastore, database: $database);
  }

  /**
   * Tests sampleRows returns rows with the record_number column stripped.
   */
  public function testSampleRowsReturnsRowsAndStripsRecordNumber(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getTableName')->willReturn('datastore_test');
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $rows = [
      ['record_number' => 1, 'state' => 'CA', 'value' => '10'],
      ['record_number' => 2, 'state' => 'TX', 'value' => '20'],
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $tools = $this->createTools(datastore: $datastore, database: $database);
    $result = $tools->sampleRows('abc__1', 2);

    $this->assertEquals('abc__1', $result['resource_id']);
    $this->assertEquals(2, $result['row_count']);
    $this->assertCount(2, $result['rows']);
    $this->assertArrayNotHasKey('record_number', $result['rows'][0]);
    $this->assertEquals('CA', $result['rows'][0]['state']);
  }

  /**
   * Tests sampleRows clamps the requested count to the 1-50 range.
   */
  public function testSampleRowsClampsN(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getTableName')->willReturn('datastore_test');
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn([]);

    $rangeArgs = [];
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnCallback(function ($start, $length) use (&$rangeArgs, $select) {
      $rangeArgs = [$start, $length];
      return $select;
    });
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $tools = $this->createTools(datastore: $datastore, database: $database);

    // Below floor — clamps to 1.
    $tools->sampleRows('abc__1', 0);
    $this->assertEquals([0, 1], $rangeArgs);

    // Above ceiling — clamps to 50.
    $tools->sampleRows('abc__1', 9999);
    $this->assertEquals([0, 50], $rangeArgs);
  }

  /**
   * Tests sampleRows returns a generic, non-leaking error for a bad resource.
   */
  public function testSampleRowsErrorOnUnknownResource(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('No such resource datastore_xyz'));
    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->sampleRows('bad__id');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('sample operation failed', $result['error']);
    $this->assertStringNotContainsString('datastore_xyz', $result['error']);
  }

  /**
   * Tests distinctValues returns values and flags truncation past the limit.
   */
  public function testDistinctValuesReturnsValuesAndDetectsTruncation(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
      ],
    ]);
    $storage->method('getTableName')->willReturn('datastore_test');
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    // Limit 3, return 4 (limit+1) to trigger truncation.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['AL', 'CA', 'NY', 'TX']);

    $select = $this->createMock(SelectInterface::class);
    $select->method('addField')->willReturnSelf();
    $select->method('distinct')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $tools = $this->createTools(datastore: $datastore, database: $database);
    $result = $tools->distinctValues('abc__1', 'state', 3);

    $this->assertEquals('state', $result['column']);
    $this->assertEquals(['AL', 'CA', 'NY'], $result['values']);
    $this->assertEquals(3, $result['value_count']);
    $this->assertTrue($result['truncated']);
  }

  /**
   * Tests distinctValues does not flag truncation when within the limit.
   */
  public function testDistinctValuesNotTruncatedWhenWithinLimit(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
      ],
    ]);
    $storage->method('getTableName')->willReturn('datastore_test');
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['AL', 'CA']);

    $select = $this->createMock(SelectInterface::class);
    $select->method('addField')->willReturnSelf();
    $select->method('distinct')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $tools = $this->createTools(datastore: $datastore, database: $database);
    $result = $tools->distinctValues('abc__1', 'state', 50);

    $this->assertFalse($result['truncated']);
    $this->assertEquals(2, $result['value_count']);
  }

  /**
   * Tests distinctValues returns an error for an unknown column.
   */
  public function testDistinctValuesUnknownColumn(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->distinctValues('abc__1', 'nonexistent');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Unknown column', $result['error']);
  }

  /**
   * Tests distinctValues rejects the record_number column.
   */
  public function testDistinctValuesRejectsRecordNumber(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['record_number' => ['type' => 'serial']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->distinctValues('abc__1', 'record_number');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Tests distinctValues excludes NULLs in SQL but keeps empty strings.
   */
  public function testDistinctValuesExcludesNullsViaSqlAndKeepsEmptyStrings(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
      ],
    ]);
    $storage->method('getTableName')->willReturn('datastore_test');
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    // NULLs are excluded in SQL (IS NOT NULL), so the rows returned are the
    // non-null distinct values; empty strings are real values and are kept.
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['CA', '', 'TX']);

    $select = $this->createMock(SelectInterface::class);
    $select->method('addField')->willReturnSelf();
    $select->method('distinct')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    // The NULL exclusion must happen at the query layer.
    $select->expects($this->once())
      ->method('isNotNull')
      ->with('t.state')
      ->willReturnSelf();

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($select);

    $tools = $this->createTools(datastore: $datastore, database: $database);
    $result = $tools->distinctValues('abc__1', 'state');

    $this->assertEquals(['CA', '', 'TX'], $result['values']);
    $this->assertSame(3, $result['value_count']);
  }

  /**
   * Tests getDatastoreStats returns per-column stats for all columns.
   */
  public function testGetDatastoreStatsAllColumns(): void {
    $tools = $this->createStatsTools(
      [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
        'amount' => ['type' => 'int'],
      ],
      [
        'total_rows' => 500,
        'state__non_null' => 498,
        'state__distinct' => 50,
        'state__min' => 'Alabama',
        'state__max' => 'Wyoming',
        'amount__non_null' => 500,
        'amount__distinct' => 100,
        'amount__min' => '10',
        'amount__max' => '9999',
      ],
    );

    $result = $tools->getDatastoreStats('abc__123');

    $this->assertEquals('abc__123', $result['resource_id']);
    $this->assertEquals(500, $result['total_rows']);
    $this->assertCount(2, $result['columns']);

    $state = $result['columns'][0];
    $this->assertEquals('state', $state['name']);
    $this->assertEquals('text', $state['type']);
    $this->assertEquals(2, $state['null_count']);
    $this->assertEquals(50, $state['distinct_count']);
    $this->assertEquals('Alabama', $state['min']);
    $this->assertEquals('Wyoming', $state['max']);

    $amount = $result['columns'][1];
    $this->assertEquals('amount', $amount['name']);
    $this->assertEquals(0, $amount['null_count']);
    $this->assertEquals(100, $amount['distinct_count']);
  }

  /**
   * Tests get_datastore_stats caps analyzed columns and flags truncation.
   *
   * Each analyzed column adds a COUNT(DISTINCT), so a wide table is a DoS
   * vector; the result is capped and the truncation is signalled.
   */
  public function testGetDatastoreStatsCapsColumns(): void {
    $fields = ['record_number' => ['type' => 'serial']];
    for ($i = 1; $i <= 60; $i++) {
      $fields["col$i"] = ['type' => 'text'];
    }
    $tools = $this->createStatsTools($fields, ['total_rows' => 10]);

    $result = $tools->getDatastoreStats('abc__123');

    $this->assertCount(50, $result['columns']);
    $this->assertTrue($result['columns_truncated']);
    $this->assertSame(50, $result['columns_analyzed']);
    $this->assertSame(60, $result['total_columns']);
  }

  /**
   * Tests getDatastoreStats limits output to the requested column.
   */
  public function testGetDatastoreStatsFilteredColumns(): void {
    $tools = $this->createStatsTools(
      [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
        'amount' => ['type' => 'int'],
      ],
      [
        'total_rows' => 500,
        'state__non_null' => 498,
        'state__distinct' => 50,
        'state__min' => 'Alabama',
        'state__max' => 'Wyoming',
      ],
    );

    $result = $tools->getDatastoreStats('abc__123', 'state');

    $this->assertCount(1, $result['columns']);
    $this->assertEquals('state', $result['columns'][0]['name']);
  }

  /**
   * Tests getDatastoreStats returns an error for an unknown column.
   */
  public function testGetDatastoreStatsUnknownColumn(): void {
    $tools = $this->createStatsTools(
      [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'text'],
      ],
      [],
    );

    $result = $tools->getDatastoreStats('abc__123', 'nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Unknown columns', $result['error']);
    $this->assertStringContainsString('nonexistent', $result['error']);
  }

  /**
   * Tests getDatastoreStats returns a generic, non-leaking error for a bad id.
   */
  public function testGetDatastoreStatsInvalidResource(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('Resource not found in datastore_priv'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getDatastoreStats('bad__id');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('statistics operation failed', $result['error']);
    $this->assertStringNotContainsString('datastore_priv', $result['error']);
  }

  /**
   * Tests an arithmetic expression builds an aliased operator property.
   */
  public function testQueryDatastoreArithmeticExpression(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['col1' => '10', 'col2' => '5', 'total' => '15']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $this->assertArrayHasKey('properties', $queryJson);
      $exprProp = $queryJson['properties'][0];
      $this->assertEquals('+', $exprProp['expression']['operator']);
      $this->assertEquals(['col1', 'col2'], $exprProp['expression']['operands']);
      $this->assertEquals('total', $exprProp['alias']);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore(
      'test-resource',
      expressions: '[{"operator":"+","operands":["col1","col2"],"alias":"total"}]',
    );

    $this->assertArrayHasKey('results', $result);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests all five arithmetic operators are accepted.
   */
  public function testQueryDatastoreAllArithmeticOperators(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);
    $tools = $this->createTools(query: $queryService);

    foreach (['+', '-', '*', '/', '%'] as $op) {
      $alias = 'result_' . ord($op);
      $expr = json_encode([['operator' => $op, 'operands' => ['a', 'b'], 'alias' => $alias]]);
      $result = $tools->queryDatastore('test-resource', expressions: $expr);
      $this->assertArrayNotHasKey('error', $result, "Operator '$op' should be accepted");
    }
  }

  /**
   * Tests arithmetic operators require exactly two operands.
   */
  public function testQueryDatastoreArithmeticRequiresTwoOperands(): void {
    $tools = $this->createTools();

    // 1 operand rejected.
    $result = $tools->queryDatastore(
      'test',
      expressions: '[{"operator":"+","operands":["col1"],"alias":"total"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('requires exactly 2 operands', $result['error']);

    // 3 operands rejected.
    $result = $tools->queryDatastore(
      'test',
      expressions: '[{"operator":"+","operands":["a","b","c"],"alias":"total"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('requires exactly 2 operands', $result['error']);
  }

  /**
   * Tests aggregate operators require exactly one operand.
   */
  public function testQueryDatastoreAggregateRequiresOneOperand(): void {
    $tools = $this->createTools();

    $result = $tools->queryDatastore(
      'test',
      expressions: '[{"operator":"sum","operands":["a","b"],"alias":"total"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('requires exactly 1 operand', $result['error']);
  }

  /**
   * Tests mixing aggregate and arithmetic expressions is rejected.
   */
  public function testQueryDatastoreMixedOperatorsRejected(): void {
    $tools = $this->createTools();

    $result = $tools->queryDatastore(
      'test',
      expressions: '[{"operator":"sum","operands":["amount"],"alias":"total"},{"operator":"+","operands":["a","b"],"alias":"computed"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Cannot mix aggregate', $result['error']);
  }

  /**
   * Tests a nested arithmetic expression operand is accepted.
   */
  public function testQueryDatastoreNestedArithmeticExpression(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);
    $tools = $this->createTools(query: $queryService);

    // Nested expression operand — the inner operand is an expression object.
    $expr = json_encode([
      [
        'operator' => '+',
        'operands' => [
          'col1',
          ['operator' => '*', 'operands' => ['col2', 'col3']],
        ],
        'alias' => 'computed',
      ],
    ]);
    $result = $tools->queryDatastore('test-resource', expressions: $expr);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests an arithmetic expression alias conflicting with a column errors.
   */
  public function testQueryDatastoreArithmeticAliasConflict(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'col1' => ['type' => 'text'],
        'col2' => ['type' => 'text'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $tools = $this->createTools(datastore: $datastore);

    $result = $tools->queryDatastore(
      'test__1',
      expressions: '[{"operator":"+","operands":["col1","col2"],"alias":"col1"}]',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);
  }

  /**
   * Tests a join with an aggregate expression appends an aliased property.
   */
  public function testQueryDatastoreJoinWithExpressions(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $this->assertArrayHasKey('properties', $queryJson);
      $lastProp = end($queryJson['properties']);
      $this->assertEquals('sum', $lastProp['expression']['operator']);
      $this->assertEquals(['amount'], $lastProp['expression']['operands']);
      $this->assertEquals('total', $lastProp['alias']);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      expressions: '[{"operator":"sum","operands":["amount"],"alias":"total"}]',
      groupings: 't.state',
    );
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests join groupings become resource-qualified grouping objects.
   */
  public function testQueryDatastoreJoinWithGroupings(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      // Groupings should be qualified objects.
      $this->assertArrayHasKey('groupings', $queryJson);
      $this->assertEquals(['resource' => 't', 'property' => 'state'], $queryJson['groupings'][0]);
      $this->assertEquals(['resource' => 'j', 'property' => 'year'], $queryJson['groupings'][1]);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      groupings: 't.state,j.year',
    );
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a join with both expressions and groupings builds both clauses.
   */
  public function testQueryDatastoreJoinWithExpressionsAndGroupings(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      $this->assertArrayHasKey('groupings', $queryJson);
      $this->assertArrayHasKey('properties', $queryJson);
      // Should have grouped column + expression.
      $props = $queryJson['properties'];
      $this->assertGreaterThanOrEqual(2, count($props));
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      expressions: '[{"operator":"avg","operands":["amount"],"alias":"avg_amount"}]',
      groupings: 't.state',
    );
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests join grouped columns are auto-included as qualified properties.
   */
  public function testQueryDatastoreJoinGroupingsAutoInclude(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($datastoreQuery) use ($queryResult) {
      $queryJson = json_decode((string) $datastoreQuery, TRUE);
      // Grouped columns should be auto-included as qualified objects.
      $props = $queryJson['properties'];
      $this->assertEquals(['resource' => 't', 'property' => 'state'], $props[0]);
      return $queryResult;
    });

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      expressions: '[{"operator":"count","operands":["amount"],"alias":"cnt"}]',
      groupings: 't.state',
    );
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests a join expression alias conflicting with a grouping errors.
   */
  public function testQueryDatastoreJoinExpressionAliasConflict(): void {
    $tools = $this->createTools();

    // Alias conflicts with a grouping column name.
    $result = $tools->queryDatastoreJoin(
      'res1__1', 'res2__1', 'id=id',
      columns: 't.state',
      expressions: '[{"operator":"count","operands":["id"],"alias":"t.state"}]',
      groupings: 't.state',
    );
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('conflicts with a column', $result['error']);
  }

  /**
   * Tests HTML-encoded condition operators are decoded before querying.
   */
  public function testQueryDatastoreDecodesHtmlEntityOperators(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['record_number' => ['type' => 'serial'], 'rate' => ['type' => 'int']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    // Operators arriving HTML-encoded — gpt-5.4-mini does this for `>`/`<`.
    $tools->queryDatastore(
      'test__1',
      conditions: json_encode([
        ['property' => 'rate', 'value' => '380', 'operator' => '&gt;'],
        ['property' => 'rate', 'value' => '0', 'operator' => '&lt;='],
      ]),
    );
    $decoded = json_decode($captured, TRUE);
    $this->assertSame('>', $decoded['conditions'][0]['operator']);
    $this->assertSame('<=', $decoded['conditions'][1]['operator']);
  }

  /**
   * Tests HTML-encoded operators are decoded inside nested condition groups.
   */
  public function testQueryDatastoreDecodesOperatorsInNestedAndOrGroups(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['record_number' => ['type' => 'serial'], 'rate' => ['type' => 'int']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $tools->queryDatastore(
      'test__1',
      conditions: json_encode([
        [
          'groupOperator' => 'or',
          'conditions' => [
            ['property' => 'rate', 'value' => '100', 'operator' => '&lt;'],
            ['property' => 'rate', 'value' => '500', 'operator' => '&gt;='],
          ],
        ],
      ]),
    );
    $decoded = json_decode($captured, TRUE);
    $sub = $decoded['conditions'][0]['conditions'];
    $this->assertSame('<', $sub[0]['operator']);
    $this->assertSame('>=', $sub[1]['operator']);
  }

  /**
   * Tests plain operators and entity-bearing values are left unchanged.
   */
  public function testQueryDatastoreLeavesPlainOperatorsAndValuesUntouched(): void {
    // Decoding is idempotent on already-plain operators; values keep their
    // entities so we don't corrupt legitimate data.
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => ['record_number' => ['type' => 'serial'], 'name' => ['type' => 'varchar']],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);
    $captured = NULL;
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturnCallback(function ($q) use (&$captured) {
      $captured = (string) $q;
      return new RootedJsonData('{"results":[],"count":0}');
    });

    $tools = $this->createTools(datastore: $datastore, query: $queryService);
    $tools->queryDatastore(
      'test__1',
      conditions: json_encode([
        ['property' => 'name', 'value' => 'Smith &amp; Jones', 'operator' => '='],
      ]),
    );
    $decoded = json_decode($captured, TRUE);
    $this->assertSame('=', $decoded['conditions'][0]['operator']);
    $this->assertSame('Smith &amp; Jones', $decoded['conditions'][0]['value']);
  }

  /**
   * Tests a bare distribution UUID resolves to its resource id and version.
   */
  public function testResolvesBareDistributionUuid(): void {
    $distribution = new RootedJsonData(json_encode([
      'data' => [
        '%Ref:downloadURL' => [
          ['data' => ['identifier' => 'abc', 'version' => '5']],
        ],
      ],
    ]));
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('get')
      ->with('distribution', 'dist-uuid')
      ->willReturn($distribution);

    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'state' => ['type' => 'varchar'],
      ],
    ]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('getStorage')
      ->with('abc', '5')
      ->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('dist-uuid', FALSE);

    $this->assertSame('dist-uuid', $result['resource_id']);
    $this->assertCount(1, $result['columns']);
    $this->assertSame('state', $result['columns'][0]['name']);
  }

  /**
   * Tests a bare id falls back to direct storage when it is not a distribution.
   */
  public function testBareIdFallsBackWhenNotDistribution(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willThrowException(new \Exception('not found'));

    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn(['fields' => ['x' => ['type' => 'int']]]);
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('getStorage')
      ->with('bare-id', NULL)
      ->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore, metastore: $metastore);
    $result = $tools->getDatastoreSchema('bare-id', FALSE);
    $this->assertSame('bare-id', $result['resource_id']);
  }

}
