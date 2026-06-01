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
 * Destructive tool: delete a dataset and cascade-delete its resources.
 *
 * Gated by 'delete datasets via mcp'; ToolAccessSubscriber enforces it on
 * tools/call and hides the tool from tools/list for users without it.
 */
#[Tool(
  id: 'delete_dataset',
  label: new TranslatableMarkup('Delete dataset'),
  description: new TranslatableMarkup('Remove a dataset and cascade-delete its distributions and datastore tables.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'identifier' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
    ],
    'required' => ['identifier'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class DeleteDatasetTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'delete datasets via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->deleteDataset((string) ($arguments['identifier'] ?? ''));
  }

}
