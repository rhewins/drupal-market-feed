<?php

namespace Drupal\market_feed\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;

/** Imports validated market rows in bounded multi-row transactions. */
final class MarketFeedImporter {

  private const DEFAULT_BATCH_SIZE = 500;
  private const MAX_BATCH_SIZE = 5000;

  public function __construct(
    private readonly Connection $database,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Imports a JSON-decoded payload.
   *
   * @param mixed $payload
   *   The decoded JSON payload.
   *
   * @return array{import_id: string, imported: int, skipped: int, errors: array<int, array{row: int|string, error: string}>}
   *   The import result.
   */
  public function import(mixed $payload): array {
    $importId = bin2hex(random_bytes(8));
    $errors = [];

    if (!is_array($payload) || array_is_list($payload) === FALSE) {
      return [
        'import_id' => $importId,
        'imported' => 0,
        'skipped' => 1,
        'errors' => [['row' => 0, 'error' => 'Payload must be a JSON array.']],
      ];
    }

    $validRows = [];
    foreach ($payload as $index => $row) {
      $validated = $this->validateRow($row);
      if ($validated['row'] === NULL) {
        $errors[] = ['row' => $index, 'error' => $validated['error']];
        continue;
      }

      // The last valid occurrence wins for duplicate symbols in this payload.
      $validRows[$validated['row']['symbol']] = $validated['row'];
    }

    $imported = 0;
    foreach (array_chunk(array_values($validRows), $this->batchSize()) as $batch) {
      $transaction = $this->database->startTransaction();
      try {
        $values = [];
        $arguments = [];
        foreach ($batch as $index => $row) {
          $suffix = $imported + $index;
          $values[] = '(:symbol_' . $suffix . ', :exchange_' . $suffix . ', :price_' . $suffix . ', :change_' . $suffix . ', :volume_' . $suffix . ', :created_' . $suffix . ', :updated_' . $suffix . ')';
          $arguments[':symbol_' . $suffix] = $row['symbol'];
          $arguments[':exchange_' . $suffix] = $row['exchange'];
          $arguments[':price_' . $suffix] = $row['price'];
          $arguments[':change_' . $suffix] = $row['change_amount'];
          $arguments[':volume_' . $suffix] = $row['volume'];
          $arguments[':created_' . $suffix] = $row['created_at'];
          $arguments[':updated_' . $suffix] = $row['updated_at'];
        }

        $this->database->query(
          'INSERT INTO {market_feed_prices} (symbol, exchange, price, change_amount, volume, created_at, updated_at) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE exchange = VALUES(exchange), price = VALUES(price), change_amount = VALUES(change_amount), volume = VALUES(volume), updated_at = VALUES(updated_at)',
          $arguments,
        );
        unset($transaction);
        $imported += count($batch);
      }
      catch (\Throwable $exception) {
        $errors[] = ['row' => 'batch', 'error' => 'Batch failed: ' . $exception->getMessage()];
      }
    }

    if ($imported > 0) {
      $this->cacheTagsInvalidator->invalidateTags(['market_feed_prices']);
    }

    return [
      'import_id' => $importId,
      'imported' => $imported,
      'skipped' => count($errors),
      'errors' => $errors,
    ];
  }

  /**
   * @param mixed $row
   * @return array{row: array<string, mixed>|null, error: string}
   */
  private function validateRow(mixed $row): array {
    if (!is_array($row) || !isset($row['symbol'], $row['price'], $row['change'], $row['volume'])) {
      return ['row' => NULL, 'error' => 'Required fields are missing.'];
    }

    $symbol = strtoupper(trim((string) $row['symbol']));
    $exchange = trim((string) ($row['exchange'] ?? 'other'));
    if ($exchange === '') {
      $exchange = 'other';
    }

    if ($symbol === '' || strlen($symbol) > 16) {
      return ['row' => NULL, 'error' => 'Symbol must be present and at most 16 characters.'];
    }
    if (!is_numeric($row['price'])) {
      return ['row' => NULL, 'error' => 'Price must be numeric.'];
    }
    if (!is_numeric($row['change'])) {
      return ['row' => NULL, 'error' => 'Change must be numeric.'];
    }
    if (filter_var($row['volume'], FILTER_VALIDATE_INT) === FALSE || (int) $row['volume'] < 0) {
      return ['row' => NULL, 'error' => 'Volume must be a non-negative integer.'];
    }
    if (strlen($exchange) > 16) {
      return ['row' => NULL, 'error' => 'Exchange must be at most 16 characters.'];
    }

    $now = time();
    return [
      'row' => [
        'symbol' => $symbol,
        'exchange' => $exchange,
        'price' => (float) $row['price'],
        'change_amount' => (float) $row['change'],
        'volume' => (int) $row['volume'],
        'created_at' => $now,
        'updated_at' => $now,
      ],
      'error' => '',
    ];
  }

  private function batchSize(): int {
    $batchSize = getenv('MARKET_FEED_BATCH_SIZE');
    if ($batchSize === FALSE || !ctype_digit($batchSize)) {
      return self::DEFAULT_BATCH_SIZE;
    }

    $batchSize = (int) $batchSize;
    return $batchSize >= 1 && $batchSize <= self::MAX_BATCH_SIZE ? $batchSize : self::DEFAULT_BATCH_SIZE;
  }

}

