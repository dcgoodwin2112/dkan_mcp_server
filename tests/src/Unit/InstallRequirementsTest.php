<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the hook_requirements auth-posture severity decision.
 *
 * Loads the procedural .install and exercises the extracted, container-free
 * severity helper: HTTP Basic auth on the MCP endpoint must raise a warning on
 * the status report, regardless of whether an OAuth provider is also present.
 */
final class InstallRequirementsTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/dkan_mcp_server.install';
  }

  /**
   * Basic auth enabled warns; disabled is OK.
   */
  public function testAuthSeverity(): void {
    $this->assertSame('warning', _dkan_mcp_server_auth_severity(TRUE));
    $this->assertSame('ok', _dkan_mcp_server_auth_severity(FALSE));
  }

}
