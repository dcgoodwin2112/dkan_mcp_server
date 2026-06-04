<?php

declare(strict_types=1);

namespace Drupal\dkan_mcp_server\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\mcp_server\Resource\CacheableResourceContent;

/**
 * Shapes DKAN data into a JSON MCP resource payload.
 *
 * Shared by the concrete (ResourceProvider) and templated
 * (ResourceTemplateProvider) DKAN bases so both serialize identically. Content
 * is cached permanently and invalidated by the DKAN cache tags the caller
 * supplies (RESOURCES_PLAN Phase 3); pass no tags for effectively static data
 * (e.g. schema definitions, which change only on deploy).
 */
trait ResourceJsonContentTrait {

  /**
   * Wraps a value as a JSON resource payload with cache-tag invalidation.
   *
   * @param string $uri
   *   The resource URI being served.
   * @param mixed $data
   *   The value to JSON-encode as the resource text.
   * @param string[] $cacheTags
   *   DKAN cache tags that invalidate this content when the backing data
   *   changes (e.g. node_list:data). Empty for static data.
   *
   * @return \Drupal\mcp_server\Resource\CacheableResourceContent
   *   The resource content DTO.
   */
  protected function jsonContent(string $uri, mixed $data, array $cacheTags = []): CacheableResourceContent {
    // JSON_INVALID_UTF8_SUBSTITUTE keeps malformed UTF-8 in imported catalog
    // data from failing the encode; JSON_THROW_ON_ERROR turns any remaining
    // failure into a controlled error payload rather than a non-string `text`.
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
      | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    try {
      $text = json_encode($data, $flags);
    }
    catch (\JsonException $e) {
      $text = json_encode(
        ['error' => 'Resource content could not be encoded as JSON.'],
        JSON_THROW_ON_ERROR,
      );
    }
    $content = [
      'uri' => $uri,
      'mimeType' => 'application/json',
      'text' => $text,
    ];
    // No max-age override: with tags, content caches permanently and is busted
    // by tag invalidation; with no tags, it is effectively static (cleared on a
    // cache rebuild). Vary by permission since reads are gated.
    $metadata = (new CacheableMetadata())
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags($cacheTags);
    return CacheableResourceContent::fromArray($content, $metadata);
  }

}
