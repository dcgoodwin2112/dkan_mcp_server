<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit;

use Drupal\dkan_mcp_server\OAuth\DkanMcpScopes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the opt-in OAuth config artifacts stay self-consistent.
 *
 * These tests cover the authorization config this module owns: the write role
 * grants exactly the module's write permissions, the scope entities use the
 * correct granularity, and every scope advertised in protected-resource
 * metadata ships as a backing oauth2_scope config entity. The token →
 * account → effective-permission leg is simple_oauth's and is verified
 * separately in an environment with simple_oauth installed (see OAUTH_PLAN.md
 * Phase 3).
 */
final class OAuthScopeConfigTest extends TestCase {

  /**
   * Module root directory (tests/src/Unit → module root is three levels up).
   */
  private const MODULE_DIR = __DIR__ . '/../../..';

  /**
   * Parses a YAML file relative to the module root.
   *
   * @return array<string, mixed>
   *   The decoded YAML.
   */
  private static function parse(string $relative): array {
    $path = self::MODULE_DIR . '/' . $relative;
    if (!is_file($path)) {
      throw new \RuntimeException("Missing config file: $relative");
    }
    return Yaml::parseFile($path);
  }

  /**
   * The write role grants every write permission plus access mcp server.
   */
  public function testWriteRoleGrantsAllWritePermissions(): void {
    $expected = array_keys(self::parse('dkan_mcp_server.permissions.yml'));
    $expected[] = 'access mcp server';
    sort($expected);

    $role = self::parse('config/optional/user.role.dkan_mcp_write.yml');
    $actual = $role['permissions'];
    sort($actual);

    $this->assertSame(
      $expected,
      $actual,
      'The dkan_mcp_write role must grant every "* via mcp" permission plus access mcp server.',
    );
  }

  /**
   * The read scope maps (permission granularity) to access mcp server.
   */
  public function testReadScopeMapsToAccessMcpServer(): void {
    $scope = self::parse('config/optional/simple_oauth.oauth2_scope.dkan_mcp_read.yml');

    $this->assertSame(DkanMcpScopes::READ, $scope['name']);
    $this->assertSame('permission', $scope['granularity_id']);
    $this->assertSame('access mcp server', $scope['granularity_configuration']['permission']);
  }

  /**
   * The write scope maps (role granularity) to the dkan_mcp_write role.
   */
  public function testWriteScopeMapsToWriteRole(): void {
    $scope = self::parse('config/optional/simple_oauth.oauth2_scope.dkan_mcp_write.yml');

    $this->assertSame(DkanMcpScopes::WRITE, $scope['name']);
    $this->assertSame('role', $scope['granularity_id']);
    $this->assertSame('dkan_mcp_write', $scope['granularity_configuration']['role']);
    $this->assertContains(
      'user.role.dkan_mcp_write',
      $scope['dependencies']['config'] ?? [],
      'The write scope must declare a config dependency on the role it grants.',
    );
  }

  /**
   * Every advertised scope ships as a backing oauth2_scope config entity.
   *
   * Closes the gap where ResourceMetadataSubscriber advertised scopes that had
   * no corresponding simple_oauth scope to issue tokens for.
   */
  public function testAdvertisedScopesHaveBackingConfig(): void {
    $shipped = [];
    foreach (['dkan_mcp_read', 'dkan_mcp_write'] as $id) {
      $shipped[] = self::parse("config/optional/simple_oauth.oauth2_scope.$id.yml")['name'];
    }
    sort($shipped);

    $advertised = DkanMcpScopes::all();
    sort($advertised);

    $this->assertSame(
      $advertised,
      $shipped,
      'ResourceMetadataSubscriber must advertise exactly the scopes that ship as config.',
    );
  }

}
