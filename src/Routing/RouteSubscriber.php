<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds HTTP Basic Auth to the MCP server endpoint.
 *
 * The mcp_server.handle route ships with `_auth: ['cookie']`, which is
 * impractical for headless MCP clients: they would have to carry a Drupal
 * session cookie and a CSRF token. Appending `basic_auth` lets a client
 * authenticate each request with an Authorization header, so the call runs as
 * that Drupal user and ToolAccessSubscriber enforces per-tool permissions for
 * that account. The addition is additive — cookie auth still works — and the
 * route's own `access mcp server` permission still gates every request.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
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
