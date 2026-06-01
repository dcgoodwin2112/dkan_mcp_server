<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: list available metastore schema IDs.
 */
#[Tool(
  id: 'list_schemas',
  label: new TranslatableMarkup('List schemas'),
  description: new TranslatableMarkup('List available metastore schema IDs (dataset, distribution, data-dictionary, etc.).'),
  inputSchema: [
    'type' => 'object',
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListSchemasTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->listSchemas();
  }

}
