<?php

namespace Drupal\dkan_common\Storage;

/**
 * Stub for Drupal\dkan_common\Storage\DatabaseTableInterface.
 */
interface DatabaseTableInterface {

  /**
   * {@inheritdoc}
   */
  public function getSchema(): array;

  /**
   * {@inheritdoc}
   */
  public function getTableName(): string;

}
