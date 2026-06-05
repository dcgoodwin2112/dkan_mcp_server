<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Plugin\PromptArgumentCompletionProvider;

use Drupal\dkan_mcp_server\Plugin\PromptArgumentCompletionProvider\DkanDatasetIdCompletionProvider;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the dataset_id completion provider.
 *
 * Covers the completion logic this module owns: empty input lists catalog
 * identifiers, partial input matches by search and by identifier substring,
 * results are deduped and capped, and backing-service failures yield no
 * suggestions rather than an error.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class DkanDatasetIdCompletionProviderTest extends TestCase {

  /**
   * Builds the provider with stubbed metastore and search tools.
   */
  private function provider(MetastoreTools $metastore, SearchTools $search): DkanDatasetIdCompletionProvider {
    return new DkanDatasetIdCompletionProvider([], 'dkan_dataset_id', [], $metastore, $search);
  }

  /**
   * A metastore stub whose listDatasetIdentifiers returns the given ids.
   */
  private function metastoreListing(array $identifiers): MetastoreTools {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasetIdentifiers')->willReturn(['identifiers' => $identifiers]);
    return $metastore;
  }

  /**
   * A search stub whose searchDatasets returns the given identifiers.
   */
  private function searchReturning(array $identifiers): SearchTools {
    $rows = array_map(static fn (string $id): array => ['identifier' => $id], $identifiers);
    $search = $this->createMock(SearchTools::class);
    $search->method('searchDatasets')->willReturn(['results' => $rows]);
    return $search;
  }

  /**
   * Empty input lists the first page of catalog identifiers; no search.
   */
  public function testEmptyInputListsIdentifiers(): void {
    $search = $this->createMock(SearchTools::class);
    $search->expects($this->never())->method('searchDatasets');
    $provider = $this->provider($this->metastoreListing(['a', 'b', 'c']), $search);

    $this->assertSame(['a', 'b', 'c'], $provider->getCompletions('', []));
  }

  /**
   * Partial input merges search matches with identifier-substring matches.
   */
  public function testPartialInputMatchesSearchAndIdSubstring(): void {
    $provider = $this->provider(
      $this->metastoreListing(['foo-123', 'bar-456']),
      $this->searchReturning(['uuid-search-hit']),
    );

    $result = $provider->getCompletions('foo', []);

    // Search hit first, then the listing identifier containing "foo".
    $this->assertSame(['uuid-search-hit', 'foo-123'], $result);
    $this->assertNotContains('bar-456', $result);
  }

  /**
   * Duplicate identifiers across sources collapse to one.
   */
  public function testDeduplicates(): void {
    $provider = $this->provider(
      $this->metastoreListing(['dupe-1']),
      $this->searchReturning(['dupe-1']),
    );

    $this->assertSame(['dupe-1'], $provider->getCompletions('dupe', []));
  }

  /**
   * The configured limit caps the number of suggestions.
   */
  public function testLimitCaps(): void {
    $provider = $this->provider($this->metastoreListing(['a', 'b', 'c', 'd']), $this->createMock(SearchTools::class));

    $this->assertSame(['a', 'b'], $provider->getCompletions('', ['limit' => 2]));
  }

  /**
   * A backing-service exception yields an empty list, not an error.
   */
  public function testServiceFailureReturnsEmpty(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasetIdentifiers')->willThrowException(new \RuntimeException('boom'));
    $provider = $this->provider($metastore, $this->createMock(SearchTools::class));

    $this->assertSame([], $provider->getCompletions('', []));
  }

  /**
   * A search error payload (no results key) leaves only substring matches.
   */
  public function testSearchErrorPayloadFallsBackToSubstring(): void {
    $search = $this->createMock(SearchTools::class);
    $search->method('searchDatasets')->willReturn(['error' => 'Search failed']);
    $provider = $this->provider($this->metastoreListing(['health-1', 'other-2']), $search);

    $this->assertSame(['health-1'], $provider->getCompletions('health', []));
  }

}
