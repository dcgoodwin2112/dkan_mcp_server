<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Plugin\ResourceTemplateProvider;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_mcp_server\Resource\ResourceJsonContentTrait;
use Drupal\dkan_metastore\Factory\MetastoreItemFactoryInterface;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\mcp_server\Plugin\ResourceTemplateProviderBase;
use Drupal\mcp_server\Resource\CacheableResourceContent;
use Mcp\Exception\ResourceNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for DKAN resource template providers (parameterized dkan:// URIs).
 *
 * Mirrors DkanResourceProviderBase for the templated case: injects
 * dkan_query_tools.metastore, keeps reads open under `access mcp server`, and
 * shapes JSON identically. Concrete templates declare only their
 * #[ResourceTemplateProvider] attribute and two small methods, templates() and
 * fetch(); the base handles URI matching, access, and content wrapping.
 *
 * Existence is enforced in getResourceContent(): a well-formed URI whose id
 * does not resolve (backing call returns NULL or an 'error' payload) throws
 * ResourceNotFoundException, which the SDK surfaces as a clean
 * resource-not-found error. Returning NULL instead would reach mcp_server's
 * RuntimeException path and surface as a generic internal "Error while reading
 * resource". A URI that
 * matches none of this provider's templates returns NULL (not this provider's
 * concern). checkAccess() gates on URI shape + permission only, so it stays
 * cheap and does not double-fetch.
 */
abstract class DkanResourceTemplateProviderBase extends ResourceTemplateProviderBase {

  use ResourceJsonContentTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    protected readonly MetastoreTools $metastore,
    protected readonly MetastoreItemFactoryInterface $metastoreItemFactory,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $currentUser,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('dkan_query_tools.metastore'),
      $container->get('dkan.metastore.metastore_item_factory'),
    );
  }

  /**
   * DKAN cache tags that bust this content when metastore data changes.
   *
   * Uses the metastore item factory's list cache tags (node_list:data) —
   * matching DKAN's own MetastoreApiResponse — so any dataset/distribution
   * create/update/delete invalidates the cached resource. This also covers the
   * referenced items (dictionaries, datastore) the templated resources derive
   * from, which a re-harvest resaves.
   *
   * @return string[]
   *   The metastore list cache tags.
   */
  protected function dataCacheTags(): array {
    return $this->metastoreItemFactory::getCacheTags();
  }

  /**
   * The URI templates this provider advertises.
   *
   * @return array<int, array{uriTemplate: string, pattern: string, name: string, description: string}>
   *   Each entry: the MCP `uriTemplate` (RFC 6570, e.g. dkan://dataset/{id}); a
   *   PCRE `pattern` with one capture group for the id; a stable `name`; and a
   *   `description`.
   */
  abstract protected function templates(): array;

  /**
   * Fetches backing data for a matched template.
   *
   * @param string $name
   *   The matched template's `name`.
   * @param string $id
   *   The id captured from the URI.
   *
   * @return array|null
   *   The backing data, or NULL/an array with an 'error' key when the resource
   *   does not exist.
   */
  abstract protected function fetch(string $name, string $id): ?array;

  /**
   * {@inheritdoc}
   */
  public function getUriTemplate(): string {
    return $this->templates()[0]['uriTemplate'];
  }

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    return array_map(static fn(array $t): array => [
      'uri' => $t['uriTemplate'],
      'name' => $t['name'],
      'description' => $t['description'],
      'mimeType' => 'application/json',
    ], $this->templates());
  }

  /**
   * {@inheritdoc}
   */
  public function getResourceContent(string $uri): ?CacheableResourceContent {
    $match = $this->matchUri($uri);
    if ($match === NULL) {
      // Not one of this provider's templates: nothing to say about this URI.
      return NULL;
    }
    $data = $this->fetch($match['name'], $match['id']);
    if ($data === NULL || isset($data['error'])) {
      // Well-formed URI, but the id does not resolve: signal not-found so the
      // SDK returns a clean resource-not-found rather than a generic error.
      throw new ResourceNotFoundException($uri);
    }
    return $this->jsonContent($uri, $data, $this->dataCacheTags());
  }

  /**
   * {@inheritdoc}
   *
   * Reads are open to any client that can reach the server, consistent with the
   * read tools and concrete resource providers. Forbids URIs that match none of
   * this provider's templates.
   */
  public function checkAccess(string $uri, AccountInterface $account): AccessResultInterface {
    if ($this->matchUri($uri) === NULL) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'access mcp server')
      ->cachePerPermissions();
  }

  /**
   * Matches a concrete URI against this provider's templates.
   *
   * @param string $uri
   *   The requested resource URI.
   *
   * @return array{name: string, id: string}|null
   *   The matched template `name` and captured `id`, or NULL if no template
   *   matches.
   */
  private function matchUri(string $uri): ?array {
    foreach ($this->templates() as $template) {
      if (preg_match($template['pattern'], $uri, $matches) === 1) {
        return ['name' => $template['name'], 'id' => $matches[1]];
      }
    }
    return NULL;
  }

}
