<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: fetch full dataset metadata by UUID.
 */
#[Tool(
  id: 'get_dataset',
  label: new TranslatableMarkup('Get dataset'),
  description: new TranslatableMarkup('Fetch full dataset metadata by UUID.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'identifier' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
    ],
    'required' => ['identifier'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDatasetTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getDataset((string) ($arguments['identifier'] ?? ''));
  }

}
