<?php

namespace Drupal\Core\Database;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Stub for Drupal\Core\Database\Connection.
 */
abstract class Connection {

  /**
   * {@inheritdoc}
   */
  public function select(string $table, ?string $alias = NULL, array $options = []): SelectInterface {
    return new class implements SelectInterface {

      /**
       * {@inheritdoc}
       */
      public function fields(string $table_alias, array $fields = []): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function orderBy(string $field, string $direction = 'ASC'): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function isNotNull(string $field): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function range(?int $start = NULL, ?int $length = NULL): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function execute(): StatementInterface {
        return new class implements StatementInterface {

          /**
           * {@inheritdoc}
           */
          public function fetchField(int $index = 0): mixed {
            return 0;
          }

          /**
           * {@inheritdoc}
           */
          public function fetchAssoc(): array|false {
            return [];
          }

          /**
           * {@inheritdoc}
           */
          public function getIterator(): \ArrayIterator {
            return new \ArrayIterator([]);
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function countQuery(): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function fetchField(int $index = 0): mixed {
        return 0;
      }

      /**
       * {@inheritdoc}
       */
      public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface {
        return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function groupBy(string $field): SelectInterface {
        return $this;
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  public function schema(): Schema {
    return new Schema();
  }

  /**
   * {@inheritdoc}
   */
  public function escapeField(string $field): string {
    $escaped = preg_replace('/[^A-Za-z0-9_.]+/', '', $field);
    return '`' . str_replace('.', '`.`', $escaped) . '`';
  }

}
