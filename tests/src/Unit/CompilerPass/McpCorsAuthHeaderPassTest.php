<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\CompilerPass;

use Drupal\dkan_mcp_server\CompilerPass\McpCorsAuthHeaderPass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for the CORS auth/session header pass.
 *
 * Verifies the pass adds 'authorization' (OAuth) and exposes 'mcp-session-id'
 * only when core CORS is enabled, and is otherwise a no-op.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class McpCorsAuthHeaderPassTest extends TestCase {

  /**
   * Runs the pass over a container seeded with the given cors.config.
   *
   * @param array|null $cors
   *   The cors.config to set, or NULL to leave the parameter absent.
   *
   * @return array|null
   *   The cors.config after the pass, or NULL if it was never set.
   */
  private function runPass(?array $cors): ?array {
    $container = new ContainerBuilder();
    if ($cors !== NULL) {
      $container->setParameter('cors.config', $cors);
    }
    (new McpCorsAuthHeaderPass())->process($container);
    return $container->hasParameter('cors.config')
      ? $container->getParameter('cors.config')
      : NULL;
  }

  /**
   * With no cors.config parameter, the pass does nothing.
   */
  public function testAbsentConfigIsNoOp(): void {
    $this->assertNull($this->runPass(NULL));
  }

  /**
   * With CORS disabled, the headers are left untouched.
   */
  public function testDisabledCorsIsNoOp(): void {
    $config = $this->runPass(['enabled' => FALSE, 'allowedHeaders' => ['content-type']]);
    $this->assertSame(['content-type'], $config['allowedHeaders']);
    $this->assertArrayNotHasKey('exposedHeaders', $config);
  }

  /**
   * When enabled, authorization is added and mcp-session-id is exposed.
   */
  public function testEnabledCorsAddsAuthAndExposesSession(): void {
    $config = $this->runPass([
      'enabled' => TRUE,
      'allowedHeaders' => ['content-type', 'mcp-session-id'],
    ]);
    $this->assertContains('authorization', $config['allowedHeaders']);
    $this->assertContains('content-type', $config['allowedHeaders'], 'Existing headers are preserved.');
    $this->assertSame(['mcp-session-id'], $config['exposedHeaders']);
  }

  /**
   * Re-running the pass does not duplicate entries.
   */
  public function testIdempotent(): void {
    $first = $this->runPass([
      'enabled' => TRUE,
      'allowedHeaders' => ['authorization'],
      'exposedHeaders' => ['mcp-session-id'],
    ]);
    $this->assertSame(['authorization'], $first['allowedHeaders']);
    $this->assertSame(['mcp-session-id'], $first['exposedHeaders']);
  }

}
