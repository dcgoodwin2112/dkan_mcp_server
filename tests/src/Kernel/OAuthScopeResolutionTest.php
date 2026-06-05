<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves simple_oauth resolves the shipped DKAN scopes to the right grants.
 *
 * This is the OAuth runtime "gate": Oauth2AccessPolicy feeds the scope
 * provider's resolution into $account->hasPermission(), which
 * ToolAccessSubscriber uses to allow/deny tools. This test runs simple_oauth's
 * own resolver against this module's actual config/optional YAML and asserts:
 * - the read scope (permission granularity) → `access mcp server`;
 * - the write scope (role granularity) → the `dkan_mcp_write` role.
 *
 * The role → write-permissions leg is covered by OAuthScopeConfigTest, so the
 * two together span the whole chain: scope → role → `* via mcp` permissions →
 * tool access. Testing the wiring (not the merged permission set) keeps this
 * lean — no DKAN boot and no dependency on the permissions being declared by an
 * enabled module.
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
#[RunTestsInSeparateProcesses]
class OAuthScopeResolutionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'serialization',
    'consumers',
    'simple_oauth',
  ];

  /**
   * Module root (tests/src/Kernel → three levels up).
   */
  private const MODULE_DIR = __DIR__ . '/../../..';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    // The scope provider defaults to the 'dynamic' (oauth2_scope) provider when
    // simple_oauth.settings is absent, so no installConfig() is needed — which
    // also avoids importing action config under the strict schema checker.
    // The authenticated role is referenced by role-granularity resolution.
    Role::create(['id' => RoleInterface::AUTHENTICATED_ID, 'label' => 'Authenticated user'])->save();
    Role::create($this->shippedConfig('config/optional/user.role.dkan_mcp_write.yml'))->save();

    $scope_storage = $this->container->get('entity_type.manager')->getStorage('oauth2_scope');
    foreach (['dkan_mcp_read', 'dkan_mcp_write'] as $id) {
      $scope_storage
        ->create($this->shippedConfig("config/optional/simple_oauth.oauth2_scope.$id.yml"))
        ->save();
    }
  }

  /**
   * The read scope grants exactly the access mcp server permission.
   */
  public function testReadScopeGrantsAccessMcpServer(): void {
    $provider = $this->scopeProvider();
    $scope = $this->loadScope('dkan_mcp:read');

    $this->assertSame(['access mcp server'], $provider->getPermissions($scope));
    // Permission granularity must not pull in any role.
    $this->assertSame([], $provider->getRoles($scope));
  }

  /**
   * The write scope grants the dkan_mcp_write role (which carries write perms).
   */
  public function testWriteScopeGrantsWriteRole(): void {
    $provider = $this->scopeProvider();
    $scope = $this->loadScope('dkan_mcp:write');

    $this->assertContains(
      'dkan_mcp_write',
      $provider->getRoles($scope),
      'A dkan_mcp:write token must grant the dkan_mcp_write role.',
    );
    // The read scope must never grant the write role.
    $this->assertNotContains('dkan_mcp_write', $provider->getRoles($this->loadScope('dkan_mcp:read')));
  }

  /**
   * Returns the simple_oauth scope provider.
   */
  private function scopeProvider(): object {
    return $this->container->get('simple_oauth.oauth2_scope.provider');
  }

  /**
   * Loads a scope entity by its name.
   */
  private function loadScope(string $name): object {
    $scopes = $this->scopeProvider()->loadMultipleByNames([$name]);
    $scope = reset($scopes);
    $this->assertNotEmpty($scope, "Scope '$name' should load.");
    return $scope;
  }

  /**
   * Parses a YAML config file shipped by this module.
   *
   * @return array<string, mixed>
   *   The decoded config.
   */
  private function shippedConfig(string $relative): array {
    return Yaml::parseFile(self::MODULE_DIR . '/' . $relative);
  }

}
