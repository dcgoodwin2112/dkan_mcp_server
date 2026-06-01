<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: queue item counts for DKAN-related queues.
 */
#[Tool(
  id: 'get_queue_status',
  label: new TranslatableMarkup('Get queue status'),
  description: new TranslatableMarkup('Get item counts for DKAN-related queues. Omit queueName for all DKAN queues.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'queueName' => [
        'type' => 'string',
        'description' => 'Specific queue worker name (e.g. datastore_import). Omit for all DKAN queues.',
      ],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetQueueStatusTool extends StatusToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->status->getQueueStatus($arguments['queueName'] ?? NULL);
  }

}
