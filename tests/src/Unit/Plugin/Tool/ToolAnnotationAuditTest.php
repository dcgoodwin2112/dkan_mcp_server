<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Plugin\Tool;

use Drupal\mcp_server\Attribute\Tool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the #[Tool] behavior-hint matrix against silent drift.
 *
 * Reads the readOnly/destructive/idempotent/openWorld arguments declared on
 * every tool plugin's #[Tool] attribute (the developer's source of truth) and
 * asserts the decided values, not mere internal consistency: a future edit that
 * flips a hint to the wrong value fails here. See docs/POLISH_PLAN.md (7a).
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class ToolAnnotationAuditTest extends TestCase {

  /**
   * The only tools that interact with systems outside the local catalog.
   */
  private const OPEN_WORLD = ['import_resource', 'run_harvest'];

  /**
   * Write tools whose repeated call has additional effect (not idempotent).
   */
  private const NON_IDEMPOTENT = [
    'import_resource',
    'post_metastore_item',
    'register_harvest',
    'run_harvest',
  ];

  /**
   * Removal tools, which are all destructive and idempotent by end-state.
   */
  private const REMOVALS = [
    'delete_dataset',
    'delete_metastore_item',
    'deregister_harvest',
    'drop_datastore',
  ];

  /**
   * Reads declare readOnly + non-destructive + idempotent + local.
   */
  public function testReadToolsAreReadOnlyIdempotentAndLocal(): void {
    foreach ($this->toolFlags() as $id => $flags) {
      if ($flags['readOnly'] !== TRUE) {
        continue;
      }
      $this->assertFalse($flags['destructive'], "$id: a read tool must be non-destructive.");
      $this->assertTrue($flags['idempotent'], "$id: a read tool must be idempotent.");
      $this->assertFalse($flags['openWorld'], "$id: a read tool must stay local.");
    }
  }

  /**
   * Only the two external-fetch tools set openWorld.
   */
  public function testOpenWorldIsExactlyTheExternalFetchTools(): void {
    $open = array_keys(array_filter(
      $this->toolFlags(),
      static fn (array $flags): bool => $flags['openWorld'] === TRUE,
    ));
    sort($open);
    $this->assertSame(self::OPEN_WORLD, $open);
  }

  /**
   * Every removal tool is destructive and idempotent.
   */
  public function testRemovalsAreDestructiveAndIdempotent(): void {
    $flags = $this->toolFlags();
    foreach (self::REMOVALS as $id) {
      $this->assertTrue($flags[$id]['destructive'], "$id must be destructive.");
      $this->assertTrue($flags[$id]['idempotent'], "$id must be idempotent.");
    }
  }

  /**
   * Non-idempotent tools are exactly the create/run/import writes.
   */
  public function testNonIdempotentSetIsExact(): void {
    $non = array_keys(array_filter(
      $this->toolFlags(),
      static fn (array $flags): bool => $flags['idempotent'] === FALSE,
    ));
    sort($non);
    $this->assertSame(self::NON_IDEMPOTENT, $non);
  }

  /**
   * Every tool declares all four hints as booleans (none dropped to default).
   */
  public function testEveryToolDeclaresAllFourHints(): void {
    $flags = $this->toolFlags();
    $this->assertNotEmpty($flags, 'No tool plugins were discovered.');
    foreach ($flags as $id => $tool) {
      foreach (['readOnly', 'destructive', 'idempotent', 'openWorld'] as $hint) {
        $this->assertIsBool($tool[$hint], "$id must declare a boolean '$hint'.");
      }
    }
  }

  /**
   * Reads each tool plugin's #[Tool] hint arguments, keyed by MCP tool id.
   *
   * @return array<string, array{readOnly: bool, destructive: bool, idempotent: bool, openWorld: bool}>
   *   The declared hint values per tool id.
   */
  private function toolFlags(): array {
    $dir = dirname(__DIR__, 5) . '/src/Plugin/Tool';
    $flags = [];
    foreach (glob($dir . '/*Tool.php') as $file) {
      $class = 'Drupal\\dkan_mcp_server\\Plugin\\Tool\\' . basename($file, '.php');
      $attributes = (new \ReflectionClass($class))->getAttributes(Tool::class);
      $this->assertNotEmpty($attributes, "$class is missing its #[Tool] attribute.");
      $args = $attributes[0]->getArguments();
      $flags[$args['id']] = [
        'readOnly' => $args['readOnly'] ?? NULL,
        'destructive' => $args['destructive'] ?? NULL,
        'idempotent' => $args['idempotent'] ?? NULL,
        'openWorld' => $args['openWorld'] ?? NULL,
      ];
    }
    return $flags;
  }

}
