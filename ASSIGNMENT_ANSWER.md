# Market Feed Assignment Answer

## Part A — Drupal custom module

### Implementation summary

The custom `market_feed` module is located at `web/modules/custom/market_feed`.

It provides:

- A `market_feed_prices` table for the latest price per symbol.
- A permission-protected `POST /api/market-feed/import` endpoint.
- An injectable `MarketFeedImporter` service.
- Batched multi-row upserts with one transaction per batch.
- Row-level validation and structured errors.
- A permission-protected `/market-feed` page.
- Symbol filtering, pagination, cache contexts, and cache-tag invalidation.

### Database design

The table contains:

```text
market_feed_prices
├── symbol             VARCHAR(16), primary key
├── exchange           VARCHAR(16), defaults to other
├── price              DECIMAL(20,6)
├── change_amount      DECIMAL(20,6)
├── volume             BIGINT UNSIGNED
├── created_at         INT UNSIGNED
└── updated_at         INT UNSIGNED
```

`symbol` is the primary key because Part A requires one latest row per symbol. The `exchange` column is indexed and is optional in the input; missing or blank values are stored as `other`.

The production multi-exchange design would use `(exchange, symbol)` as the key. Part A keeps `symbol` unique to match the assignment contract.

### Import behavior

The input is a JSON array. The required input fields are `symbol`, `price`, `change`, and `volume`. `exchange` is optional.

Validation rules:

- `symbol` must be present, non-empty, and no longer than 16 characters.
- `symbol` is normalized to uppercase.
- `price` must be numeric.
- `change` must be numeric and is stored as `change_amount`.
- `volume` must be a non-negative integer.
- `exchange`, when supplied, must be no longer than 16 characters.
- Missing or blank `exchange` becomes `other`.

Invalid rows are skipped without aborting valid rows. The response includes an `import_id`, counts, and row-level errors:

```json
{
	"import_id": "9cc2aa2de2b28ac5",
	"imported": 48,
	"skipped": 1,
	"errors": [
		{
			"row": 3,
			"error": "Price must be numeric."
		}
	]
}
```

Duplicate symbols in one payload use the last valid occurrence. This is deterministic because the Part A input does not provide event-time or sequence metadata. A production feed should use vendor ordering metadata instead.

Valid rows are deduplicated and written in batches. The batch size is configurable through `MARKET_FEED_BATCH_SIZE`, defaults to 500, and is limited to 1–5000. Each batch has its own transaction, so a failed batch can roll back without discarding successful batches.

For a new symbol, `created_at` and `updated_at` are set. On an update, `created_at` is preserved and `updated_at` is refreshed.

### Security

The import route requires the `import market feed prices` permission. The display page requires `view market feed prices`.

For a real machine-to-machine integration, the caller should use an authenticated service identity over TLS, with a narrowly scoped permission. An API gateway or OAuth2/API-key validation layer could authenticate the caller before the Drupal route is reached. Credentials must not be stored in the repository.

### Caching and display

The `/market-feed` page:

- Uses the `url.query_args:symbol` cache context.
- Uses the `url.query_args:page` cache context for pagination.
- Uses the `market_feed_prices` cache tag.
- Invalidates that tag after a successful import.
- Displays 15 rows per page.
- Provides a symbol filter.

The page uses a small module-scoped stylesheet for readability. No site-wide theme changes are required.

## Part B — Production architecture

### Ingestion architecture

Use an authenticated vendor push endpoint as the primary ingestion path. Authenticate requests with mTLS or signed requests, validate the envelope, assign or record a vendor batch ID, and publish valid updates to a durable queue.

Consumers read from the queue and update a latest-price store. The queue absorbs vendor bursts and provides backpressure. Repeated failures go to a dead-letter queue for inspection and replay.

Every update should include:

- Exchange
- Symbol
- Vendor event time
- Received time
- Vendor batch ID
- Sequence number when available

Duplicate delivery is expected. The consumer should be idempotent and reject an older event or sequence from overwriting a newer value.

### Storage and API performance

Use separate storage models for current and historical data:

```text
latest_prices
├── exchange
├── symbol
├── price
├── change_amount
├── volume
├── event_time
├── received_at
└── sequence
```

The latest table should use `(exchange, symbol)` as its primary key. Add an index for global symbol lookups if required.

Historical data belongs in a separate `historical_prices` table, indexed by exchange, symbol, and event time. The nightly job writes end-of-day rows from the latest or validated event store.

The mobile API should read the latest-price store directly through an indexed query, use connection pooling, and cache popular symbol sets. A read replica or dedicated read model can support the 200 requests/second peak. Drupal should not be the only API-serving layer for that traffic.

### Caching

Use several cache layers:

1. Drupal render cache for website pages.
2. Application cache for latest-price API responses.
3. Reverse proxy/CDN caching for short-lived public responses.

