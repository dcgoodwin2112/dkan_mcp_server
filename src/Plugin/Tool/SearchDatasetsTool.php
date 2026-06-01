<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: keyword search across datasets via the DKAN search API.
 */
#[Tool(
  id: 'search_datasets',
  label: new TranslatableMarkup('Search datasets'),
  description: new TranslatableMarkup('Search datasets by keyword via the DKAN search API, with pagination.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'keyword' => [
        'type' => 'string',
        'description' => 'Search keyword.',
      ],
      'page' => [
        'type' => 'integer',
        'description' => 'Result page (1-based).',
        'default' => 1,
      ],
      'pageSize' => [
        'type' => 'integer',
        'description' => 'Results per page (1-50).',
        'default' => 10,
      ],
    ],
    'required' => ['keyword'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class SearchDatasetsTool extends SearchToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->search->searchDatasets(
      (string) ($arguments['keyword'] ?? ''),
      (int) ($arguments['page'] ?? 1),
      (int) ($arguments['pageSize'] ?? 10),
    );
  }

}
