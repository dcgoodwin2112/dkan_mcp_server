<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Write tool: execute a harvest run.
 */
#[Tool(
  id: 'run_harvest',
  label: new TranslatableMarkup('Run harvest'),
  description: new TranslatableMarkup('Execute a harvest run for a registered plan.'),
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
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  // The harvest runner fetches the remote source, reaching outside the catalog.
  openWorld: TRUE,
)]
final class RunHarvestTool extends HarvestToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'manage harvests via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->harvest->runHarvest((string) ($arguments['planId'] ?? ''));
  }

}