After a successful latest-price commit, invalidate a feed-generation key or granular exchange/symbol cache tags. A short API TTL, such as 5–15 seconds, limits stale data while avoiding a cache purge for every individual update.

The Drupal implementation uses the `market_feed_prices` cache tag and query-argument contexts. At production scale, tags can be made more granular by exchange or symbol.

### Failure handling

If the vendor feed is unavailable for 20 minutes:

- Monitor feed heartbeat and freshness.
- Mark the exchange as stale.
- Continue showing the last known value with its timestamp.
- Alert operators.
- Retry with backoff.
- Reconcile with a full snapshot when the vendor recovers.

Before committing a snapshot, validate required fields, price ranges, symbol coverage, timestamps, row counts, and aggregate checks. A corrupt snapshot should be quarantined rather than replacing the last known good snapshot.

If bad data is discovered after commit, stop downstream publication, restore the last known good version, and replay valid corrections from the durable queue. Preserve audit metadata for each correction.

### Explicit trade-off

Choose a durable queue plus a relational latest-price store instead of writing directly from the vendor request into Drupal’s database.

Direct writes are simpler, but they couple vendor latency and burst size to web-request capacity and make replay difficult. A queue adds operational complexity, but provides backpressure, retry behavior, dead-letter handling, and idempotent replay. Queue workers are described here but intentionally not implemented in Part A because they are explicitly out of scope.

## Part C — Merge request review

### Summary comment

I would not approve this merge request yet. The endpoint has merge-blocking issues around authorization, SQL injection, and unescaped output. It also performs an N+1 query pattern and mixes database access with HTML rendering, which will make the behavior harder to test and maintain.

The main fixes I would request are:

1. Use the authenticated user identity rather than `$_GET['user_id']`, and enforce authorization for the requested portfolio.
2. Replace string-concatenated SQL with parameterized queries.
3. Replace the per-row price query with one set-based query or a preloaded price map.
4. Escape output or return structured data to a template/API serializer.
5. Add query and result error handling, including the case where no historical price exists.

### Line comments

**`function get_portfolio($db, $user_id)` — [Blocker]**

The function accepts `$user_id` but does not use it. Instead, it trusts `$_GET['user_id']`. That creates a risk: a caller could request another user’s portfolio by changing the query parameter. Please derive the user identity from the authenticated request context and authorize access to that portfolio. If this is an administrative endpoint, make that permission explicit.

**`"SELECT * FROM portfolios WHERE user_id = " . $_GET['user_id']` — [Blocker]**

This concatenates untrusted request input into SQL and is vulnerable to SQL injection. The issue is the string concatenation of untrusted data. Use the project’s database abstraction or a prepared `mysqli` statement with a validated integer parameter. Also select only the columns required by the response instead of `SELECT *`.

**`mysqli_query(...)` — [High]**

The query result is used without checking whether the query succeeded. A database error would produce warnings or invalid behavior instead of a controlled response. Please handle query failures and return the endpoint’s normal error format.

**`while (...)` plus the nested `historical_prices` query — [High]**

This is an N+1 query pattern: one query loads the portfolio and then one additional query runs for every holding. It will become slow as the portfolio grows and increases database load. Fetch the latest price set-based, for example with a join to a derived latest-price query/window function, or load the required symbols once and build an in-memory map.

**`WHERE symbol = '" . $row['symbol'] . "'` — [Blocker]**

The symbol is also concatenated directly into SQL, so this query is independently vulnerable to SQL injection. It must use a parameterized query even if the value came from the database, because database values should not be treated as trusted SQL syntax.

**`ORDER BY trade_date DESC LIMIT 1` — [High]**

Please confirm the intended market-data semantics. This selects the latest historical price globally for the symbol, with no exchange or portfolio valuation timestamp. If the same symbol exists on multiple exchanges, or if the portfolio is valued as of a particular time, this can return the wrong price. The query should include the relevant exchange and valuation/as-of-time rules.

**`$row['last_price'] = $price['close'];` — [High]**

`mysqli_fetch_assoc()` can return `null` when no historical price exists, so this can trigger an undefined access. Decide whether the holding should be omitted, reported as unavailable, or cause a structured partial-result error. Do not silently treat a missing price as zero.

**`echo "<h2>Portfolio for user " . $_GET['user_id'] . "</h2>"` — [Blocker]**

The request value is written directly into HTML and can cause reflected XSS. Escape it at the output boundary, or preferably keep this function responsible for data retrieval and let a template/render layer handle output escaping.

**`echo "<div>" . $r['symbol'] . ": ...` — [High]**

The symbol is also rendered without escaping. Even if it currently comes from a database, output should be escaped at the HTML boundary. The function should not concatenate HTML at all; return structured portfolio data and render it through the application’s normal templating mechanism.

**`$r['qty'] * $r['last_price']` — [Medium]**

