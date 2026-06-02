<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Tools;

use Drupal\dkan_harvest\Entity\HarvestRunRepository;
use Drupal\dkan_harvest\HarvestService;
use Drupal\dkan_mcp_server\Tools\HarvestTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests HarvestTools run-listing.
 *
 * Mocks the real HarvestService/HarvestRunRepository (not stubs) so the run
 * list is built through methods that actually exist. The original bug called
 * HarvestService::getAllHarvestRunInfo(), which does not exist and threw at
 * runtime; mocking the real class makes that class of mistake impossible to
 * fake in a test.
 */
final class HarvestToolsTest extends TestCase {

  /**
   * Build a HarvestService mock with a run repository attached.
   */
  private function harvestWithRuns(array $runs): HarvestService {
    $repo = $this->createMock(HarvestRunRepository::class);
    $repo->method('retrieveAllRunsJson')->willReturn($runs);
    $harvest = $this->createMock(HarvestService::class);
    // runRepository is a public property on the real service.
    $harvest->runRepository = $repo;
    $harvest->method('getHarvestPlanObject')->willReturn((object) ['identifier' => 'plan_a']);
    return $harvest;
  }

  /**
   * The methods the fix relies on exist; the buggy one never did.
   */
  public function testServiceContract(): void {
    $this->assertTrue(method_exists(HarvestRunRepository::class, 'retrieveAllRunsJson'));
    $this->assertFalse(method_exists(HarvestService::class, 'getAllHarvestRunInfo'));
  }

  /**
   * Lists runs, strips the embedded plan, and surfaces the run ID key.
   */
  public function testGetHarvestRuns(): void {
    $runJson = json_encode([
      'status' => ['extract' => 'SUCCESS'],
      'identifier' => '1700000000',
      'plan' => json_encode(['identifier' => 'plan_a']),
    ]);
    $harvest = $this->harvestWithRuns([1 => $runJson]);

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->getHarvestRuns('plan_a');

    $this->assertCount(1, $result['runs']);
    $this->assertEquals(1, $result['total']);
    $this->assertEquals('1700000000', $result['runs'][0]['identifier']);
    $this->assertEquals('1', $result['runs'][0]['run_id']);
    // Plan config is stripped to reduce token waste.
    $this->assertArrayNotHasKey('plan', $result['runs'][0]);
    // Runs are numerically indexed.
    $this->assertArrayHasKey(0, $result['runs']);
  }

  /**
   * Multiple runs keep their repository keys as run IDs, newest first.
   */
  public function testGetHarvestRunsMultiple(): void {
    $run = fn(string $id) => json_encode([
      'status' => ['extract' => 'SUCCESS'],
      'identifier' => $id,
    ]);
    $harvest = $this->harvestWithRuns([
      3 => $run('1700000300'),
      2 => $run('1700000200'),
    ]);

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->getHarvestRuns('plan_a');

    $this->assertEquals(2, $result['total']);
    $this->assertEquals('3', $result['runs'][0]['run_id']);
    $this->assertEquals('2', $result['runs'][1]['run_id']);
  }

  /**
   * No runs yields an empty, well-formed payload.
   */
  public function testGetHarvestRunsEmpty(): void {
    $harvest = $this->harvestWithRuns([]);

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->getHarvestRuns('plan_a');

    $this->assertSame([], $result['runs']);
    $this->assertEquals(0, $result['total']);
  }

  /**
   * An unknown plan returns an error without touching the repository.
   */
  public function testGetHarvestRunsInvalidPlan(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn(NULL);

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->getHarvestRuns('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Harvest plan not found', $result['error']);
  }

}
