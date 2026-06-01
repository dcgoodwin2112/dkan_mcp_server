<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: aggregated dataset info (distributions, resources, import status).
 */
#[Tool(
  id: 'get_dataset_info',
  label: new TranslatableMarkup('Get dataset info'),
  description: new TranslatableMarkup('Get aggregated dataset info (distributions, resources, and import status) for a dataset UUID.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'uuid' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
    ],
    'required' => ['uuid'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDatasetInfoTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getDatasetInfo((string) ($arguments['uuid'] ?? ''));
  }

}
