<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\PromptArgumentCompletionProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Drupal\mcp_server\Attribute\PromptArgumentCompletionProvider;
use Drupal\mcp_server\Plugin\PromptArgumentCompletionProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Completes a dataset_id prompt argument with live DKAN dataset identifiers.
 *
 * Empty input suggests the first page of catalog identifiers. Partial input is
 * matched two ways: by title/keyword through the search service (whole catalog,
 * the primary path), and by identifier substring within the first page of
 * results. The identifier scan is bounded to the first page on purpose:
 * completion runs per keystroke, DKAN does not full-text index identifiers, and
 * dataset UUIDs are rarely typed from memory, so paging the whole catalog each
 * keystroke is not worth the cost. Identifier lookups use the metastore's
 * identifier-only API, so no full dataset bodies are decoded per keystroke.
 * Delegates to dkan_query_tools (Decision D1).
 */
#[PromptArgumentCompletionProvider(
  id: 'dkan_dataset_id',
  label: new TranslatableMarkup('DKAN dataset ID'),
  description: new TranslatableMarkup('Suggests dataset identifiers from the DKAN catalog, matching partial input by title or identifier.'),
)]
final class DkanDatasetIdCompletionProvider extends PromptArgumentCompletionProviderBase {

  /**
   * The default maximum number of suggestions.
   */
  private const DEFAULT_LIMIT = 20;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MetastoreTools $metastore,
    private readonly SearchTools $search,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('dkan_query_tools.metastore'),
      $container->get('dkan_query_tools.search'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['limit' => self::DEFAULT_LIMIT];
  }

  /**
   * {@inheritdoc}
   */
  public function getCompletions(string $current_value, array $configuration): array {
    $limit = $this->resolveLimit($configuration);
    $current = trim($current_value);
    $ids = [];

    try {
      if ($current === '') {
        $ids = $this->metastore->listDatasetIdentifiers(0, $limit)['identifiers'];
      }
      else {
        // Title/keyword matches (whole catalog) plus first-page identifier
        // substring matches; see the class doc for why the id scan is bounded.
        $ids = $this->identifiers($this->search->searchDatasets($current, 1, $limit), 'results');
        foreach ($this->metastore->listDatasetIdentifiers(0, $limit)['identifiers'] as $id) {
          if (stripos($id, $current) !== FALSE) {
            $ids[] = $id;
          }
        }
      }
    }
    catch (\Throwable) {
      return [];
    }

    return array_slice(array_values(array_unique($ids)), 0, $limit);
  }

  /**
   * Extracts non-empty string identifiers from a tool result row list.
   *
   * @param array $result
   *   A searchDatasets result payload.
   * @param string $key
   *   The key holding the row list ('results').
   *
   * @return string[]
   *   The identifiers, in order.
   */
  private function identifiers(array $result, string $key): array {
    $ids = [];
    foreach ($result[$key] ?? [] as $row) {
      if (!empty($row['identifier'])) {
        $ids[] = (string) $row['identifier'];
      }
    }
    return $ids;
  }

  /**
   * Resolves the configured suggestion cap, clamped to a sane range.
   *
   * @param array $configuration
   *   Plugin configuration from the prompt argument.
   */
  private function resolveLimit(array $configuration): int {
    $limit = (int) ($configuration['limit'] ?? self::DEFAULT_LIMIT);
    return max(1, min($limit, 50));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

}
