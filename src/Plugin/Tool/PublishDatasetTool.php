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
 * Write tool: publish a dataset to make it publicly visible.
 */
#[Tool(
  id: 'publish_dataset',
  label: new TranslatableMarkup('Publish dataset'),
  description: new TranslatableMarkup('Publish a dataset to make it publicly visible.'),
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
final class PublishDatasetTool extends WriteToolBase {

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
    return $this->writeTools->publishDataset((string) ($arguments['identifier'] ?? ''));
  }

}
