# Pixelforge Billing

A small Laravel + Vue + Inertia app. Users generate AI images through the
Pixelforge API and pay Pixelforge's cost plus 25% for each image that succeeds.

## How it works

1. The user fills in the form. The page shows a price range before submit.
2. The app saves its own row first, holds the highest possible price from the
   user's credit, and puts a job on the queue.
3. The job sends the request to Pixelforge with an idempotency key.
4. Pixelforge reports progress by webhook. A command polls once a minute as a
   backup for lost webhooks.
5. When the image succeeds, the real cost is saved and the user is charged
   once. A failed image costs nothing.

## Decisions

### Money

- All money is stored as whole **nanodollars** (1 USD = 1,000,000,000) in
  `bigint` columns. Pixelforge reports 9 decimals, and one reasoning token
  costs $0.0000125, so smaller units would lose precision. Floats are never
  used.
- Cost = tokens x rate, summed over the four token types, using the **rates
  stored on that image**, not today's prices.
- Price = cost x 125 / 100. With Pixelforge's current rates this is always a
  whole number of nanodollars. If it ever is not, it is rounded **up**. This is
  the only rounding in the app (`Money::divideRoundingUp`).
- The browser never calculates money. It only formats numbers from the server.
- The worked example from the Pixelforge docs ($0.373875000) is a unit test.

### Estimate

The text token count can be off by 15% and reasoning tokens are only known at
the end. So the estimate is a range, "from X to Y". The credit check uses Y.

### Charging exactly once

Three layers, each enough on its own:

1. `webhook_events.event_id` is unique. A repeated event is dropped.
2. A finished image never changes again (`GenerationUpdater`, rule 1).
3. `credit_transactions` has a unique index on `(generation_id, type)`. The
   database refuses a second charge.

### Webhooks in the wrong order

Status has a rank: queued 0, processing 1, succeeded/failed 2. A status may
only move to a higher rank. Webhooks, the create response and polling all go
through the same method, `GenerationUpdater::apply()`, so their order does not
matter.

A webhook can arrive before the create request returns. That is why the row is
saved first and matched by our own uuid, which is sent in `metadata`.

### Pixelforge problems

| Problem                                                             | What the app does                                                              |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| 429 rate limit, 429 three images running, 503 overloaded, no answer | Job goes back on the queue and waits `Retry-After`. Gives up after 10 minutes. |
| 429 daily limit, 400 rejected prompt, 422                           | Image fails right away with a clear message. No charge.                        |
| Lost webhook                                                        | `pixelforge:reconcile` polls unfinished images, max 20 per minute.             |
| Image link expires after 24 hours                                   | The image is copied to our own storage when it succeeds.                       |

If the job gives up after 10 minutes, we cannot be sure Pixelforge never
created the image. That failure is marked `submission_unconfirmed`, and it is
the one case where a later "succeeded" webhook may still correct the row, so
our numbers always match Pixelforge's.

### Credits

- The balance is the sum of the ledger (`credit_transactions`). Rows are only
  added.
- While an image is unfinished, its highest possible price is held. The real
  price is never above that, so a balance cannot go below zero.
- "Buy credits" is fake and adds $5.

### Email verification

Turned off: `User` does not implement `MustVerifyEmail`. The deployed app has
no mail service, so new users would be stuck on "verify your email" and could
not try the app. With a real mail service, adding `implements MustVerifyEmail`
back is the only change needed: the `verified` middleware is still on the
routes.

### Admin report

`/admin/report` (emails listed in `ADMIN_EMAILS`) shows paid, cost and profit
per day and per user. Days are UTC days of **completion**, the same as
Pixelforge's usage report.

## Where things are

| File                                                   | Job                                               |
| ------------------------------------------------------ | ------------------------------------------------- |
| `app/Support/Money.php`, `PriceCalculator.php`         | All money math. Pure functions.                   |
| `app/Support/WebhookSignature.php`                     | Signature check.                                  |
| `app/Services/Pixelforge/PixelforgeClient.php`         | The only HTTP code for Pixelforge.                |
| `app/Services/GenerationUpdater.php`                   | The only place status changes and charges happen. |
| `app/Services/Credits.php`                             | Ledger: balance, held credit, charge.             |
| `app/Jobs/SubmitGeneration.php`                        | Sends the request, handles limits and retries.    |
| `app/Http/Controllers/PixelforgeWebhookController.php` | Verify, dedupe, apply.                            |
| `app/Console/Commands/ReconcileGenerations.php`        | Polling backup.                                   |

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev          # web server, queue worker and Vite
php artisan schedule:work # the polling backup
```

`.env`:

```
PIXELFORGE_API_KEY=       # local key
PIXELFORGE_WEBHOOK_SECRET=whsec_...
ADMIN_EMAILS=you@example.com
```

Webhook URL: `https://<your-host>/api/webhooks/pixelforge`. Pixelforge rejects
local addresses, so use a tunnel (for example `herd share` or ngrok). Without
one the app still works through polling, only slower.

## Tests

```bash
php artisan test
```

## Known limits

- An image whose submission was never confirmed has no Pixelforge id, so
  polling cannot find it. Only a webhook can correct it.
- History shows the latest 50 images, with no paging.
