<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: get a harvest plan's configuration.
 */
#[Tool(
  id: 'get_harvest_plan',
  label: new TranslatableMarkup('Get harvest plan'),
  description: new TranslatableMarkup('Get the configuration (extract and load definitions) of a harvest plan.'),
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
final class GetHarvestPlanTool extends HarvestToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->harvest->getHarvestPlan((string) ($arguments['planId'] ?? ''));
  }

}
