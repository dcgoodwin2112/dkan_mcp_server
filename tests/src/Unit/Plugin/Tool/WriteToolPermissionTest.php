<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Plugin\Tool;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\dkan_mcp_server\Plugin\Tool\DeleteDatasetTool;
use Drupal\dkan_mcp_server\Plugin\Tool\DeleteMetastoreItemTool;
use Drupal\dkan_mcp_server\Plugin\Tool\DeregisterHarvestTool;
use Drupal\dkan_mcp_server\Plugin\Tool\DropDatastoreTool;
use Drupal\dkan_mcp_server\Plugin\Tool\GetDatasetTool;
use Drupal\dkan_mcp_server\Plugin\Tool\ImportResourceTool;
use Drupal\dkan_mcp_server\Plugin\Tool\PatchDatasetTool;
use Drupal\dkan_mcp_server\Plugin\Tool\PatchMetastoreItemTool;
use Drupal\dkan_mcp_server\Plugin\Tool\PostMetastoreItemTool;
use Drupal\dkan_mcp_server\Plugin\Tool\PublishDatasetTool;
use Drupal\dkan_mcp_server\Plugin\Tool\RegisterHarvestTool;
use Drupal\dkan_mcp_server\Plugin\Tool\RunHarvestTool;
use Drupal\dkan_mcp_server\Plugin\Tool\UnpublishDatasetTool;
use Drupal\dkan_mcp_server\Plugin\Tool\UpdateDatasetTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Locks the write tool -> permission mapping and the read-tool open default.
 *
 * The checkAccess() method touches none of the constructor-injected services,
 * so the tools are instantiated without their constructor and exercised against
 * a mocked account. This pins each write permission string (a typo here would
 * silently widen access) and confirms read tools require none.
 */
final class WriteToolPermissionTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // AccessResult::allowedIfHasPermission() attaches the 'user.permissions'
    // cache context, which is validated against the global container.
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Every write tool keyed to the single permission it must require.
   *
   * @return array<string, array{class-string, string}>
   *   Test name => [tool class, required permission].
   */
  public static function writeToolProvider(): array {
    return [
      'import_resource' => [ImportResourceTool::class, 'import datastore via mcp'],
      'update_dataset' => [UpdateDatasetTool::class, 'edit datasets via mcp'],
      'patch_dataset' => [PatchDatasetTool::class, 'edit datasets via mcp'],
      'delete_dataset' => [DeleteDatasetTool::class, 'delete datasets via mcp'],
      'publish_dataset' => [PublishDatasetTool::class, 'publish datasets via mcp'],
      'unpublish_dataset' => [UnpublishDatasetTool::class, 'publish datasets via mcp'],
      'post_metastore_item' => [PostMetastoreItemTool::class, 'manage metastore items via mcp'],
      'patch_metastore_item' => [PatchMetastoreItemTool::class, 'manage metastore items via mcp'],
      'delete_metastore_item' => [DeleteMetastoreItemTool::class, 'manage metastore items via mcp'],
      'drop_datastore' => [DropDatastoreTool::class, 'drop datastore via mcp'],
      'register_harvest' => [RegisterHarvestTool::class, 'manage harvests via mcp'],
      'run_harvest' => [RunHarvestTool::class, 'manage harvests via mcp'],
      'deregister_harvest' => [DeregisterHarvestTool::class, 'manage harvests via mcp'],
    ];
  }

  /**
   * A write tool gates on exactly its one declared permission.
   */
  #[DataProvider('writeToolProvider')]
  public function testWriteToolRequiresPermission(string $class, string $permission): void {
    $requested = [];
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      function (string $perm) use (&$requested): bool {
        $requested[] = $perm;
        return TRUE;
      },
    );

    $result = $this->toolWithoutConstructor($class)->checkAccess($account);

    $this->assertTrue($result->isAllowed(), "$class should allow when its permission is granted.");
    $this->assertSame([$permission], $requested, "$class must gate on exactly one permission.");
  }

  /**
   * A write tool denies when its permission is absent.
   */
  #[DataProvider('writeToolProvider')]
  public function testWriteToolDeniedWithoutPermission(string $class, string $permission): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $this->assertFalse(
      $this->toolWithoutConstructor($class)->checkAccess($account)->isAllowed(),
      "$class must deny when its permission ($permission) is absent.",
    );
  }

  /**
   * Every required permission is declared in the module's permissions.yml.
   */
  public function testRequiredPermissionsAreDeclared(): void {
    $declared = array_keys(Yaml::parseFile(dirname(__DIR__, 5) . '/dkan_mcp_server.permissions.yml'));
    foreach (self::writeToolProvider() as [$class, $permission]) {
      $this->assertContains($permission, $declared, "Permission '$permission' for $class is undeclared.");
    }
  }

  /**
   * Read tools inherit the open default: access without any extra permission.
   */
  public function testReadToolIsOpen(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $this->assertTrue(
      $this->toolWithoutConstructor(GetDatasetTool::class)->checkAccess($account)->isAllowed(),
      'Read tools must not require a write permission.',
    );
  }

  /**
   * Instantiates a tool plugin without invoking its constructor.
   *
   * The checkAccess() method reads none of the injected services, so skipping
   * the constructor avoids wiring the DKAN service graph in a unit test.
   */
  private function toolWithoutConstructor(string $class): object {
    return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
  }

}
