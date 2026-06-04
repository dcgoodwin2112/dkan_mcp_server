<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\dkan_mcp_server\CompilerPass\McpCorsAuthHeaderPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

/**
 * Registers this module's compiler passes.
 */
final class DkanMcpServerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    // Negative priority runs after mcp_server's McpCorsConfigPass (default 0),
    // so this augments the already-merged cors.config.
    $container->addCompilerPass(
      new McpCorsAuthHeaderPass(),
      PassConfig::TYPE_BEFORE_OPTIMIZATION,
      -10,
    );
  }

}
