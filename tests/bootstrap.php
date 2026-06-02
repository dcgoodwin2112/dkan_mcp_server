<?php

/**
 * @file
 * Bootstrap for dkan_mcp_server PHPUnit unit tests.
 *
 * Runs under the site-level PHPUnit. The site Composer autoloader already
 * resolves Mcp\, Drupal\Core\, and Drupal\Component\; this bootstrap loads it,
 * then registers the contrib/custom module namespaces that Drupal would
 * normally register at runtime, plus this module's own classes and tests.
 */

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$module = dirname(__DIR__);
$contrib = dirname(__DIR__, 3) . '/contrib';
$custom = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($module, $contrib, $custom): void {
  $prefixes = [
    'Drupal\\dkan_mcp_server\\' => $module . '/src/',
    'Drupal\\Tests\\dkan_mcp_server\\' => __DIR__ . '/src/',
    'Drupal\\mcp_server\\' => $contrib . '/mcp_server/src/',
    'Drupal\\dkan_query_tools\\' => $custom . '/dkan_query_tools/src/',
    'Drupal\\dkan_harvest\\' => $contrib . '/dkan/modules/dkan_harvest/src/',
    'Drupal\\dkan_metastore\\' => $contrib . '/dkan/modules/dkan_metastore/src/',
  ];
  foreach ($prefixes as $prefix => $baseDir) {
    if (str_starts_with($class, $prefix)) {
      $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (is_file($file)) {
        require $file;
      }
      return;
    }
  }
});
