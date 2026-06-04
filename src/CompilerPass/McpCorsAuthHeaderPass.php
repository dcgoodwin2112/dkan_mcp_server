<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Closes the OAuth/session gaps mcp_server's CORS pass leaves open.
 *
 * The mcp_server McpCorsConfigPass augments core's cors.config with the MCP
 * methods and request headers (content-type, mcp-protocol-version,
 * mcp-session-id), but deliberately not:
 * - 'authorization' in allowedHeaders — required for the OAuth Bearer header on
 *   cross-origin POST /mcp, so without it browser OAuth clients are blocked.
 * - 'mcp-session-id' in exposedHeaders — the server returns the session id in
 *   this response header, but browsers hide non-safelisted response headers
 *   from JS unless they are explicitly exposed, so the client cannot read it.
 *
 * This adds both, but only when core CORS is enabled (cors.config present and
 * 'enabled' true). It is a no-op otherwise, matching upstream's guard, so it
 * never turns CORS on by itself — operators still own the origin allowlist in
 * services.yml (see README).
 *
 * Runs after McpCorsConfigPass via the lower priority registered in the
 * service provider, so it augments the already-merged config.
 */
final class McpCorsAuthHeaderPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    if (!$container->hasParameter('cors.config')) {
      return;
    }

    $config = $container->getParameter('cors.config');
    if (empty($config['enabled'])) {
      return;
    }

    $config['allowedHeaders'] = array_values(array_unique(array_merge(
      $config['allowedHeaders'] ?? [],
      ['authorization'],
    )));

    $config['exposedHeaders'] = array_values(array_unique(array_merge(
      $config['exposedHeaders'] ?? [],
      ['mcp-session-id'],
    )));

    $container->setParameter('cors.config', $config);
  }

}
