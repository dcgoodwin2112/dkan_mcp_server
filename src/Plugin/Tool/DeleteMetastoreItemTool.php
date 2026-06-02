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
 * Destructive tool: delete any metastore item by schema and identifier.
 *
 * Gated by 'manage metastore items via mcp'. DKAN does not reference-check on
 * delete, so removing an item still linked elsewhere (e.g. a data-dictionary
 * referenced by a distribution's describedBy) orphans those references; verify
 * usage with the read tools first. Use delete_dataset for datasets.
 */
#[Tool(
  id: 'delete_metastore_item',
  label: new TranslatableMarkup('Delete metastore item'),
  description: new TranslatableMarkup('Delete any metastore item (data-dictionary, distribution, theme, keyword, etc.) by schema and identifier. Destructive and unguarded: DKAN does not check references, so deleting an item still linked elsewhere (such as a data dictionary used by a distribution) orphans those links. Verify usage first with get_data_dictionary or resolve_resource. Use delete_dataset for datasets.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'schemaId' => [
        'type' => 'string',
        'description' => 'Metastore schema ID (e.g. data-dictionary, distribution, theme, keyword).',
      ],
      'identifier' => [
        'type' => 'string',
        'description' => 'Item identifier (UUID).',
      ],
    ],
    'required' => ['schemaId', 'identifier'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class DeleteMetastoreItemTool extends WriteToolBase {

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
    return $this->writeTools->deleteMetastoreItem(
      (string) ($arguments['schemaId'] ?? ''),
      (string) ($arguments['identifier'] ?? ''),
    );
  }

}
