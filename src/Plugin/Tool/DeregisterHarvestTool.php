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
 * Write tool: remove a registered harvest plan.
 */
#[Tool(
  id: 'deregister_harvest',
  label: new TranslatableMarkup('Deregister harvest'),
  description: new TranslatableMarkup('Remove a registered harvest plan.'),
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
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class DeregisterHarvestTool extends HarvestToolBase {

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
    return $this->harvest->deregisterHarvest((string) ($arguments['planId'] ?? ''));
  }

}
