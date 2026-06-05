<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the hook_requirements auth-posture classification.
 *
 * Loads the procedural .install and exercises the extracted, container-free
 * state helper. basic_auth is an optional module, so the flag alone does not
 * mean Basic auth is served: the status report distinguishes off (OAuth-only),
 * active (served — security warning), and inert (flag on but module missing, so
 * the configuration is dead).
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class InstallRequirementsTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/dkan_mcp_server.install';
  }

  /**
   * The flag plus basic_auth module presence map to the three posture states.
   */
  public function testAuthState(): void {
    // Flag off is the OAuth-only posture regardless of the module.
    $this->assertSame('off', _dkan_mcp_server_auth_state(FALSE, FALSE));
    $this->assertSame('off', _dkan_mcp_server_auth_state(FALSE, TRUE));
    // Flag on with the module serving Basic auth is the security warning.
    $this->assertSame('active', _dkan_mcp_server_auth_state(TRUE, TRUE));
    // Flag on without the module is dead configuration.
    $this->assertSame('inert', _dkan_mcp_server_auth_state(TRUE, FALSE));
  }

}
