<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: return the distinct values (code list) of a datastore column.
 */
#[Tool(
  id: 'distinct_values',
  label: new TranslatableMarkup('Distinct column values'),
  description: new TranslatableMarkup('Return the distinct values of one datastore column so an agent can learn the code list / enum domain before filtering. Returns at most "limit" values and flags truncation.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'column' => [
        'type' => 'string',
        'description' => 'Column name to enumerate.',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Maximum distinct values to return (1-500).',
        'default' => 50,
        'minimum' => 1,
        'maximum' => 500,
      ],
    ],
    'required' => ['resourceId', 'column'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'resource_id' => ['type' => 'string'],
      'column' => ['type' => 'string'],
      'values' => ['type' => 'array'],
      'value_count' => ['type' => 'integer'],
      'truncated' => ['type' => 'boolean'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class DistinctValuesTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->distinctValues(
      (string) ($arguments['resourceId'] ?? ''),
      (string) ($arguments['column'] ?? ''),
      (int) ($arguments['limit'] ?? 50),
    );
  }

}
