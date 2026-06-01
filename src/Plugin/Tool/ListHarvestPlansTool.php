<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: list registered harvest plan IDs.
 */
#[Tool(
  id: 'list_harvest_plans',
  label: new TranslatableMarkup('List harvest plans'),
  description: new TranslatableMarkup('List all registered harvest plan IDs.'),
  inputSchema: [
    'type' => 'object',
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ListHarvestPlansTool extends HarvestToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->harvest->listHarvestPlans();
  }

}
