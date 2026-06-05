<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Optionally adds HTTP Basic Auth to the MCP server endpoint.
 *
 * The mcp_server.handle route ships with `_auth: ['cookie']`. Basic auth lets a
 * headless client authenticate each request with an Authorization header (so
 * the call runs as that Drupal user and ToolAccessSubscriber enforces per-tool
 * permissions). It is gated by the `dkan_mcp_server.settings:http_basic_auth`
 * flag, default FALSE.
 *
 * Default off is the production OAuth posture: a `Basic` challenge would
 * otherwise shadow the `oauth2` provider's `Bearer` challenge and break
 * RFC 9728 discovery. Enable it for local/demo setups that authenticate with a
 * static Basic header. Toggling requires a router rebuild.
 *
 * The basic_auth core module is an optional dependency (it is only needed for
 * this opt-in path), so the alteration is skipped when it is not enabled: the
 * basic_auth provider service would not exist, making the _auth entry a silent
 * no-op. hook_requirements surfaces that misconfiguration to the admin.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if (!$this->configFactory->get('dkan_mcp_server.settings')->get('http_basic_auth')) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('basic_auth')) {
      return;
    }
    $route = $collection->get('mcp_server.handle');
    if ($route === NULL) {
      return;
    }
    $auth = $route->getOption('_auth') ?? [];
    if (!in_array('basic_auth', $auth, TRUE)) {
      $auth[] = 'basic_auth';
      $route->setOption('_auth', $auth);
    }
  }

}
