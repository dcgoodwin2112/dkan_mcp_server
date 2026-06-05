<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\dkan_mcp_server\Routing\RouteSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Unit tests for the configurable basic_auth route alteration.
 *
 * The basic_auth module is an optional dependency, so the alteration applies
 * only when the flag is on and that module is enabled; otherwise the route is
 * left untouched (a flag-on-without-module case is a clean no-op).
 *
 * @group dkan_mcp_server
 */
#[Group('dkan_mcp_server')]
final class RouteSubscriberTest extends TestCase {

  /**
   * Invokes the protected alterRoutes() with the given flag and module state.
   *
   * @param bool $basic_auth_enabled
   *   The http_basic_auth flag value.
   * @param bool $module_enabled
   *   Whether the basic_auth module is reported as enabled.
   *
   * @return string[]
   *   The route's _auth option after alteration.
   */
  private function authAfterAlter(bool $basic_auth_enabled, bool $module_enabled = TRUE): array {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('http_basic_auth')->willReturn($basic_auth_enabled);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('dkan_mcp_server.settings')->willReturn($config);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('basic_auth')->willReturn($module_enabled);

    $route = new Route('/_mcp', [], [], ['_auth' => ['cookie']]);
    $collection = new RouteCollection();
    $collection->add('mcp_server.handle', $route);

    $subscriber = new RouteSubscriber($config_factory, $module_handler);
    $method = new \ReflectionMethod($subscriber, 'alterRoutes');
    $method->invoke($subscriber, $collection);

    return $collection->get('mcp_server.handle')->getOption('_auth');
  }

  /**
   * With the flag on and the module enabled, basic_auth is appended.
   */
  public function testBasicAuthAppendedWhenEnabled(): void {
    $this->assertContains('basic_auth', $this->authAfterAlter(TRUE, TRUE));
  }

  /**
   * When disabled (default), basic_auth is not added.
   */
  public function testBasicAuthAbsentWhenDisabled(): void {
    $auth = $this->authAfterAlter(FALSE);
    $this->assertNotContains('basic_auth', $auth);
    $this->assertSame(['cookie'], $auth);
  }

  /**
   * With the flag on but the module missing, the route is left untouched.
   */
  public function testBasicAuthAbsentWhenModuleMissing(): void {
    $auth = $this->authAfterAlter(TRUE, FALSE);
    $this->assertNotContains('basic_auth', $auth);
    $this->assertSame(['cookie'], $auth);
  }

}
