<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

/**
 * Implemented by tool plugins that belong to an operational tool group.
 *
 * ToolAccessSubscriber reads the group to hide/deny tools whose group is listed
 * in dkan_mcp_server.settings:disabled_groups. See ToolGroup for the taxonomy.
 */
interface GroupedToolInterface {

  /**
   * The machine name of the tool's group (a ToolGroup constant).
   */
  public function toolGroup(): string;

}
