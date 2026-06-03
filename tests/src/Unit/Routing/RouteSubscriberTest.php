<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Unit\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dkan_mcp_server\Routing\RouteSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Unit tests for the configurable basic_auth route alteration.
 */
final class RouteSubscriberTest extends TestCase {

  /**
   * Invokes the protected alterRoutes() with the given setting.
   *
   * @return string[]
   *   The route's _auth option after alteration.
   */
  private function authAfterAlter(bool $basic_auth_enabled): array {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('http_basic_auth')->willReturn($basic_auth_enabled);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('dkan_mcp_server.settings')->willReturn($config);

    $route = new Route('/_mcp', [], [], ['_auth' => ['cookie']]);
    $collection = new RouteCollection();
    $collection->add('mcp_server.handle', $route);

    $subscriber = new RouteSubscriber($config_factory);
    $method = new \ReflectionMethod($subscriber, 'alterRoutes');
    $method->invoke($subscriber, $collection);

    return $collection->get('mcp_server.handle')->getOption('_auth');
  }

  /**
   * When enabled, basic_auth is appended to the route.
   */
  public function testBasicAuthAppendedWhenEnabled(): void {
    $this->assertContains('basic_auth', $this->authAfterAlter(TRUE));
  }

  /**
   * When disabled (default), basic_auth is not added.
   */
  public function testBasicAuthAbsentWhenDisabled(): void {
    $auth = $this->authAfterAlter(FALSE);
    $this->assertNotContains('basic_auth', $auth);
    $this->assertSame(['cookie'], $auth);
  }

}
