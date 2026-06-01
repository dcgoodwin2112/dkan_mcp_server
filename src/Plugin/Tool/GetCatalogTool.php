<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: get the full DCAT data catalog.
 */
#[Tool(
  id: 'get_catalog',
  label: new TranslatableMarkup('Get catalog'),
  description: new TranslatableMarkup('Get the full DCAT data catalog (descriptions truncated, spatial fields removed).'),
  inputSchema: [
    'type' => 'object',
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetCatalogTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getCatalog();
  }

}
