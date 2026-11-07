<?php

namespace Drupal\market_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\market_feed\Service\MarketFeedImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Provides the market-feed import endpoint. */
final class MarketFeedImportController extends ControllerBase {

  public function __construct(
    private readonly MarketFeedImporter $importer,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('market_feed.importer'));
  }

  public function import(Request $request): JsonResponse {
    try {
      $payload = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return new JsonResponse([
        'import_id' => NULL,
        'imported' => 0,
        'skipped' => 1,
        'errors' => [
          ['row' => 0, 'error' => 'Request body must contain valid JSON.'],
        ],
      ], 400);
    }

    return new JsonResponse($this->importer->import($payload));
  }

}

