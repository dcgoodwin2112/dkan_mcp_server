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
 * Write tool: drop a datastore table for a resource.
 */
#[Tool(
  id: 'drop_datastore',
  label: new TranslatableMarkup('Drop datastore'),
  description: new TranslatableMarkup('Drop the datastore table for a resource (the metadata remains; re-import to rebuild).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
    ],
    'required' => ['resourceId'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class DropDatastoreTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'drop datastore via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->dropDatastore((string) ($arguments['resourceId'] ?? ''));
  }

}
