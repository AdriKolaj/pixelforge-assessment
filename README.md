# Pixel Studio

Image generation on top of [Pixelforge](https://pixelforge.promptedit.dev/docs), built for the PromptEdit
take-home. Laravel 13, Vue 3, Inertia v3, Tailwind 4.

Users sign in, write a prompt, see what it will cost, and get charged Pixelforge's cost + 25% once the image is
done. Failed generations are free. Both stretch goals are in: per-user balances with a (fake) "Buy credits", and an
admin report that is reconciled against `GET /v1/usage`.

## Running it locally

```bash
composer setup                  # install, .env, key, migrate, build
php artisan dev                 # web server, queue worker and Vite
php artisan schedule:work       # polls generations whose webhooks went missing
```

Put the API key and the key's webhook signing secret in `.env`:

```dotenv
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=whsec_...
```

Pixelforge can't reach `localhost`, so expose the app with a tunnel (`expose share`, `ngrok http 8000`, ...) and
set `https://<tunnel>/webhooks/pixelforge` as the webhook URL of the key. Without a tunnel everything still works,
just slower: the scheduler picks up results by polling.

To see the admin report, promote a user:

```bash
php artisan app:make-admin you@example.com
```

Tests, static analysis and formatting:

```bash
composer test     # pint --test, phpstan, pest
npm run check     # lint + format
npm run types:check
```

## How money is handled

**Everything is an integer number of nanodollars** (1e-9 USD), in `bigint` columns. That is the precision of
Pixelforge's own `cost_usd`, so there is no rounding when comparing with them, and no floats anywhere. Decimal maths
(rates like `"12.50"`) goes through `brick/math`.

- **Provider cost** = Σ tokens × rate / 1M, computed from the usage and the `rates` _on the generation_ (they are
  fixed when it's created, so a later price change can't affect it). `App\Pricing\CostCalculator`.
- **User charge** = provider cost × 1.25, rounded **up** to the next nanodollar. That's the only rounding in the
  system and it can never sell below cost. Worst case it's 1 nanodollar over the exact 25%.
- **Before submitting** the user sees an estimate (text tokens ≈ `ceil(chars / 4)`, the middle of the reasoning
  range) and the maximum (text +15%, max reasoning). Both come from the server, which reads rates from
  `GET /v1/pricing` (cached for 10 minutes), so there's a single pricing implementation.
- **Balances**: the worst-case price is held while a generation is running (available = balance − holds of
  unfinished generations). The check runs under a row lock on the user, so two quick submits can't both spend the
  same money. On success the actual charge is booked once in `credit_transactions`; failures just release the hold.
- **Never twice**: the ledger has a unique index on `generation_id`, and charging only happens on the transition into
  `succeeded`, inside the same transaction that locks the generation.
- Display rounds to 4 decimals; the exact 9-decimal value is in the tooltip. The admin report shows exact amounts.

## Robustness

- **Submitting** happens in a queued job (`SubmitGeneration`). The local row is created first and its id goes in the
  request `metadata`, so a webhook that arrives before the create response still finds it.
- Every attempt sends the same `Idempotency-Key`, so a retry after a timeout returns the original generation instead
  of creating (and billing) a second one.
- `429`/`503` are retried, waiting for `Retry-After` or an exponential backoff, whichever is longer (capped at 60s,
  10 attempts). Errors a retry can't fix (`content_policy_violation`, `daily_limit_exceeded`, validation) fail
  straight away with a clear message and no charge.
- **Webhooks** (`POST /webhooks/pixelforge`, outside the session/CSRF stack): HMAC-SHA256 over the raw body, constant
  time compare, 5 minute replay window. The event id is stored with a primary key in the _same_ transaction that
  applies the event: a duplicate is a no-op, and if applying fails the whole thing rolls back so Pixelforge's retry
  gets another go.
- **Out of order**: a generation only moves forward (`pending → queued → processing → succeeded|failed`). A late
  `processing` after `succeeded` is ignored. The same code path (`RecordGenerationUpdate`) handles create responses,
  webhooks and polling.
- **Missing webhooks**: `pixelforge:sync` runs every minute and fetches anything that has been quiet for 2 minutes
  (at most 10 per run, stops early if rate limited).
- **Provisional failures**: if we give up after timeouts we can't know whether Pixelforge accepted the request. We mark
  it failed (not charged), but if Pixelforge later reports it, we trust them and charge, since they will bill us.

## Admin report

`/admin/report`: credit spend, provider cost and profit per UTC day and per user, for up to 92 days. Generations count
on the day they _completed_, which is how Pixelforge bills them. Each day is compared with `GET /v1/usage` and marked
✓ or ⚠.

## Decisions and trade-offs

- **Email verification is off**: the demo has no mail server, and it would block reviewers right after sign-up.
- **Images aren't stored.** Pixelforge links expire after 24 hours, and after that the card says so. In production
  I'd copy the PNG to S3 in a queued job when the generation succeeds.
- **Negative balances are possible but rare**: only if a generation we had given up on turns out to have succeeded,
  or if rates change between the quote and the create call. I preferred charging the exact amount over capping it.
  A negative balance blocks new generations.
- **Webhooks are applied inline** rather than queued, because they're a single short transaction. If they ever did
  more (downloading the image, notifications), that part would go to a job.
- History is polled every 2.5s while something is running instead of using websockets. It's simple and stops as soon
  as nothing is active.

## Deploying (Laravel Cloud)

1. Create the app from the repository with a Postgres database. The SQL is portable, tested on SQLite.
2. Environment: `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET` (a separate key from local), `APP_NAME="Pixel Studio"`.
3. Enable a queue worker and the scheduler.
4. Deploy command: `php artisan migrate --force`.
5. In the Pixelforge dashboard set the key's webhook URL to `https://<app>/webhooks/pixelforge` and send a test event.
