<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_mcp_server\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mcp_server\Plugin\PromptArgumentCompletionProviderInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Boots a real container and verifies the dataset_id completer is wired.
 *
 * Proves the #[PromptArgumentCompletionProvider] plugin discovers and
 * instantiates through dependency injection (resolving the injected
 * dkan_query_tools.metastore and .search services) on the real manager. The
 * suggestion content itself is covered by the unit test and the live
 * verification — exercising it here would test DKAN's metastore, not this
 * module's wiring.
 *
 * @group dkan_mcp_server
 */
#[RunTestsInSeparateProcesses]
class PromptCompletionDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Mirrors ResourceDiscoveryTest: every module whose services are referenced
   * while the container builds is listed explicitly.
   */
  protected static $modules = [
    'node',
    'user',
    'dkan_common',
    'dkan_metastore',
    'dkan_datastore',
    'dkan_harvest',
    'search_api',
    'dkan_metastore_search',
    'dkan_query_tools',
    'mcp_server',
    'dkan_mcp_server',
  ];

  /**
   * The completer discovers and instantiates through DI.
   */
  public function testCompleterDiscoversAndInstantiates(): void {
    $manager = $this->container
      ->get('plugin.manager.mcp_server.prompt_argument_completion_provider');

    $this->assertArrayHasKey(
      'dkan_dataset_id',
      $manager->getDefinitions(),
      "Completion provider 'dkan_dataset_id' was not discovered.",
    );

    $plugin = $manager->createInstance('dkan_dataset_id');
    $this->assertInstanceOf(PromptArgumentCompletionProviderInterface::class, $plugin);
  }

}
