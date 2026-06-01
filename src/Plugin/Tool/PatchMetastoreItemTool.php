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
 * Write tool: partial update of any metastore item via JSON Merge Patch.
 */
#[Tool(
  id: 'patch_metastore_item',
  label: new TranslatableMarkup('Patch metastore item'),
  description: new TranslatableMarkup('Partial update of any metastore item via JSON Merge Patch (RFC 7396).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'schemaId' => [
        'type' => 'string',
        'description' => 'Metastore schema ID (e.g. dataset, data-dictionary, distribution).',
      ],
      'identifier' => [
        'type' => 'string',
        'description' => 'Item identifier (UUID).',
      ],
      'metadata' => [
        'type' => 'string',
        'description' => 'JSON object string with only the fields to change.',
      ],
    ],
    'required' => ['schemaId', 'identifier', 'metadata'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PatchMetastoreItemTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'manage metastore items via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->patchMetastoreItem(
      (string) ($arguments['schemaId'] ?? ''),
      (string) ($arguments['identifier'] ?? ''),
      (string) ($arguments['metadata'] ?? ''),
    );
  }

}
