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

    $pager = $query
      ->extend('Drupal\\Core\\Database\\Query\\PagerSelectExtender')
      ->limit(15);
    $rows = [];
    foreach ($pager->execute() as $price) {
      $rows[] = [
        ['data' => ['#plain_text' => $price->symbol]],
        ['data' => ['#plain_text' => $price->exchange]],
        ['data' => ['#plain_text' => number_format((float) $price->price, 2, '.', ',')]],
        ['data' => ['#plain_text' => number_format((float) $price->change_amount, 2, '.', ',')]],
        ['data' => ['#plain_text' => number_format((int) $price->volume, 0, '.', ',')]],
        ['data' => ['#plain_text' => date('M j, Y g:i A', (int) $price->updated_at)]],
      ];
    }

    return [
      '#attached' => [
        'library' => ['market_feed/page'],
      ],
      'filter' => [
        '#type' => 'inline_template',
        '#template' => '<form method="get" class="market-feed-filter"><label for="market-feed-symbol">Filter by symbol</label> <input id="market-feed-symbol" name="symbol" value="{{ symbol|e }}" placeholder="AAPL"> <button type="submit">Filter</button>{% if symbol %}<a href="{{ path }}">Clear</a>{% endif %}</form>',
        '#context' => ['symbol' => $symbol, 'path' => '/market-feed'],
      ],
      'prices' => [
        '#type' => 'table',
        '#caption' => $this->t('Latest market prices'),
        '#attributes' => ['class' => ['market-feed-table']],
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
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'contexts' => ['url.query_args:symbol', 'url.query_args:page'],
        'tags' => ['market_feed_prices'],
      ],
    ];
  }

}

