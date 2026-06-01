<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: datastore column names and types (data-dictionary enriched).
 */
#[Tool(
  id: 'get_datastore_schema',
  label: new TranslatableMarkup('Get datastore schema'),
  description: new TranslatableMarkup('Get the column names and types of a datastore resource. When a data dictionary is linked, columns are enriched with dictionary titles, descriptions, and types.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'includeDictionary' => [
        'type' => 'boolean',
        'description' => 'Enrich columns with linked data-dictionary metadata.',
        'default' => TRUE,
      ],
    ],
    'required' => ['resourceId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDatastoreSchemaTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->getDatastoreSchema(
      (string) ($arguments['resourceId'] ?? ''),
      (bool) ($arguments['includeDictionary'] ?? TRUE),
    );
  }

}
