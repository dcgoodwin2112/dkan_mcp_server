<?php

namespace Drupal\dkan_metastore;

use RootedData\RootedJsonData;

/**
 * Stub for Drupal\dkan_metastore\MetastoreService.
 */
class MetastoreService {

  /**
   * {@inheritdoc}
   */
  public function getAll(string $schema_id, ?int $start = NULL, ?int $length = NULL, $unpublished = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $schema_id, string $identifier, bool $published = TRUE): RootedJsonData {
    return new RootedJsonData('{}');
  }

  /**
   * {@inheritdoc}
   */
  public function count(string $schema_id, bool $unpublished = FALSE): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemas() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCatalog() {
    return new \stdClass();
  }

  /**
   * {@inheritdoc}
   */
  public static function removeReferences(RootedJsonData $data): void {
    $json = (string) $data;
    $decoded = json_decode($json, TRUE);
    if (is_array($decoded)) {
      foreach (array_keys($decoded) as $key) {
        if (str_starts_with($key, '%Ref:')) {
          unset($decoded[$key]);
        }
      }
      $data->set('$', json_decode(json_encode($decoded)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function post(string $schema_id, RootedJsonData $data): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function publish(string $schema_id, string $identifier): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function put(string $schema_id, string $identifier, RootedJsonData $data): array {
    return ['identifier' => $identifier, 'new' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function patch(string $schema_id, string $identifier, mixed $json_data): string {
    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $schema_id, string $identifier): string {
    return $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function archive(string $schema_id, string $identifier): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $schema_id) {
    return new \stdClass();
  }

}
