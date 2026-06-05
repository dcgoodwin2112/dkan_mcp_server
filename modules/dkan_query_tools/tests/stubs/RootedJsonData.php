<?php

namespace RootedData;

/**
 * Stub for RootedData\RootedJsonData.
 */
class RootedJsonData {

  /**
   * The backing JSON string.
   *
   * @var string
   */
  protected string $json;

  public function __construct(string $json = '{}', $schema = '{}') {
    $this->json = $json;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return $this->json;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $path, $value): void {
    if ($path === '$') {
      $this->json = json_encode($value);
    }
  }

}