Please define the numeric and rounding rules for portfolio value. Financial values should not rely on implicit PHP type coercion and binary floating-point behavior. Validate quantity and price, use the project’s decimal/money convention, and define the displayed precision and rounding mode.

Once the blocking security and correctness issues are addressed, I would be happy to re-review the performance and presentation refinements.

## Part D — SQL

The examples below use MySQL 8+/MariaDB-compatible SQL with CTEs and window functions.

### 1. Day-over-day movers

The previous close must come from the previous stored row for that symbol, not from `trade_date - 1`. This handles weekends, holidays, and suspensions.

```sql
WITH prices_with_previous AS (
  SELECT
    symbol,
    trade_date,
    close,
    LAG(close) OVER (
      PARTITION BY symbol
      ORDER BY trade_date
    ) AS previous_close
  FROM historical_prices
  WHERE trade_date <= :as_of_date
),
movers AS (
  SELECT
    symbol,
    trade_date,
    close,
    previous_close,
    CASE
      WHEN previous_close IS NULL OR previous_close = 0 THEN NULL
      ELSE ((close - previous_close) / previous_close) * 100
    END AS percentage_change
  FROM prices_with_previous
  WHERE trade_date = :as_of_date
)
SELECT
  symbol,
  close,
  previous_close,
  percentage_change
FROM movers
ORDER BY ABS(percentage_change) DESC, symbol;
```

The `previous_close = 0` guard avoids division by zero. A row with no previous observation has a `NULL` percentage change and sorts last in MySQL/MariaDB when ordering descending.

### 2. Gap detection during June 2026

This treats a date as a market-wide trading date when more than one symbol has a row on that date. It then checks every symbol seen during June against those trading dates.

```sql
WITH june_prices AS (
  SELECT symbol, trade_date
  FROM historical_prices
  WHERE trade_date >= '2026-06-01'
    AND trade_date < '2026-07-01'
),
trading_dates AS (
  SELECT trade_date
  FROM june_prices
  GROUP BY trade_date
  HAVING COUNT(DISTINCT symbol) > 1
),
june_symbols AS (
  SELECT DISTINCT symbol
  FROM june_prices
),
missing_rows AS (
  SELECT
    s.symbol,
    d.trade_date
  FROM june_symbols s
  CROSS JOIN trading_dates d
  LEFT JOIN june_prices p
    ON p.symbol = s.symbol
   AND p.trade_date = d.trade_date
  WHERE p.symbol IS NULL
)
SELECT symbol, trade_date
FROM missing_rows
ORDER BY trade_date, symbol;
```

This reports expected symbol/date combinations that are absent. If the business has an official exchange calendar, that calendar should replace the inferred `trading_dates` CTE. The query currently treats a symbol with no June rows as outside the June universe.

### 3. Slow query optimization

Original query:

```sql
SELECT *
FROM historical_prices
WHERE YEAR(trade_date) = 2026
  AND symbol LIKE '%BANK%'
ORDER BY volume DESC
LIMIT 100;
```

Problems:

- `YEAR(trade_date)` applies a function to the indexed column, so a normal `trade_date` index cannot be used efficiently for the range predicate.
- `LIKE '%BANK%'` has a leading wildcard, so a normal B-tree index on `symbol` cannot seek to the matching values.
- `SELECT *` reads more data than the result needs.
- The database may need to examine and sort many matching rows to find the top 100 by volume.
- On a 500-million-row table, these costs become substantial.

At minimum, make the date predicate sargable and select only required columns:

```sql
SELECT symbol, trade_date, close, volume
FROM historical_prices
WHERE trade_date >= '2026-01-01'
  AND trade_date < '2027-01-01'
  AND symbol LIKE '%BANK%'
ORDER BY volume DESC
LIMIT 100;
```

This allows partition pruning or a usable date range scan, but it does not make the leading-wildcard search fast.

The preferred schema change depends on the intended meaning of `BANK`:

- If the requirement is a prefix search, change the predicate to `symbol LIKE 'BANK%'` and use an index beginning with `symbol`. A composite index such as `(symbol, trade_date, volume)` can help filter the symbols and year, although the database may still sort by volume.
- If arbitrary substring search is required, use a search-oriented structure rather than expecting a B-tree to solve it. Options include a full-text/ngram index where supported, or a separate normalized symbol-search table containing searchable tokens/prefixes mapped to symbols. Keep the historical table optimized for time-series access.
- For a very large table, partition by a bounded date period such as year or month so the 2026 predicate scans only relevant partitions. Partitioning does not solve the `%BANK%` search by itself; it only reduces the time range scanned.

Assumptions: `symbol` is a short identifier, the query genuinely requires substring matching, and the result needs only the listed columns. I would confirm the actual query plan with `EXPLAIN`/`EXPLAIN ANALYZE` before choosing a final index, because an index that helps filtering can still hurt or fail to help the `ORDER BY volume` operation.
