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
 * Write tool: full replacement of dataset metadata (PUT semantics).
 */
#[Tool(
  id: 'update_dataset',
  label: new TranslatableMarkup('Update dataset'),
  description: new TranslatableMarkup('Full replacement of dataset metadata (PUT/upsert).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'identifier' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
      'metadata' => [
        'type' => 'string',
        'description' => 'Complete dataset metadata as a JSON object string.',
      ],
    ],
    'required' => ['identifier', 'metadata'],
  ],
  readOnly: FALSE,
  destructive: TRUE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class UpdateDatasetTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'edit datasets via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->updateDataset(
      (string) ($arguments['identifier'] ?? ''),
      (string) ($arguments['metadata'] ?? ''),
    );
  }

}
