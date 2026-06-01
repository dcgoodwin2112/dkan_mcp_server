<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: detailed result for a harvest run.
 */
#[Tool(
  id: 'get_harvest_run_result',
  label: new TranslatableMarkup('Get harvest run result'),
  description: new TranslatableMarkup('Get the detailed result for a harvest run. Omit runId for the latest run.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'planId' => [
        'type' => 'string',
        'description' => 'Harvest plan ID.',
      ],
      'runId' => [
        'type' => 'string',
        'description' => 'Run ID/timestamp. Omit for the latest run.',
      ],
    ],
    'required' => ['planId'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetHarvestRunResultTool extends HarvestToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->harvest->getHarvestRunResult(
      (string) ($arguments['planId'] ?? ''),
      $arguments['runId'] ?? NULL,
    );
  }

}
