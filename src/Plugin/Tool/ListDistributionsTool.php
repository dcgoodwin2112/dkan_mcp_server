<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: list the distributions (resources) in a dataset.
 */
#[Tool(
  id: 'list_distributions',
  label: new TranslatableMarkup('List distributions'),
  description: new TranslatableMarkup('List all distributions in a dataset, including resource_id (identifier__version), title, mediaType, and download URL.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'datasetId' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
    ],
    'required' => ['datasetId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListDistributionsTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->listDistributions((string) ($arguments['datasetId'] ?? ''));
  }

}
