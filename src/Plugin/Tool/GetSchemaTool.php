<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: get a JSON Schema definition by schema ID.
 */
#[Tool(
  id: 'get_schema',
  label: new TranslatableMarkup('Get schema'),
  description: new TranslatableMarkup('Get a JSON Schema definition by schema ID (from list_schemas).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'schemaId' => [
        'type' => 'string',
        'description' => 'Metastore schema ID (e.g. dataset, distribution, data-dictionary).',
      ],
    ],
    'required' => ['schemaId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetSchemaTool extends MetastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->metastore->getSchema((string) ($arguments['schemaId'] ?? ''));
  }

}
