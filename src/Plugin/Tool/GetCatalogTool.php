<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\Tool;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read tool: get the full DCAT data catalog.
 */
#[Tool(
  id: 'get_catalog',
  label: new TranslatableMarkup('Get catalog'),
  description: new TranslatableMarkup('Get the full DCAT data catalog (descriptions truncated, spatial fields removed).'),
  inputSchema: [
    'type' => 'object',
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetCatalogTool extends MetastoreToolBase {

  /**
   * Cache id for the shaped catalog payload.
   */
  private const CID = 'dkan_mcp_server:tool:get_catalog';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    MetastoreTools $metastore,
    private readonly CacheBackendInterface $cache,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser, $metastore);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('dkan_query_tools.metastore'),
      $container->get('cache.default'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Loading the whole catalog is O(datasets) in queries (~42/dataset measured),
   * so the shaped payload is cached. It is busted by node_list:data — the same
   * metastore list tag the dkan://catalog resource uses
   * (DkanResourceProviderBase::dataCacheTags()). Any dataset or distribution
   * create, update, delete, publish, or unpublish invalidates it. Catalog
   * content changes only through those metastore writes, so a permanent entry
   * is safe. The payload is identical for every client (access is gated before
   * execute() runs), so a single shared key needs no per-user variation.
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $cached = $this->cache->get(self::CID);
    if ($cached !== FALSE) {
      return $cached->data;
    }
    $catalog = $this->metastore->getCatalog();
    $this->cache->set(self::CID, $catalog, CacheBackendInterface::CACHE_PERMANENT, ['node_list:data']);
    return $catalog;
  }

}
