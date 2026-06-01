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
 * Write tool: trigger a datastore import for a resource.
 */
#[Tool(
  id: 'import_resource',
  label: new TranslatableMarkup('Import resource'),
  description: new TranslatableMarkup('Trigger a datastore import for a resource, inline or queued for background processing.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'deferred' => [
        'type' => 'boolean',
        'description' => 'Queue for background processing instead of running inline.',
        'default' => FALSE,
      ],
    ],
    'required' => ['resourceId'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class ImportResourceTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'import datastore via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->importResource(
      (string) ($arguments['resourceId'] ?? ''),
      (bool) ($arguments['deferred'] ?? FALSE),
    );
  }

}
