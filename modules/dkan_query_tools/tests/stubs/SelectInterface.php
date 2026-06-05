<?php

namespace Drupal\Core\Database\Query;

use Drupal\Core\Database\StatementInterface;

/**
 * Stub for Drupal\Core\Database\Query\SelectInterface.
 */
interface SelectInterface {

  /**
   * {@inheritdoc}
   */
  public function fields(string $table_alias, array $fields = []): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function orderBy(string $field, string $direction = 'ASC'): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function isNotNull(string $field): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function range(?int $start = NULL, ?int $length = NULL): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function execute(): StatementInterface;

  /**
   * {@inheritdoc}
   */
  public function countQuery(): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function fetchField(int $index = 0): mixed;

  /**
   * {@inheritdoc}
   */
  public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function groupBy(string $field): SelectInterface;

  /**
   * {@inheritdoc}
   */
  public function distinct(bool $distinct = TRUE): SelectInterface;

}
