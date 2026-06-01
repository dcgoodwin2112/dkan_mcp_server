<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: list runs for a harvest plan.
 */
#[Tool(
  id: 'get_harvest_runs',
  label: new TranslatableMarkup('Get harvest runs'),
  description: new TranslatableMarkup('List all run IDs/timestamps for a harvest plan.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'planId' => [
        'type' => 'string',
        'description' => 'Harvest plan ID.',
      ],
    ],
    'required' => ['planId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetHarvestRunsTool extends HarvestToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->harvest->getHarvestRuns((string) ($arguments['planId'] ?? ''));
  }

}
