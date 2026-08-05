# Tupay — Cross-Border NGN/CNY Ledger & Settlement Engine

A production-grade backend for cross-border money movement between Nigeria (NGN) and China (CNY): Step-Up 2FA-gated swaps, a strict double-entry ledger with zero float in the money path, deterministic distributed locking, and idempotent settlement webhooks — built and verified against real Postgres and Redis throughout, not mocked infrastructure.

## Table of contents

- [Quick start](#quick-start)
- [Architecture](#architecture)
- [Step-Up 2FA: the Elevated Action Token](#step-up-2fa-the-elevated-action-token)
- [Double-entry ledger design](#double-entry-ledger-design)
- [Deadlock prevention and lock ordering](#deadlock-prevention-and-lock-ordering)
- [Database indexing rationale](#database-indexing-rationale)
- [Rate engine: stale-while-revalidate](#rate-engine-stale-while-revalidate)
- [Settlement webhooks: idempotency in two independent layers](#settlement-webhooks-idempotency-in-two-independent-layers)
- [The concurrency guarantee, proven](#the-concurrency-guarantee-proven)
- [Testing strategy](#testing-strategy)
- [Known limitations and deliberate scope decisions](#known-limitations-and-deliberate-scope-decisions)
- [Troubleshooting](#troubleshooting)
- [API reference](#api-reference)

---

## Quick start

**Requirements:** PHP 8.2+, Composer 2, Docker Desktop.

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
```

The seeder prints a demo user's email, password, TOTP secret, and wallet IDs to the console — copy these, you'll need them for every authenticated request.

Two application instances are required locally (see [Known limitations](#known-limitations-and-deliberate-scope-decisions) for why):

```bash
php artisan serve --port=8000        # main API
php artisan serve --port=8001        # mock rate provider
```

Verify everything is wired correctly:

```bash
curl http://localhost:8000/api/health
```

`api-test.http` in the project root walks through the full authenticated flow — login, Step-Up challenge, swap, ledger inspection, webhook delivery, and every documented failure case — with inline instructions for the two things that genuinely can't be scripted into a static request file (TOTP codes and HMAC signatures; see the file's header comment for why).

---

## Continuous Integration

Every push runs:

- Laravel Pint
- PHPStan
- Pest
- Live concurrency integration test

---

## Architecture

The codebase is organized around **domains, not framework conventions.** `app/Http` is deliberately thin — controllers do almost nothing beyond translating an HTTP request into a call on a domain service and translating the result back into a response. All real logic lives in `app/Domain`, grouped by bounded context:

app/Domain/
├── Security/ — TOTP verification, Elevated Action Token issuance/consumption
├── Swap/ — rate fetching, rounding, slippage, locking, ledger posting
└── Webhooks/ — settlement ingestion, idempotency, crediting

This separation exists for a specific reason: **every piece of domain logic in this codebase was independently unit- or integration-tested in isolation** — `BankersRounder`, `SlippageCalculator`, `SwapLock`, `ElevatedActionTokenService` — before it was ever wired into an HTTP controller. A fat-controller design would have made that impossible; you cannot unit-test a rounding algorithm that's inlined into a `SwapController::__invoke()` method. The domain/HTTP split isn't cosmetic — it's what made the verification discipline in this project's build possible at all.

**Service classes over static helpers or model-embedded logic.** `SwapService`, `SettlementWebhookProcessor`, and `SettlementCreditor` are constructor-injected, stateless-per-request services — never singletons holding mutable state, never facades hiding untestable side effects. Every one of them is resolvable from the container in isolation, which is what let us prove `SwapService::execute()`'s insufficient-balance and lock-contention paths directly in tests, without needing a full HTTP round trip for every scenario.

**Models stay data-shape-only.** `Wallet::balance()` and `Wallet::lockedBalance()` are the one deliberate exception — balance computation is *intrinsic* to what a wallet is, not business logic bolted onto it, and keeping it on the model means every caller (the ledger endpoint, the swap orchestrator, ad-hoc debugging) gets identical, correct balance semantics for free. Everything else — fee calculation, rate conversion, lock acquisition, ledger posting — lives in domain services.

**No `Actions` layer.** Some Laravel codebases add a single-purpose "Action" class per use case as an additional layer above services. I deliberately didn't: `SwapService::execute()` and `SettlementWebhookProcessor::ingest()` already *are* single-purpose, orchestration-focused entry points into their domains — adding a thin Action wrapper around each would have meant two classes doing the job of one, with no corresponding gain in testability or clarity. Layering for its own sake is a cost, not a virtue.

---

## Step-Up 2FA: the Elevated Action Token

The spec requires that sensitive endpoints (`POST /api/swap`) cannot accept a raw TOTP code directly — instead, TOTP verification produces a short-lived, single-use, cryptographically signed **Elevated Action Token (EAT)** bound to the exact action being authorized.

### Why binding to the action matters

A weaker design would issue a generic "you passed 2FA" token and let the client use it for any subsequent sensitive request. That's a real vulnerability: if an attacker can intercept or manipulate the request *after* 2FA succeeds — a compromised client, a race in a poorly-isolated frontend, a confused-deputy scenario — a generic token lets them redirect an authorized action into an unauthorized one (swap $10 becomes swap $10,000, or swap into wallet A becomes swap into wallet B).

The EAT closes this by binding the token to a **SHA-256 hash of the exact action and its exact parameters**, computed at issuance time:

```php
hash('sha256', json_encode([
    'user_id' => $userId,
    'action' => $action,
    'payload' => $sortedPayload,  // recursively key-sorted
], JSON_UNESCAPED_SLASHES));
```

The payload is recursively key-sorted before hashing (`ActionHasher::sortRecursively()`) so that two logically identical payloads — differing only in key order, which can happen across serialization boundaries or client implementations — always produce the same hash. This was verified directly: two payloads with identical keys in different orders hash identically; changing any single value produces a completely different hash.

At consumption time (`RequireElevatedActionToken` middleware), the server recomputes the same hash over the *actual request body* it received and compares it — via `hash_equals()`, not `===`, to avoid timing side-channels — against what was stored at issuance. A mismatch, for any reason (tampered amount, tampered wallet ID, tampered anything), is rejected with `422`.

### Token structure and signing

The token itself is an opaque, self-contained envelope, not a database row the client references by ID: base64url(json_encode({nonce, user_id, exp})) + "." + HMAC-SHA256(that, EAT_SIGNING_KEY)

`EAT_SIGNING_KEY` is a dedicated secret, **deliberately not derived from or shared with `APP_KEY`** — reusing a single key for two different cryptographic purposes (application encryption vs. token signing) is a key-separation smell; a compromise or rotation of one shouldn't have to consider the other.

### Single-use enforcement: why Redis, and why `GETDEL`

The action hash itself is never embedded in the token — it's stored server-side in Redis, keyed by the token's `nonce`, with a TTL matching the token's validity window (60 seconds). Consumption uses Redis's `GETDEL` command: an atomic read-and-delete in a single round trip.

This atomicity is the entire single-use guarantee. A naive `GET` followed by a separate `DEL` has a race window: two concurrent requests presenting the same token could both pass the `GET` check before either executes the `DEL`, both believing the token is still valid. `GETDEL` closes that window at the Redis protocol level — there is no intermediate state where two callers can both observe the token as "present."

This was proven directly, not just reasoned about: issuing a token, consuming it once (succeeds), then attempting to consume the identical token again (correctly throws `alreadyConsumedOrUnknown`) — and separately, issuing a token and attempting to consume it with a tampered payload (correctly throws `actionMismatch`, distinct from the replay case). Both are automated Pest tests today, not just manual verification.

---

## Double-entry ledger design

### No mutable balance column, by construction

`wallets` has no `balance` column. A wallet's balance is *always* `SUM(credits) - SUM(debits)` over `ledger_entries`, computed on read (`Wallet::balance()`). This isn't a performance compromise accepted for correctness — it's the only design where a balance literally *cannot* drift from its ledger history, because there is nothing to drift: the number is derived, not stored and independently updated. A bug that forgets to update a balance column is a whole class of bug that doesn't exist here.

### Cross-currency postings are two independently balanced legs

A naive double-entry implementation for a same-currency transfer posts one debit and one credit that must sum to zero. A cross-currency swap can't work that way — NGN kobo and CNY fen are not fungible and can never be summed against each other to "prove" a transaction balanced.

Every swap instead posts **two separately balanced legs**, tied together by a shared `transaction_group_id`:

- **Source leg (NGN):** debit the user's source wallet the full amount → credit the platform's NGN FX-clearing wallet the net-of-fee amount → credit the platform's NGN fee-revenue wallet the fee (if any). These three sum to zero within NGN.
- **Destination leg (CNY):** debit the platform's CNY FX-clearing wallet the converted amount → credit the user's destination wallet the same amount. These two sum to zero within CNY.

The platform's FX-clearing wallets are the ledger's designated counterparty for value crossing the currency boundary — economically, they represent the platform's own NGN and CNY liquidity pools funding conversions. This is why they're **deliberately exempted from the overdraft-prevention trigger** (below): a clearing account running temporarily negative between funding events is not a bug, it's the expected behavior of a liquidity account, the same way a market maker's inventory account isn't expected to stay non-negative on every single trade.

### Database-level overdraft guardrail — a second, independent line of defense

Application-level balance checks (`SwapService` validates balance under a row lock before ever posting an entry) are necessary but not, on their own, sufficient — a future code path, a raw SQL migration, or direct database access could bypass application logic entirely. `ledger_entries` therefore carries a `CHECK` constraint on `amount_subunits > 0` plus a Postgres `AFTER INSERT` trigger (`prevent_wallet_overdraft`) that recomputes the affected wallet's real-time balance and raises a hard exception if it would go negative — *at the storage layer*, regardless of what application code did or didn't check.

This defense-in-depth wasn't theoretical: it caught a real bug during development. The first version of the FX-clearing-wallet design didn't account for those wallets needing an overdraft exemption, and the trigger correctly rejected the very first test swap with `wallet 7 overdraft: balance would be -480 subunits` — precisely the scenario the trigger exists to catch, firing exactly as designed on the first genuine attempt to trigger it.

The trigger is label-aware: it looks up the target wallet's `label` column and skips the check entirely for labeled (system) wallets, applying full protection only to ordinary user wallets — the accounts where a negative balance would represent an actual, unacceptable loss of funds.

---

## Deadlock prevention and lock ordering

### The problem

Two concurrent swaps between the same pair of wallets, in *opposite directions* (user A swaps wallet 1→2 while, simultaneously, another request swaps the same user's wallet 2→1), are the textbook deadlock setup: if each request naively locks its own "source" wallet first, then tries to lock its "destination," each can end up holding one lock while waiting on the other, forever.

### The fix: locks are always acquired in the same, sorted order — regardless of transaction direction

`SwapLock::acquire()` builds its lock keys not from "source, then destination" but from a **fixed canonical ordering**: the user ID, then both wallet IDs *sorted ascending*, regardless of which is semantically the source and which is the destination for this particular request.

```php
$sortedWalletIds = [$walletIdA, $walletIdB];
sort($sortedWalletIds);

return [
    "lock:swap:user:{$userId}",
    "lock:swap:wallet:{$sortedWalletIds[0]}",
    "lock:swap:wallet:{$sortedWalletIds[1]}",
];
```

Two swaps between wallets 1 and 2 — in either direction — always contend for locks in the identical sequence: `user:X` → `wallet:1` → `wallet:2`. One request acquires the full chain and proceeds; the other blocks on the very first contended key and waits. There is no ordering in which each side can hold one lock and wait on the other, because there is only ever one possible acquisition order to begin with. This is the standard resource-ordering strategy for deadlock prevention, applied at the lock-key-construction level rather than left to callers to get right by convention.

The user-level lock additionally means **one user can only have one swap in flight at a time, full stop** — not just one swap per wallet pair. This was a deliberate design choice, not an incidental side effect: without it, a user could race two swaps across *different* wallet pairs and potentially construct a double-spend that the wallet-pair-scoped lock alone wouldn't catch. This was verified directly — a lock held for wallets (1, 2) correctly blocks a *different* wallet pair (3, 4) for the same user, specifically because the user-level key is shared across both attempts.

### Safe release under TTL expiry races

Locks carry a 10-second TTL as a safety net against a crashed process holding a lock forever. But TTL expiry itself introduces a subtler race: if Lock A's TTL expires naturally, a *different* request could legitimately acquire the now-free key, and then Lock A's original holder's `finally` block would run and delete what it thinks is its own lock — actually deleting the new holder's lock instead.

`SwapLock::release()` avoids this with a small atomic Lua script that compares the stored token before deleting:

```lua
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
else
    return 0
end
```

Each acquisition stores a random 16-byte token as the lock's value; release only succeeds if the value still matches the token this specific caller was given. A stale release is a safe no-op, never someone else's lock.

### Why the lock, not just the database transaction, is what actually prevents the race

`SwapService::execute()` also runs inside a database transaction with a row-level `SELECT ... FOR UPDATE` on the source wallet, taken *after* the Redis lock is held. Worth being precise about the division of labor: the Redis lock is what prevents two concurrent *requests* from ever attempting to touch the same wallet pair simultaneously in the first place; the row lock is what guarantees a single, consistent balance read within whichever request wins that contention. Neither alone is sufficient — the Redis lock has no atomicity guarantee over the actual balance mutation, and the row lock alone would only serialize concurrent transactions, not prevent the initial race for *which* transaction gets to run first. Together, they're what let the automated concurrency test below hold.

---

## Database indexing rationale

`GET /api/ledger/{wallet_id}` needs to serve paginated results, ordered newest-first, filtered to one wallet, at scale — the exact profile the composite index on `ledger_entries` is built for:

```php
$table->index(['wallet_id', 'created_at']);
```

The controller's query — `$wallet->ledgerEntries()->orderByDesc('created_at')->paginate($perPage)` — filters on `wallet_id` and sorts on `created_at`. A composite index with `wallet_id` first (the equality-filtered column) and `created_at` second (the sorted column) lets Postgres satisfy both the `WHERE` and the `ORDER BY` from a single index scan, walking the index in the exact order the query needs rather than pulling matching rows and sorting them separately. Without this, a growing ledger would force either a full-table scan filtered post-hoc, or a separate sort step over every matching row — both of which get linearly worse as the ledger grows, exactly the failure mode a real production ledger needs to avoid from day one, not retrofit later.

The composite index also covers the wallet-balance computation itself (`Wallet::balance()`'s `SUM` over `ledgerEntries()`), which filters on the same `wallet_id` — a genuine two-birds case, not index bloat for a single narrow query.

Two secondary indexes support the other query patterns actually present in the codebase: `transaction_group_id` (used to reconstruct all legs of one swap or settlement, e.g. for reconciliation or support tooling) and `reference` (used by `SettlementCreditor`'s idempotency check — `WHERE reference = ?` — which needs to be fast precisely because it runs on every single settlement credit attempt).

---

## Rate engine: stale-while-revalidate

`RateService::getRate()` implements SWR caching against the (mocked) external rate provider:

- **Fresh** (age ≤ 30s): return the cached rate immediately, zero calls to the provider.
- **Stale-but-in-grace** (30s < age ≤ 150s): return the cached rate immediately — the swap is never blocked on a slow or degraded rate provider — while dispatching a background job (`RefreshExchangeRate`, queued via Redis, deduplicated via `ShouldBeUnique` so a burst of concurrent requests doesn't queue redundant refreshes) to update the cache for the *next* request.
- **Fully expired** (age > 150s): block and fetch synchronously — correctness wins over latency once the cache is old enough that serving it would be genuinely stale, not just slightly behind.

All three branches were verified independently against real Redis: a fresh read makes zero client calls; a stale-in-grace read also makes zero *synchronous* calls but genuinely enqueues a background job (confirmed via `LLEN` on the Redis queue); a fully-expired read makes exactly one synchronous call.

A rate-provider failure (network error, timeout, non-2xx) is caught and re-thrown as a domain-specific `RateProviderUnavailableException`, mapped to a clean `503 Service Unavailable` with no leaked stack trace — not the raw `500` an uncaught `ConnectionException` would otherwise produce.

---

## Settlement webhooks: idempotency in two independent layers

Real settlement providers deliver webhooks with two failure modes a naive handler will get wrong: **exact retries** (the provider didn't get a `200` in time and resends the identical delivery) and **out-of-order delivery** (a `COMPLETED` status arriving before the `INITIATED` that logically preceded it, or a late `INITIATED` arriving *after* `COMPLETED` is already recorded).

### Layer 1: an explicit state machine, not "last write wins"

`WebhookStatus::canTransitionTo()` is a small, explicit lookup of valid forward transitions:

```php
Initiated  → Processing, Completed, Failed
Processing → Completed, Failed
Completed  → (nothing)
Failed     → (nothing)
```

`SettlementWebhookProcessor::ingest()` checks this before applying any update. A delivery whose transition isn't valid — a duplicate of the current status, or a regression to an earlier stage than what's already recorded — is a silent no-op, not an error and not a state change. This means out-of-order delivery is handled *by construction*, not by trying to infer intent from timestamps: whichever status arrives first for a never-before-seen reference is simply recorded (since there's nothing to compare against yet), and every subsequent delivery is checked against the *current* recorded state, not the order of arrival.

This was verified directly, including the specific scenario the spec calls out: a `COMPLETED` delivery followed by a stale `INITIATED` delivery for the same reference leaves the recorded status at `completed`, unchanged — proven both via direct service calls and via a real signed HTTP request.

### Layer 2: an atomic Redis dedupe guard ahead of the state machine

Before the state machine even runs, `ingest()` does a `SETNX`-style check (`Redis::command('set', [$key, '1', 'NX', 'EX', 300])`) keyed on `provider_reference` + status. This catches the *exact-retry* case cheaply, before touching Postgres at all — a provider retrying because it didn't see a timely `200` gets a fast, correct no-op rather than a second trip through the full ingestion pipeline.

### Layer 3: a ledger-reference check at the point of crediting, independent of both layers above

`SettlementCreditor::credit()` — the component that actually posts ledger entries — checks `LedgerEntry::where('reference', $providerReference)->exists()` before posting anything, *regardless* of how it was invoked. This is deliberate belt-and-braces: even if the state machine were ever bypassed by a future bug, or a job were manually re-queued by an operator during an incident, a wallet still cannot be double-credited, because the check that prevents it lives at the one place money actually moves, not upstream of it. This was verified by calling `credit()` on the same event twice, directly — the second call correctly no-ops, balance unchanged.

Heavy work (the actual crediting) is dispatched to a Redis-backed Laravel queue rather than performed inline — verified through a real `php artisan queue:work` consumer, not just Laravel's `sync` driver, confirming the async path genuinely works end-to-end.

---

## The concurrency guarantee, proven

The spec's core requirement — 10 concurrent swap requests against a balance that can fund exactly one — is implemented as `tests/Feature/Concurrency/SwapConcurrencyTest.php` and runs automatically in CI on every push.

This test is architecturally distinct from the rest of the suite: it fires genuine concurrent HTTP requests (via Guzzle's `Promise\Utils::settle()`) at a live, separately-running application server — not Pest's in-process request simulation, which is inherently synchronous and cannot exercise real request-level concurrency. CI boots two `php artisan serve` instances in the background with an explicit readiness-polling step (a process existing is not the same as it accepting connections) before running the suite.

Result: exactly 1 of 10 concurrent requests succeeds; the other 9 are rejected (`422`, insufficient balance — since by the time each contending request acquires the lock, the winning request has already spent the only available funds); the ledger balances afterward are exact to the subunit, with zero overdraft and zero double-crediting.

---

## Testing strategy

Three genuinely distinct test contexts exist, each solving a different isolation problem — worth understanding before adding new tests, since using the wrong one silently produces false confidence:

| Suite | Isolation mechanism | Database | When to use |
|---|---|---|---|
| `tests/Unit` | None needed (pure functions) | — | Rounding, fee math — no I/O |
| `tests/Feature/*.php` | `RefreshDatabase` (transaction rollback per test) | Postgres `tupay_testing`, Redis DB 1 (flushed before every test) | Everything that calls the app in-process via `postJson`/`getJson` |
| `tests/Feature/Concurrency` | None (real, committed state) | Postgres `tupay_testing` (dev database name is *never* touched) | Only tests that must fire real concurrent HTTP against a live server |

Two non-obvious things worth knowing if you extend this suite:

- **`RefreshDatabase` cannot run against SQLite for this project.** The migrations use genuinely Postgres-specific features — a raw PL/pgSQL trigger function, a `CHECK` constraint via `ALTER TABLE` — that have no SQLite equivalent. `phpunit.xml` is configured to point ordinary feature tests at a real, dedicated `tupay_testing` Postgres database for this reason; this isn't a preference, it's a hard requirement of the schema.
- **`SwapService::execute()` skips its explicit `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` statement when already running inside an outer transaction** (detected via `DB::transactionLevel() > 0`). Postgres only accepts that statement as the very first command of a transaction block; `RefreshDatabase`'s wrapping transaction means it's never actually first when a feature test exercises the swap endpoint. This is safe, not a compromise: correctness in the concurrent case comes primarily from the Redis lock plus the row-level `FOR UPDATE` lock, both of which apply unconditionally — `REPEATABLE READ` is defense-in-depth on top of that, not the sole mechanism holding the guarantee together, and the concurrency test (which never runs inside a wrapping transaction) confirms the full guarantee still holds end-to-end.

Run everything:
```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
```

The concurrency test requires both `php artisan serve` instances (ports 8000 and 8001) running locally; CI handles this automatically.

---

## Known limitations and deliberate scope decisions

Stated explicitly rather than left implicit, because I'd rather be clear about what's out of scope and why than have it discovered as an unstated gap:

**Single-instance Redis is a single point of failure for the distributed lock.** The lock implementation here (`SET ... NX EX`, with token-checked release) is correct for a single Redis instance, but naive locking against a single node has a known correctness gap during failover — a lock holder can lose its lock if that node fails over before the lock's TTL expires, and a second request could then legitimately acquire "the same" lock. The standard fix is the Redlock algorithm, run against multiple independent Redis nodes. I deliberately did not implement this: doing it *correctly* means running several genuinely independent Redis instances, implementing the actual quorum-based Redlock protocol (not just pointing at more than one node), and testing real failover scenarios — a multi-day project in its own right, and disproportionate to a take-home exercise built and verified against one Docker Redis container throughout. Implementing a partial or incorrect version would be a worse signal than naming the limitation plainly.

**The `.http` test collection isn't fully self-executing.** TOTP codes rotate every 30 seconds and HMAC signatures require an actual cryptographic computation — neither can be produced by a static request file (VS Code's REST Client has no scripting engine; Postman's pre-request scripts could do this, but the file is written to be importable by either). Every request's comments include the exact command to generate what's needed immediately beforehand. This was a deliberate choice over pretending the file is more automated than it can honestly be.

**Windows dev environment specifics.** This project was built and verified end-to-end on Windows. Two genuine environment issues surfaced and are worth flagging for anyone else on Windows: a pre-existing local Redis-compatible service (Memurai) silently intercepting `127.0.0.1:6379` ahead of Docker's container (`docker-compose.yml` maps Redis to host port `6380` specifically to avoid this — see [Troubleshooting](#troubleshooting)), and `php artisan serve`'s single-threaded nature meaning it cannot handle a request that itself makes an HTTP call back into the same process — which is why the mock rate provider runs as a genuinely separate `php artisan serve` instance on port 8001, not a route within the same app instance.

---

## Troubleshooting

**Config changes don't seem to take effect.** `php artisan serve` reads `.env` once at process startup and does not re-read it per request. Restart the server after any `.env` change — `php artisan config:clear` alone is not sufficient for an already-running dev server.

**Redis operations behave unexpectedly / keys you expect aren't showing up in `redis-cli`.** Check for a key prefix: `config/database.php`'s default Redis options apply a prefix derived from `APP_NAME`. If you see keys like `tupay-database-your-key` instead of `your-key`, that's the prefix — set `REDIS_PREFIX=` (empty) in `.env` if you want raw key names for manual debugging.

**Redis lock/cache tests behave inconsistently, or `redis-cli` shows nothing you'd expect.** On Windows, check for a conflicting local Redis-compatible service:
```powershell
netstat -ano | findstr :6379
tasklist /FI "PID eq <pid from above>"
```
If something other than Docker owns port 6379, that's very likely the cause — this project's `docker-compose.yml` maps Redis to host port `6380` specifically to avoid this class of conflict.

**A queued job doesn't seem to run.** Confirm `QUEUE_CONNECTION` — `redis` means jobs sit queued until a worker (`php artisan queue:work`) consumes them; `sync` runs them inline immediately. Both are legitimate depending on what you're testing.

---

## API reference

| Method | Endpoint | Protection | Description |
|---|---|---|---|
| `POST` | `/api/login` | Rate-limited | Authenticate, returns a Sanctum bearer token |
| `POST` | `/api/2fa/challenge` | Bearer auth | Submit TOTP + action payload, returns an EAT |
| `POST` | `/api/swap` | Bearer auth + EAT | Execute a cross-currency swap |
| `GET` | `/api/ledger/{wallet_id}` | Bearer auth | Paginated ledger history for an owned wallet |
| `POST` | `/api/webhooks/settlement` | HMAC signature | Process a third-party settlement confirmation |
| `GET` | `/api/health` | None | Dependency-aware health check (Postgres + Redis) |

See `api-test.http` for a complete, runnable walkthrough of every endpoint including negative cases.
