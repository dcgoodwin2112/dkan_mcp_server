<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Tools;

use Drupal\dkan_harvest\Entity\HarvestRunRepository;
use Drupal\dkan_harvest\HarvestService;
use Drupal\dkan_mcp_server\Tools\HarvestTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
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

  /**
   * A plan with stock ETL classes and a public source URI is registered.
   */
  public function testRegisterHarvestAcceptsValidPlan(): void {
    $harvest = $this->createMock(HarvestService::class);
    // The plan must reach DKAN exactly once.
    $harvest->expects($this->once())->method('registerHarvest');

    $tools = new HarvestTools($harvest, new NullLogger());
    // 8.8.8.8 is a public IP literal: no DNS lookup, not private/reserved.
    $plan = json_encode([
      'identifier' => 'p1',
      'extract' => [
        'type' => '\\Drupal\\dkan_harvest\\ETL\\Extract\\DataJson',
        'uri' => 'http://8.8.8.8/data.json',
      ],
      'load' => ['type' => '\\Drupal\\dkan_harvest\\Load\\Dataset'],
      'transforms' => ['\\Drupal\\dkan_harvest\\Transform\\ResourceImporter'],
    ]);

    $result = $tools->registerHarvest($plan);
    $this->assertSame('success', $result['status']);
    $this->assertSame('p1', $result['plan_id']);
  }

  /**
   * Unsafe plans are rejected before reaching DKAN.
   *
   * Covers SSRF / local-file-read source URIs and ETL class names outside the
   * stock-DKAN allowlist (the arbitrary-class-instantiation vector).
   */
  #[DataProvider('unsafePlans')]
  public function testRegisterHarvestRejectsUnsafePlan(array $plan, string $fragment): void {
    $harvest = $this->createMock(HarvestService::class);
    // A rejected plan must never reach DKAN's registration.
    $harvest->expects($this->never())->method('registerHarvest');

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->registerHarvest(json_encode($plan));

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString($fragment, $result['error']);
  }

  /**
   * Running a harvest re-validates the stored plan and refuses an unsafe URI.
   */
  public function testRunHarvestRevalidatesStoredPlan(): void {
    $harvest = $this->createMock(HarvestService::class);
    // A plan that resolved as public at registration now points at loopback.
    $harvest->method('getHarvestPlanObject')->willReturn((object) [
      'identifier' => 'p1',
      'extract' => (object) [
        'type' => '\\Drupal\\dkan_harvest\\ETL\\Extract\\DataJson',
        'uri' => 'http://127.0.0.1/data.json',
      ],
      'load' => (object) ['type' => '\\Drupal\\dkan_harvest\\Load\\Dataset'],
    ]);
    // The unsafe plan must never reach DKAN's run.
    $harvest->expects($this->never())->method('runHarvest');

    $tools = new HarvestTools($harvest, new NullLogger());
    $result = $tools->runHarvest('p1');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('non-public', $result['error']);
  }

  /**
   * Unsafe harvest plans: [plan array, expected error fragment].
   */
  public static function unsafePlans(): array {
    $extract = static fn(string $uri, string $type = '\\Drupal\\dkan_harvest\\ETL\\Extract\\DataJson'): array =>
      ['type' => $type, 'uri' => $uri];
    $load = ['type' => '\\Drupal\\dkan_harvest\\Load\\Dataset'];
    $plan = static fn(array $ex, array $ld, ?array $tr = NULL): array => array_filter([
      'identifier' => 'p1',
      'extract' => $ex,
      'load' => $ld,
      'transforms' => $tr,
    ], static fn($v): bool => $v !== NULL);

    return [
      'file scheme (local file read)' => [$plan($extract('file:///etc/passwd'), $load), 'scheme'],
      'loopback ip' => [$plan($extract('http://127.0.0.1/x'), $load), 'non-public'],
      'link-local metadata' => [$plan($extract('http://169.254.169.254/latest/meta-data/'), $load), 'non-public'],
      'private ip' => [$plan($extract('http://10.0.0.5/x'), $load), 'non-public'],
      'ipv6 loopback' => [$plan($extract('http://[::1]/x'), $load), 'non-public'],
      'ipv6 link-local' => [$plan($extract('http://[fe80::1]/x'), $load), 'non-public'],
      'ipv6 unique-local' => [$plan($extract('http://[fc00::1]/x'), $load), 'non-public'],
      'ipv6 zone id (encoded)' => [$plan($extract('http://[fe80::1%25eth0]/x'), $load), 'non-public'],
      'cgnat shared space' => [$plan($extract('http://100.64.1.1/x'), $load), 'non-public'],
      'localhost name' => [$plan($extract('http://localhost/x'), $load), 'non-public'],
      'relative uri (no scheme/host)' => [$plan($extract('/local.json'), $load), 'absolute http'],
      'disallowed extract class' => [$plan($extract('http://8.8.8.8/x', '\\Evil\\Extractor'), $load), 'extract.type'],
      'disallowed load class' => [$plan($extract('http://8.8.8.8/x'), ['type' => '\\Evil\\Loader']), 'load.type'],
      'disallowed transform class' => [$plan($extract('http://8.8.8.8/x'), $load, ['\\Evil\\Transform']), 'transforms'],
      // An associative array JSON-encodes to an object, which DKAN's Factory
      // would still iterate; it must be rejected as a non-array.
      'object-shaped transforms' => [
        $plan($extract('http://8.8.8.8/x'), $load, ['x' => '\\Evil\\Transform']),
        'must be an array',
      ],
      // Integer IPv4 notation: 2130706433 == 127.0.0.1. It does not resolve as
      // a DNS name here, so the fail-closed path rejects it.
      'integer ipv4 loopback' => [
        $plan($extract('http://2130706433/x'), $load),
        'non-public',
      ],
    ];
  }

}
