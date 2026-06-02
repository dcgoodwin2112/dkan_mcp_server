<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;

/**
 * Read tool: query a datastore resource table.
 */
#[Tool(
  id: 'query_datastore',
  label: new TranslatableMarkup('Query datastore'),
  description: new TranslatableMarkup('Query a datastore resource table with optional filters, sorting, pagination, aggregation (sum, count, avg, max, min with GROUP BY), and arithmetic expressions (+, -, *, /, %). Use get_datastore_schema first to discover available columns.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'resourceId' => [
        'type' => 'string',
        'description' => 'Resource ID in identifier__version format (from list_distributions).',
      ],
      'columns' => [
        'type' => 'string',
        'description' => 'Comma-separated column names to return (omit for all).',
      ],
      'conditions' => [
        'type' => 'string',
        'description' => 'JSON array of condition objects: [{"property":"col","value":"val","operator":"="}]. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between. Supports conditionGroup for OR logic: [{"groupOperator":"or","conditions":[...]}]',
      ],
      'sortField' => [
        'type' => 'string',
        'description' => 'Column name to sort by.',
      ],
      'sortDirection' => [
        'type' => 'string',
        'enum' => ['asc', 'desc'],
        'default' => 'asc',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Max rows to return (1-500).',
        'default' => 100,
        'minimum' => 1,
        'maximum' => 500,
      ],
      'offset' => [
        'type' => 'integer',
        'description' => 'Number of rows to skip.',
        'default' => 0,
        'minimum' => 0,
      ],
      'expressions' => [
        'type' => 'string',
        'description' => 'JSON array of expressions: [{"operator":"sum","operands":["column"],"alias":"total"}]. Aggregate operators: sum, count, avg, max, min (1 operand, use with groupings). Arithmetic operators: +, -, *, /, % (2 operands, row-level computed columns). Cannot mix aggregate and arithmetic in one query.',
      ],
      'groupings' => [
        'type' => 'string',
        'description' => 'Comma-separated column names to GROUP BY. Required when using aggregate expressions. All non-aggregated columns must be listed here.',
      ],
    ],
    'required' => ['resourceId'],
  ],
  outputSchema: [
    'type' => 'object',
    'properties' => [
      'results' => ['type' => 'array'],
      'result_count' => ['type' => 'integer'],
      'total_rows' => ['type' => 'integer'],
      'limit' => ['type' => 'integer'],
      'offset' => ['type' => 'integer'],
      'sanity_flags' => ['type' => 'object'],
    ],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class QueryDatastoreTool extends DatastoreToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->datastore->queryDatastore(
      (string) ($arguments['resourceId'] ?? ''),
      $arguments['columns'] ?? NULL,
      $arguments['conditions'] ?? NULL,
      $arguments['sortField'] ?? NULL,
      $arguments['sortDirection'] ?? 'asc',
      (int) ($arguments['limit'] ?? 100),
      (int) ($arguments['offset'] ?? 0),
      $arguments['expressions'] ?? NULL,
      $arguments['groupings'] ?? NULL,
    );
  }

}
