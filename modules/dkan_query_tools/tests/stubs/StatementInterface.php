<?php

namespace Drupal\Core\Database;

/**
 * Stub for Drupal\Core\Database\StatementInterface.
 */
interface StatementInterface extends \IteratorAggregate {

  /**
   * {@inheritdoc}
   */
  public function fetchField(int $index = 0): mixed;

  /**
   * {@inheritdoc}
   */
  public function fetchAssoc(): array|false;

  /**
   * {@inheritdoc}
   */
  public function fetchAll(?int $mode = NULL): array;

  /**
   * {@inheritdoc}
   */
  public function fetchCol(int $index = 0): array;

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \ArrayIterator;

}
