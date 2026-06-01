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
 * Write tool: create a metastore item under any schema.
 */
#[Tool(
  id: 'post_metastore_item',
  label: new TranslatableMarkup('Post metastore item'),
  description: new TranslatableMarkup('Create a metastore item under any schema (dataset, data-dictionary, distribution, theme, keyword, etc.).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'schemaId' => [
        'type' => 'string',
        'description' => 'Metastore schema ID (e.g. dataset, data-dictionary, distribution).',
      ],
      'metadata' => [
        'type' => 'string',
        'description' => 'Complete item metadata as a JSON object string, including identifier and the schema\'s required fields.',
      ],
    ],
    'required' => ['schemaId', 'metadata'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class PostMetastoreItemTool extends WriteToolBase {

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
    return $this->writeTools->postMetastoreItem(
      (string) ($arguments['schemaId'] ?? ''),
      (string) ($arguments['metadata'] ?? ''),
    );
  }

}
