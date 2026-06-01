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
 * Write tool: archive (unpublish) a dataset.
 */
#[Tool(
  id: 'unpublish_dataset',
  label: new TranslatableMarkup('Unpublish dataset'),
  description: new TranslatableMarkup('Archive (unpublish) a dataset so it is no longer publicly visible.'),
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
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class UnpublishDatasetTool extends WriteToolBase {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'publish datasets via mcp');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->writeTools->unpublishDataset((string) ($arguments['identifier'] ?? ''));
  }

}
