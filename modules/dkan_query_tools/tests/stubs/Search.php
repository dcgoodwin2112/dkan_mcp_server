<?php

namespace Drupal\dkan_metastore_search;

/**
 * Stub for Drupal\dkan_metastore_search\Search.
 */
class Search {

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle -- Stubs the real Search::search(); the name matches the class case-insensitively, which is not a PHP4 constructor.
  public function search(array $params = []) {
    return (object) ['total' => 0, 'results' => []];
  }

}
