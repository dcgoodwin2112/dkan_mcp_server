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
 * Write tool: register a new harvest plan.
 */
#[Tool(
  id: 'register_harvest',
  label: new TranslatableMarkup('Register harvest'),
  description: new TranslatableMarkup('Register a new harvest plan from a JSON definition (identifier, extract, load).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'plan' => [
        'type' => 'string',
        'description' => 'Harvest plan as a JSON object string with identifier, extract, and load properties.',
      ],
    ],
    'required' => ['plan'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class RegisterHarvestTool extends HarvestToolBase {

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
    return $this->harvest->registerHarvest((string) ($arguments['plan'] ?? ''));
  }

}
