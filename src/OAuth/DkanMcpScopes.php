<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\OAuth;

/**
 * OAuth scope identifiers advertised for DKAN MCP clients.
 */
final class DkanMcpScopes {

  /**
   * Allows clients to call read-only DKAN MCP tools.
   */
  public const READ = 'dkan_mcp:read';

  /**
   * Allows clients to call DKAN MCP write/harvest/import tools.
   */
  public const WRITE = 'dkan_mcp:write';

  /**
   * Returns the supported DKAN MCP OAuth scopes.
   *
   * @return string[]
   *   Scope names suitable for RFC 9728 protected resource metadata.
   */
  public static function all(): array {
    return [
      self::READ,
      self::WRITE,
    ];
  }

}
