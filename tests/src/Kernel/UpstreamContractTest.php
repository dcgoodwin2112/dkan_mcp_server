<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift guard for the mcp_server / mcp/sdk API surface this module consumes.
 *
 * The stack rides dev branches (mcp_server:2.x-dev, mcp/sdk:dev-main) pinned to
 * tested commits in composer.json. When those pins are bumped, an upstream
 * rename of a class, method, attribute parameter, service, or route would
 * otherwise surface as an opaque runtime fatal deep in a request. This test
 * asserts every symbol the module imports still exists, so a bump that breaks
 * the contract fails here with a clear, located message instead.
 *
 * Keep this in sync with the breakage register in
 * docs/DEPENDENCY_STABILITY_PLAN.md. Add a symbol here only when the module's
 * own code starts consuming it.
 *
 * @group dkan_mcp_server
 */
#[RunTestsInSeparateProcesses]
class UpstreamContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The mcp_server module declares no dependencies; enabling it registers the
   * Drupal\mcp_server\ namespace and defines the tool plugin manager service.
   * The Mcp\ SDK classes autoload from the mcp/sdk Composer package.
   */
  protected static $modules = ['system', 'user', 'mcp_server'];

  /**
   * SDK (mcp/sdk) classes the module imports.
   */
  private const SDK_CLASSES = [
    'Mcp\\Event\\RequestEvent',
    'Mcp\\Event\\ResponseEvent',
    'Mcp\\Schema\\JsonRpc\\Response',
    'Mcp\\Schema\\Request\\CallToolRequest',
    'Mcp\\Schema\\Request\\ListToolsRequest',
    'Mcp\\Schema\\Result\\ListToolsResult',
    'Mcp\\Schema\\Tool',
    'Mcp\\Server\\ClientGateway',
  ];

  /**
   * Classes from mcp_server the module imports.
   */
  private const SERVER_CLASSES = [
    'Drupal\\mcp_server\\Attribute\\Tool',
    'Drupal\\mcp_server\\Exception\\McpAuthorizationDeniedException',
    'Drupal\\mcp_server\\Plugin\\ToolPluginBase',
    'Drupal\\mcp_server\\Plugin\\ToolPluginManager',
    // Resource subsystem (src/Plugin/ResourceProvider/).
    'Drupal\\mcp_server\\Attribute\\ResourceProvider',
    'Drupal\\mcp_server\\Plugin\\ResourceProviderBase',
    'Drupal\\mcp_server\\Resource\\CacheableResourceContent',
    // Resource template subsystem (src/Plugin/ResourceTemplateProvider/).
    'Drupal\\mcp_server\\Attribute\\ResourceTemplateProvider',
    'Drupal\\mcp_server\\Plugin\\ResourceTemplateProviderBase',
  ];

  /**
   * Interfaces from mcp_server the module implements.
   */
  private const SERVER_INTERFACES = [
    'Drupal\\mcp_server\\Plugin\\ToolPluginInterface',
    'Drupal\\mcp_server\\Plugin\\ResourceProviderInterface',
    'Drupal\\mcp_server\\Plugin\\ResourceTemplateProviderInterface',
  ];

  /**
   * Plugin manager services the module's subsystems rely on.
   */
  private const MANAGER_SERVICES = [
    'plugin.manager.mcp_server.tool',
    'plugin.manager.mcp_server.resource_provider',
    'plugin.manager.mcp_server.resource_template_provider',
  ];

  /**
   * Methods called on upstream types: class => [method, ...].
   */
  private const CONSUMED_METHODS = [
    'Mcp\\Event\\RequestEvent' => ['getRequest'],
    'Mcp\\Event\\ResponseEvent' => ['getRequest', 'getResponse', 'setResponse'],
    'Mcp\\Schema\\JsonRpc\\Response' => ['getId'],
    'Drupal\\mcp_server\\Plugin\\ToolPluginInterface' => ['checkToolAccess'],
    'Drupal\\mcp_server\\Resource\\CacheableResourceContent' => ['fromArray'],
    'Drupal\\mcp_server\\Plugin\\ResourceTemplateProviderInterface' => [
      'getUriTemplate', 'getResources', 'getResourceContent', 'checkAccess',
    ],
  ];

  /**
   * Public properties read off upstream types: class => [property, ...].
   */
  private const CONSUMED_PROPERTIES = [
    'Mcp\\Schema\\JsonRpc\\Response' => ['result'],
    'Mcp\\Schema\\Request\\CallToolRequest' => ['name'],
    'Mcp\\Schema\\Result\\ListToolsResult' => ['tools', 'nextCursor'],
    'Mcp\\Schema\\Tool' => ['name'],
  ];

  /**
   * The #[Tool] attribute constructor parameters the plugins pass by name.
   */
  private const TOOL_ATTRIBUTE_PARAMS = [
    'id', 'label', 'description', 'inputSchema',
    'readOnly', 'destructive', 'idempotent', 'openWorld',
  ];

  /**
   * The #[ResourceProvider] attribute constructor parameters the plugins use.
   */
  private const RESOURCE_ATTRIBUTE_PARAMS = [
    'id', 'label', 'description', 'module_dependencies',
  ];

  /**
   * The #[ResourceTemplateProvider] attribute parameters the plugins use.
   */
  private const RESOURCE_TEMPLATE_ATTRIBUTE_PARAMS = [
    'id', 'label', 'description', 'module_dependencies',
  ];

  /**
   * Every imported upstream class/interface still resolves.
   */
  public function testConsumedClassesExist(): void {
    foreach (self::SDK_CLASSES as $class) {
      $this->assertTrue(class_exists($class), "mcp/sdk class missing: $class");
    }
    foreach (self::SERVER_CLASSES as $class) {
      $this->assertTrue(class_exists($class), "mcp_server class missing: $class");
    }
    foreach (self::SERVER_INTERFACES as $interface) {
      $this->assertTrue(interface_exists($interface), "mcp_server interface missing: $interface");
    }
  }

  /**
   * Every method the module invokes on an upstream type still exists.
   */
  public function testConsumedMethodsExist(): void {
    foreach (self::CONSUMED_METHODS as $class => $methods) {
      foreach ($methods as $method) {
        $this->assertTrue(
          method_exists($class, $method),
          "Upstream method missing: {$class}::{$method}()",
        );
      }
    }
  }

  /**
   * Every public property the module reads off an upstream type still exists.
   */
  public function testConsumedPropertiesExist(): void {
    foreach (self::CONSUMED_PROPERTIES as $class => $properties) {
      foreach ($properties as $property) {
        $this->assertTrue(
          property_exists($class, $property),
          "Upstream property missing: {$class}::\${$property}",
        );
      }
    }
  }

  /**
   * The #[Tool] attribute still accepts the named arguments the plugins use.
   */
  public function testToolAttributeParametersExist(): void {
    $params = (new \ReflectionMethod('Drupal\\mcp_server\\Attribute\\Tool', '__construct'))
      ->getParameters();
    $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params);
    foreach (self::TOOL_ATTRIBUTE_PARAMS as $param) {
      $this->assertContains(
        $param,
        $names,
        "#[Tool] attribute no longer accepts a '$param' parameter.",
      );
    }
  }

  /**
   * The #[ResourceProvider] attribute still accepts the parameters used.
   */
  public function testResourceProviderAttributeParametersExist(): void {
    $params = (new \ReflectionMethod('Drupal\\mcp_server\\Attribute\\ResourceProvider', '__construct'))
      ->getParameters();
    $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params);
    foreach (self::RESOURCE_ATTRIBUTE_PARAMS as $param) {
      $this->assertContains(
        $param,
        $names,
        "#[ResourceProvider] attribute no longer accepts a '$param' parameter.",
      );
    }
  }

  /**
   * The #[ResourceTemplateProvider] attribute still accepts its parameters.
   */
  public function testResourceTemplateProviderAttributeParametersExist(): void {
    $params = (new \ReflectionMethod('Drupal\\mcp_server\\Attribute\\ResourceTemplateProvider', '__construct'))
      ->getParameters();
    $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params);
    foreach (self::RESOURCE_TEMPLATE_ATTRIBUTE_PARAMS as $param) {
      $this->assertContains(
        $param,
        $names,
        "#[ResourceTemplateProvider] attribute no longer accepts a '$param' parameter.",
      );
    }
  }

  /**
   * The plugin manager services the module's subsystems rely on are defined.
   */
  public function testManagerServicesExist(): void {
    foreach (self::MANAGER_SERVICES as $service) {
      $this->assertTrue(
        $this->container->has($service),
        "Service missing: $service",
      );
    }
  }

  /**
   * The mcp_server.handle route still ships with a manipulable _auth option.
   *
   * RouteSubscriber appends 'basic_auth' to this route's _auth option, so the
   * route name and the option must both remain. Asserted against the shipped
   * routing.yml (located via the extension list) to avoid a router rebuild.
   */
  public function testMcpHandleRouteContract(): void {
    $path = $this->container->get('extension.list.module')
      ->getPath('mcp_server') . '/mcp_server.routing.yml';
    $this->assertFileExists($path);
    $routes = Yaml::parseFile($path);

    $this->assertArrayHasKey('mcp_server.handle', $routes, 'Route renamed/removed: mcp_server.handle');
    $auth = $routes['mcp_server.handle']['options']['_auth'] ?? NULL;
    $this->assertIsArray($auth, "mcp_server.handle no longer declares an '_auth' option array.");
  }

}
