<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: list DKAN dataset summaries with pagination.
 */
#[Tool(
  id: 'list_datasets',
  label: new TranslatableMarkup('List datasets'),
  description: new TranslatableMarkup('List dataset summaries (identifier, title, truncated description, distribution count) with pagination.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'offset' => [
        'type' => 'integer',
        'description' => 'Number of datasets to skip.',
        'default' => 0,
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Maximum datasets to return.',
        'default' => 25,
      ],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListDatasetsTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->listDatasets(
      (int) ($arguments['offset'] ?? 0),
      (int) ($arguments['limit'] ?? 25),
    );
  }

}
