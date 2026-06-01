<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: resolve the data dictionary linked to a dataset or resource.
 */
#[Tool(
  id: 'get_data_dictionary',
  label: new TranslatableMarkup('Get data dictionary'),
  description: new TranslatableMarkup('Resolve a dataset UUID or resource ID to its linked data-dictionary item(s) with field definitions.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'datasetOrResourceId' => [
        'type' => 'string',
        'description' => 'Dataset UUID or resource ID in identifier__version format.',
      ],
    ],
    'required' => ['datasetOrResourceId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDataDictionaryTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getDataDictionary((string) ($arguments['datasetOrResourceId'] ?? ''));
  }

}
