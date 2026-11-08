# Drupal Market Feed

## Project description

This is a Drupal custom module that will ingest stock price data, store the latest price per symbol, and display the stored prices through Drupal.

## Technology stack

- Drupal 11
- PHP 8.3+
- MariaDB
- DDEV
- Composer

## How to run

The local development environment is managed with DDEV. Before starting, install:

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [DDEV](https://ddev.com/get-started/)

### Start the project for the first time

Open a terminal and run these commands one at a time:

1. Download the project:

   ```bash
   git clone https://github.com/rhewins/drupal-market-feed.git
   ```

2. Enter the project folder:

   ```bash
   cd drupal-market-feed
   ```

3. Create the local configuration file:

   ```bash
   cp .env.example .env
   ```

   For this test project, `.env.example` contains the demo Drupal administrator username and password. The values are named `DRUPAL_ADMIN_USER` and `DRUPAL_ADMIN_PASSWORD`.

   `MARKET_FEED_BATCH_SIZE` controls how many valid rows are written per database transaction. The default is `500`; it must be between `1` and `5000`.

4. Start and install the project:

   ```bash
   make setup
   ```

   The setup command starts Drupal and MariaDB, installs the required dependencies, and installs Drupal automatically.

5. Enable the Market Feed module:

   ```bash
   ddev drush en market_feed -y
   ```

6. Confirm that the module is enabled:

   ```bash
   ddev drush pm:list --type=module --status=enabled | grep market_feed
   ```

7. Open the URL shown by DDEV, usually:

   [http://drupal-market-feed.ddev.site](http://drupal-market-feed.ddev.site)

Use the administrator username and password from `.env` to sign in.

The current demo password is for demonstration purposes only. Change it in `.env` before initialization if needed, and never use this demo password in a real development or production environment. In a real development project, `.env.example` should contain only a placeholder, while the actual password should be supplied through the uncommitted local `.env` file or a proper secret-management system.

### Run the demo import

The sample payload is stored in `docs/market-feed-import.json`. Drush runs from Drupal's `web` directory, so use the relative path `../docs/market-feed-import.json`:

```bash
ddev drush php:eval '$payload = json_decode(file_get_contents("../docs/market-feed-import.json"), TRUE, 512, JSON_THROW_ON_ERROR); echo json_encode(\Drupal::service("market_feed.importer")->import($payload), JSON_PRETTY_PRINT), PHP_EOL;'
```

The demo contains 50 rows: 48 distinct valid symbols, one duplicate symbol where the last occurrence wins, one missing exchange that falls back to `other`, and one invalid row that is skipped.

To inspect the imported values:

```bash
ddev drush sql:query "SELECT symbol, exchange, price, change_amount, volume FROM market_feed_prices ORDER BY symbol"
```

If you change `MARKET_FEED_BATCH_SIZE` in `.env` after the project is running, restart the project so PHP receives the new value:

```bash
make restart
```

Restarting stops and starts the containers without deleting the Drupal database or module files.

### Useful commands

```bash
make start
make stop
make restart
make logs
make status
make shell
make drush
```

## Assumptions and trade-offs

### Part A — Drupal module

- The `market_feed_prices` table stores the latest value per symbol, not a historical time series.
- `symbol` is the primary key because the assignment requires it to be unique. A production multi-exchange model would use `(exchange, symbol)` instead.
- `exchange` is optional in the input and defaults to `other` when missing or blank.
- The input field `change` is stored as `change_amount`.
- Duplicate symbols in one payload use the last valid occurrence because Part A does not provide event-time or sequence metadata.
- Invalid rows are skipped and reported; valid rows continue to import.
- Valid rows are written in configurable batches with one transaction per batch. This reduces database round-trips and bounds rollback size, but allows partial success when a later batch fails.
- The importer uses MariaDB upsert syntax to demonstrate efficient multi-row writes. This favors the agreed DDEV database and performance requirement over complete database-driver portability.
- Failed rows are returned in the response but are not persisted in an error table. Durable retry tracking belongs in the production architecture.
- Drupal render cache uses the `market_feed_prices` cache tag and query-argument cache contexts. A production system may use more granular tags or an application cache.

### Part B — Production architecture

- The vendor feed is assumed to support authenticated push delivery and batch or sequence metadata.
- A durable queue is preferred over direct vendor-to-Drupal writes because it provides backpressure, retry, replay, and dead-letter handling.
- Latest prices and historical prices use separate storage models because their read and write patterns differ.
- Redis, a queue, read replicas, and a CDN are proposed production components, not requirements for the Part A local module.
- The design favors operational resilience and replayability over the lower operational complexity of direct database writes.

### Part C — Review

- The review assumes the endpoint is user-scoped and that portfolio data must not be readable by another user.
- The review treats SQL injection, authorization failures, and unescaped output as merge blockers.
- The proposed replacement should use the project’s database abstraction or prepared statements, a set-based latest-price query, and a normal rendering/API layer.

### Part D — SQL

- The SQL examples assume MySQL 8+ or MariaDB with CTE and window-function support.
- The day-over-day query uses the previous stored row per symbol, which handles holidays and suspensions.
- Gap detection infers market-wide trading dates from dates on which more than one symbol traded. An official exchange calendar would be more authoritative.
- The optimization recommendation assumes arbitrary substring search is genuinely required. If prefix search is sufficient, `LIKE 'BANK%'` and a B-tree index are preferable.

## Automated test strategy for Part A

Automated tests are not required by the assignment, but I would implement the following layered test strategy in a production version.

### Unit tests

Test the importer’s validation and normalization behavior without a real database:

- Valid row acceptance.
- Missing and blank symbols.
- Symbol length limits and uppercase normalization.
- Numeric price and change validation.
- Integer and non-negative volume validation.
- Exchange fallback to `other`.
- Exchange length validation.
- Last valid duplicate occurrence wins.
- Invalid rows produce row-indexed errors without stopping other rows.
- Batch-size configuration falls back safely for invalid values.

### Functional tests

Use Drupal’s HTTP test framework to verify routes and permissions:

- Import route rejects users without `import market feed prices`.
- Display route rejects users without `view market feed prices`.
- Valid JSON returns the structured import response.
- Invalid JSON returns an appropriate client error.
- Mixed valid and invalid rows return partial success and errors.
- Symbol filtering returns only the requested symbol.
- Pagination returns the expected 15-row pages.
- A successful import invalidates stale display output.

### Static and manual checks

- PHP syntax and Drupal coding-standard checks.
- `git diff --check`.
- Manual import using `docs/market-feed-import.json`.
- Manual verification of MariaDB values and indexes.
- Fresh-clone setup using `make setup`.

## Approximate time allocation

The assignment timeboxes and approximate implementation effort are:

| Part                            | Approximate time |
| ------------------------------- | ---------------: |
| Infrastructure and Drupal setup |    20–30 minutes |
| Part A — Custom module          |       75 minutes |
| Part B — Architecture write-up  |       30 minutes |
| Part C — Merge request review   |       25 minutes |
| Part D — SQL                    |       20 minutes |

These are approximate working allocations rather than measured wall-clock timings. The implementation intentionally prioritizes the required vertical slice and documents production extensions instead of implementing queue workers, historical ingestion, or a separate mobile API.
