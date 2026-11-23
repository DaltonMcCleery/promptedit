# PromptEdit

A Laravel 13, Vue 3 and Inertia v3 app where signed-in users generate images through the [Pixelforge API](https://pixelforge.promptedit.dev/docs). Users see the price range before they submit, watch each generation's status live, and are charged exactly Pixelforge's cost plus 25% once a generation succeeds. Failed generations cost nothing.

## How money works

- **Every amount is an integer number of nanodollars** (1 USD = 1,000,000,000). Pixelforge reports costs as exact 9-decimal strings, so a nanodollar is its smallest unit, and our totals compare digit for digit with `GET /v1/usage`. No floats are used anywhere: PHP uses integers and the browser uses `BigInt`.
- **Provider cost** is `Σ tokens × rate ÷ 1,000,000` over the four token types, using the rates Pixelforge froze on that generation (`rates` on the generation object), not today's rates.
- **User charge** is provider cost × 1.25, computed as `ceil(cost × 5 / 4)`. With Pixelforge's current rates every provider cost is a multiple of 4 nanodollars, so the markup is always exact; rounding up only guards against future rates. Charges are not rounded to cents.
- **The estimate is a range.** Output tokens and reference-image tokens (765 each) are exact. Prompt tokens are `ceil(characters ÷ 4)` ±15% and reasoning tokens vary by quality, and both are only known once the image is ready, so the page shows the fewest and most it can cost. The maximum is a real ceiling. Rates and token tables come from `GET /v1/pricing` (cached 10 minutes, with the last good copy as a fallback), never from code.
- The browser computes the estimate live for display. The server recomputes it on submit and stores it; it never trusts a number from the browser. If Pixelforge's rates changed between the estimate and its acceptance, the stored estimate is recomputed at the frozen rates.
- **A generation is charged once**, on its single move into `succeeded`. Failed generations, whether rejected at submission or failed after acceptance, are recorded at $0.

The docs' worked example is a test: high quality, 1536x1024, a 400-character prompt and 2 reference images has a maximum Pixelforge cost of exactly $0.373875000 and a maximum charge of $0.467343750.

## How it copes with Pixelforge

| Situation                                           | What the app does                                                                                                                                                                                                                            |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A webhook arrives before the create request returns | The local row and its idempotency key exist before Pixelforge is called, and the app's id travels in `metadata.app_generation_id`, so the webhook finds the generation and records Pixelforge's id and rates.                                |
| The same event is delivered twice                   | Each event id is inserted under a unique constraint in the same transaction that applies it. A repeat inserts nothing, changes nothing and gets a 200.                                                                                       |
| Events arrive out of order                          | Status is read from the generation object, never the event type, and only moves forward (`submitting` → `queued` → `processing` → `succeeded` or `failed`). A late `processing` after `succeeded` is ignored, and final states never change. |
| Applying an event fails                             | The transaction rolls back (including the event row) and the endpoint answers 500, so Pixelforge retries.                                                                                                                                    |
| Forged or replayed webhooks                         | The `Pixelforge-Signature` HMAC is checked on the raw body in constant time, and timestamps more than 300 seconds off are rejected with a 400.                                                                                               |
| 503 `overloaded` or 429 `rate_limit_exceeded`       | Retried after `Retry-After` (or an increasing backoff), with the same idempotency key, up to 5 attempts. Then the generation fails at $0 with a clear reason.                                                                                |
| 429 `concurrency_limit_exceeded`                    | Shown as "Waiting for a free slot" and retried after `Retry-After` for up to 10 minutes. The job also checks the 3-in-flight limit locally first, so waiting generations don't spend the 60-requests-a-minute budget.                        |
| A timeout or unexpected 5xx on create               | Pixelforge may have created the generation, so it is never failed. It shows "Can't reach Pixelforge (retrying)" and is retried with the same key (backing off to once a minute); a replay returns what Pixelforge made, charged once.        |
| 400, 401, 409, 422, or the daily limit              | Nothing was created, so the generation fails at once at $0 with a plain-language reason.                                                                                                                                                     |
| A webhook never arrives                             | `generations:reconcile` runs every minute and polls generations with no status change for 2 minutes, at most 20 per run, through the same status rules as webhooks. It also re-dispatches submissions whose job was lost.                    |
| An image link expires                               | History shows a placeholder saying the link expired.                                                                                                                                                                                         |

## Code map

| Concern                   | Where                                                                                                                        |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Money units               | `app/Billing/Nanos.php`                                                                                                      |
| All price calculations    | `app/Billing/CostCalculator.php`, `app/Billing/Estimate.php`                                                                 |
| Pricing from Pixelforge   | `app/Pixelforge/PricingRepository.php`, `app/Pixelforge/PriceSheet.php`                                                      |
| Pixelforge HTTP client    | `app/Pixelforge/PixelforgeClient.php`, `CreateResult.php`, `CreateOutcome.php`                                               |
| Saving a generation       | `app/Generations/CreateGeneration.php`, `app/Http/Requests/StoreGenerationRequest.php`                                       |
| Submitting and retries    | `app/Jobs/SubmitGeneration.php`                                                                                              |
| Status rules and charging | `app/Generations/GenerationStatusUpdater.php`                                                                                |
| Webhooks                  | `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Pixelforge/WebhookSignature.php` (route in `bootstrap/app.php`) |
| Polling fallback          | `app/Console/Commands/ReconcileGenerations.php` (scheduled in `routes/console.php`)                                          |
| Failure wording           | `app/Generations/FailureReason.php`                                                                                          |
| Pages                     | `resources/js/pages/Generations/Create.vue`, `resources/js/pages/Generations/Index.vue`                                      |
| Browser price math        | `resources/js/lib/pricing.ts`, `resources/js/lib/money.ts`                                                                   |

## Local setup

Requirements: PHP 8.4, Composer, Node 22, and Postgres (the app and its tests use Postgres, because the guarantees above rely on its row locks and unique constraints). These steps assume [Laravel Herd](https://herd.laravel.com), which serves the app at `https://promptedit.test`.

```bash
git clone https://github.com/DaltonMcCleery/promptedit.git ~/Herd/promptedit
cd ~/Herd/promptedit
createdb promptedit
createdb promptedit_testing
composer run setup
herd secure promptedit
```

`composer run setup` installs dependencies, copies `.env.example` to `.env`, generates the app key, runs the migrations and builds the frontend. Then set in `.env`:

```dotenv
APP_URL=https://promptedit.test
PIXELFORGE_API_KEY=pf_...          # a key for local development only
PIXELFORGE_WEBHOOK_SECRET=whsec_...  # that key's signing secret
```

Mail goes to the log by default (`MAIL_MAILER=log`). With Herd Pro you can point it at Herd's mailbox instead (`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=2525`).

Run the queue worker, the scheduler, the log viewer and Vite together:

```bash
composer run dev
```

The queue worker sends generations to Pixelforge and the scheduler runs `generations:reconcile`, so both must be running for generations to progress.

### Webhooks in development

Pixelforge refuses webhook URLs on private or loopback addresses, so expose the app through a tunnel. Herd includes Expose:

```bash
herd share
```

In the Pixelforge dashboard, set your local key's webhook URL to `https://<tunnel-host>/webhooks/pixelforge` (exactly; redirects are not followed), copy that key's signing secret into `PIXELFORGE_WEBHOOK_SECRET`, and press "Send test event". It should get a 2xx. Without a tunnel, generations still finish through the polling fallback, two minutes late.

Prompt keywords from the docs force specific behaviour: `[fail]`, `[blocked]`, `[overloaded]`, `[slow]`, `[duplicate]` and `[succeed]`.

## Tests

```bash
npm run build
php artisan test --compact tests/Feature/WebhookTest.php
```

Run `npm run build` first: page tests render Inertia responses, which need the Vite manifest. Tests use the `promptedit_testing` Postgres database (see `phpunit.xml`) and fake every Pixelforge request. The whole suite is `php artisan test`. Static checks: `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`, `npm run types:check` and `npm run check`.

## Deployment

The app runs on Laravel Cloud with a Postgres database, a queue worker and the scheduler enabled (hibernation off, so both keep running), and `php artisan migrate --force` as the deploy command. The deployed environment uses its own Pixelforge API key, whose webhook URL is `https://<app-host>/webhooks/pixelforge`, so that key's `GET /v1/usage` covers exactly the deployed app's generations. Password reset emails go through Resend (`MAIL_MAILER=resend`, `RESEND_API_KEY`, and a `MAIL_FROM_ADDRESS` on a domain verified in Resend).
