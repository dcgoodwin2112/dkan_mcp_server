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
 * Write tool: partial dataset update via JSON Merge Patch (RFC 7396).
 */
#[Tool(
  id: 'patch_dataset',
  label: new TranslatableMarkup('Patch dataset'),
  description: new TranslatableMarkup('Partially update dataset metadata via JSON Merge Patch (RFC 7396).'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'identifier' => [
        'type' => 'string',
        'description' => 'Dataset UUID.',
      ],
      'metadata' => [
        'type' => 'string',
        'description' => 'JSON object string with only the fields to change.',
      ],
    ],
    'required' => ['identifier', 'metadata'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PatchDatasetTool extends WriteToolBase {

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
    return $this->writeTools->patchDataset(
      (string) ($arguments['identifier'] ?? ''),
      (string) ($arguments['metadata'] ?? ''),
    );
  }

}
