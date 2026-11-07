<?php

namespace Drupal\market_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/** Displays the latest market prices. */
final class MarketFeedController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function page(Request $request): array {
    $symbol = strtoupper(trim((string) $request->query->get('symbol', '')));
    $query = $this->database->select('market_feed_prices', 'prices')
      ->fields('prices', ['symbol', 'exchange', 'price', 'change_amount', 'volume', 'updated_at'])
      ->orderBy('symbol');

    if ($symbol !== '') {
      $query->condition('symbol', $symbol);
    }

    $rows = [];
    foreach ($query->execute() as $price) {
      $rows[] = [
        ['data' => ['#plain_text' => $price->symbol]],
        ['data' => ['#plain_text' => $price->exchange]],
        ['data' => ['#plain_text' => $price->price]],
        ['data' => ['#plain_text' => $price->change_amount]],
        ['data' => ['#plain_text' => $price->volume]],
        ['data' => ['#plain_text' => date('c', (int) $price->updated_at)]],
      ];
    }

    return [
      'filter' => [
        '#type' => 'inline_template',
        '#template' => '<form method="get"><label for="market-feed-symbol">Symbol</label> <input id="market-feed-symbol" name="symbol" value="{{ symbol|e }}"> <button type="submit">Filter</button></form>',
        '#context' => ['symbol' => $symbol],
      ],
      'prices' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Symbol'),
          $this->t('Exchange'),
          $this->t('Price'),
          $this->t('Change'),
          $this->t('Volume'),
          $this->t('Updated'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No market prices found.'),
      ],
      '#cache' => [
        'contexts' => ['url.query_args:symbol'],
        'tags' => ['market_feed_prices'],
      ],
    ];
  }

}

