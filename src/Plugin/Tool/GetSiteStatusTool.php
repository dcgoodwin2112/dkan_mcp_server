<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: high-level DKAN site overview.
 */
#[Tool(
  id: 'get_site_status',
  label: new TranslatableMarkup('Get site status'),
  description: new TranslatableMarkup('Get a high-level DKAN site overview: dataset/distribution counts, formats, import status, harvest plans, and versions.'),
  inputSchema: [
    'type' => 'object',
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetSiteStatusTool extends StatusToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->status->getSiteStatus();
  }

}
