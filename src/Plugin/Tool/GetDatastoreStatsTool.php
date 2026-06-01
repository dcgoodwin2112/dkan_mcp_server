<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: per-column datastore statistics.
 */
#[Tool(
  id: 'get_datastore_stats',
  label: new TranslatableMarkup('Get datastore stats'),
  description: new TranslatableMarkup('Per-column statistics (null count, distinct count, min, max) for a datastore resource.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'columns' => [
        'type' => 'string',
        'description' => 'Comma-separated column names to limit stats to (omit for all).',
      ],
    ],
    'required' => ['resourceId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetDatastoreStatsTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->getDatastoreStats(
      (string) ($arguments['resourceId'] ?? ''),
      $arguments['columns'] ?? NULL,
    );
  }

}
