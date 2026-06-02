<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: return the first N rows of a datastore resource for grounding.
 */
#[Tool(
  id: 'sample_rows',
  label: new TranslatableMarkup('Sample datastore rows'),
  description: new TranslatableMarkup('Return the first N rows of a datastore resource (deterministic, sorted by record order) to ground an agent in real cell shapes, code values, and units before composing filters or aggregations.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'n' => [
        'type' => 'integer',
        'description' => 'Number of rows to return (1-50).',
        'default' => 5,
        'minimum' => 1,
        'maximum' => 50,
      ],
    ],
    'required' => ['resourceId'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'resource_id' => ['type' => 'string'],
      'rows' => ['type' => 'array'],
      'row_count' => ['type' => 'integer'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SampleRowsTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->sampleRows(
      (string) ($arguments['resourceId'] ?? ''),
      (int) ($arguments['n'] ?? 5),
    );
  }

}
