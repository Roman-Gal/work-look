# WorkLook

**AI-assisted job search for Poland and Ukraine, with relevance scoring and fact-grounded PDF CVs.**

WorkLook brings your experience, job preferences and relevant vacancies into one
private workspace. Start a search, let a bounded AI agent explore supported feeds,
then review its matches, explanations and prepared CVs. Every CV statement comes
from your canonical profile. You stay in control of applications.

## Features

- On-demand AI searches processed through a background queue.
- SOLID.Jobs JSON API and DOU/Djinni RSS integrations.
- Match scores, relevant experience and missing-requirement explanations.
- Private PDF CVs built from selected, verified profile facts.
- Vacancy statuses: New, Reviewed, Applied and Skipped.
- Email-verified accounts and Ukrainian, Polish and English interfaces.
- One free AI search per verified account; a disabled button reserves future payments.

There is no autonomous search schedule or automatic application submission.
Demo search and fabricated offers have been removed.

## Stack

| Layer | Technology |
| --- | --- |
| Application | PHP 8.2+, Symfony 7.4 LTS |
| Interface | Twig, Symfony UX Turbo/Stimulus, AssetMapper |
| Database | PostgreSQL, Doctrine ORM and migrations |
| Queue | Symfony Messenger with Doctrine transport |
| AI | OpenAI Responses API with bounded tool calls |
| PDF | Dompdf with locally hosted Zakon Text fonts |
| Hosting | MyDevil MD2, FreeBSD, native PHP and cron |

No Angular application, Node build or separate frontend server is required.

## Local setup

The owner chose **one shared WorkLook PostgreSQL database on MyDevil** for this
development pet project. Local access uses an SSH tunnel. A separate development
database is not required; automated tests use isolated, disposable SQLite fixtures.

Install Composer and PHP 8.2+ with the extensions required by composer.json,
including PostgreSQL support and SQLite support for tests. The existing Windows
executable is C:\Php82\php.exe; the host uses PHP 8.4. MySQL Workbench is an
administration client, not a PostgreSQL server.

From the repository root:

~~~sh
composer install
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
~~~

Store the generated secret and connection settings in the ignored .env.local.
See .env.prod.example and [deployment notes](docs/mydevil-deployment.txt) for hosted
configuration and the database tunnel. On a new installation:

~~~sh
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:user:create your@email.example
~~~

The CLI reads the password without displaying it and creates a trusted, verified
account. Public registration at /register requires configured SMTP; see
[email registration](docs/email-registration.txt).

Start the local server:

~~~sh
php -S 127.0.0.1:8000 -t public tools/router.php
~~~

Open [localhost:8000](http://127.0.0.1:8000/). A worker must handle queued searches.
When the hosted worker is not handling the shared queue, run one in another terminal:

~~~sh
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M --no-interaction
~~~

Avoid accidentally starting a duplicate hosted-queue worker. Restart workers after
code or environment changes. The command exits after an hour once the current
message finishes; Ctrl+C stops it sooner. Without a worker, a search stays queued.

Existing demo credentials are in the ignored var/deploy/mydevil-demo-access.txt.
The older var/local-access.txt may be obsolete. Credentials, databases, logs and
local environment files stay outside Git.

## Configure AI

~~~dotenv
OPENAI_API_KEY=your_private_api_key
OPENAI_MODEL=your_available_model
~~~

Restart the worker and complete your profile with truthful experience, preferences
and search terms. Model access depends on your API account and environment settings.
Keep API keys out of web forms, chats and commits.

The API receives the search request, preferences, headline, canonical facts and
relevant vacancy descriptions. get_my_profile excludes separate name/contact fields;
personal details entered into facts or requests will still be sent. Requests use
store=false; this does not promise the absence of all provider-side logs.

As of **24 November 2026**, the host has no OpenAI key. Search remains disabled and
the free allowance is preserved until a key is configured.

## Search limits

A run allows at most 12 model calls, 24 tool calls and 4 searches, with a cumulative
60,000-token budget and an approximately 300-second limit checked between steps.
A final call may exceed those token/time boundaries; this is not a monetary cap.
Output is capped at 3,500 tokens per response; model HTTP timeout is 60 seconds.

Cancellation is checked between calls, so an in-flight request may finish. Queued
cancellations also need a worker. Completed messages are not rerun on redelivery,
and paid calls have no automatic retry. A crash can leave a run marked running;
automatic crash recovery is not implemented.

The free allowance is enforced in PostgreSQL. Reservation, run creation and queue
dispatch share one transaction. Failure/cancellation before the first model attempt
releases only that reservation. An attempted model call consumes the free search.
The administrative live-AI switch does not grant extra searches. Future payments
need a separate entitlement ledger, not a reset of the trial pointer.

## Job sources

| Source | Access | Current scope |
| --- | --- | --- |
| SOLID.Jobs | Public JSON API | Descriptions and structured salary/contract fields |
| DOU | Public RSS | Recent vacancy window; incomplete structured metadata |
| Djinni | Public RSS | Recent vacancy window; incomplete structured metadata |

Adapters return up to 10 jobs per request. SOLID supports pages 0–2; RSS supports
page 0 only. HTTPS requests use an allowlist, a one-hour cache, a 3 MB response cap
and a 25-second timeout, without redirects or unlimited retries. HTTP 429 and schema
changes surface errors rather than triggering protection bypasses.

RSS is not a complete archive and does not establish eligibility to work from Poland.
Unknown fields remain unknown. Job details use already fetched data without parsing
HTML pages. CV generation is blocked for summary-only jobs. SOLID currently treats
an inline description of at least 300 characters as complete; that heuristic still
needs broader validation.

JustJoinIT, NoFluffJobs, Pracuj, Work.ua and other platforms are researched but are
not connected to the MVP. See [source research](docs/research/02-job-sources.txt).
Manual diagnostics perform live GET requests without database writes:

~~~sh
php bin/console app:sources:check solidjobs PHP
php bin/console app:sources:check dou PHP
php bin/console app:sources:check djinni PHP
~~~

## CV integrity and languages

Enter one self-contained, truthful fact per line. Each fact has a stable ID; each
run stores an immutable profile snapshot. The model selects and orders known IDs,
and the server rejects unknown/duplicate IDs. PDFs copy the original text.

Free-form rewriting, CV translation, PDF import, cover letters, geocoding and commute
estimates are not implemented. Match scores and explanations require human review.

The interface supports **UA / PL / EN**. Ukrainian uses internal locale uk and is the
default. A validated browser cookie stores the choice for a year. Translations live
in translations/messages.{uk,pl,en}.yaml; new messages need all three translations.
Language changes use CSRF-protected POST and redirect only to known local pages.
Each run saves ui_locale in its snapshot; saved explanations and source text do not
change when the interface language changes. CV facts are never passed to Translator.

Zakon Text is served locally in four styles and embedded in PDFs. No font CDN is
used. The PDF font cache at var/pdf-fonts/ must be writable. See the original
[font provenance and checksums](assets/fonts/zakon/NOTICE.txt).

## Security

Password hashing, email verification, login throttling, CSRF and server-side owner
checks protect private runs, results and PDFs. The public job catalog is shared;
personal records belong to a user. PDFs disable remote fetch, JavaScript and PHP.
Twig escapes model explanations. CSP permits the exact pinned Turbo progress CSS
hash while keeping arbitrary inline styles blocked.

Job descriptions are untrusted data, not agent instructions. The server constrains
tools, URLs and ownership. Progress shows actions/errors, not hidden reasoning.
SQL logging is disabled; workers do not log prompts or API keys.

Private files and backups stay outside public/. Database contents and snapshots
are not application-encrypted. Applications sharing an OS account can read each
other's files; stronger isolation needs separate accounts or hosts. These controls
are not an independent security audit.

## Validation

~~~sh
php vendor/phpunit/phpunit/phpunit
php bin/console lint:container --env=test
php bin/console lint:twig templates --env=test
php bin/console lint:yaml config translations
php bin/console doctrine:schema:validate
composer audit
~~~

The latest suite passed **41 tests and 312 assertions**. Tests guard the disposable
database name before rebuilding its schema and mock external API calls. Coverage
includes ownership, CSRF, login, escaping, PDFs, immutable facts, sources/XXE,
verification/expiry/token reuse/throttling, SMTP failures, quotas, queue rollback,
idempotency, the agent tool loop and the pinned Turbo stylesheet hash.
Live source checks are manual diagnostics, not a required part of every test run.

## Deployment and documentation

WorkLook runs natively on MyDevil: PHP with public/ as the only document root,
PostgreSQL, a lock-protected cron worker and private shared configuration. Git
archives travel over SFTP/SSH into a new release, which is checked before switching
current. Docker is optional locally and is not a hosting requirement.

| Document | Contents |
| --- | --- |
| [Architecture](docs/architecture.txt) | Agent flow, tools, entities and implementation boundaries |
| [Deployment](docs/mydevil-deployment.txt) | Verified release, workers, backups and host operations |
| [Email registration](docs/email-registration.txt) | Confirmation flow, SMTP and safeguards |
| [Hosting](docs/hosting.txt) | Multiple runtimes, domains and process management |
| [Specification](docs/specification.txt) | Original goals and later product decisions |
| [Research](docs/research/00-README.txt) | API/RSS findings, contracts, requests and dated evidence |

Verified runtime: 9d4d29b. Later documentation-only commits do not require a new
application release. WorkLook test data was cleared on 24 November 2026, keeping
the demo login/password. Telegram bots and their databases were preserved.

An explicit test reset is available after stopping the WorkLook worker:

~~~sh
php84 bin/console app:reset-test-data --keep-user=demo@worklook.local --confirm=RESET_WORKLOOK_TEST_DATA --env=prod
~~~

It deletes other WorkLook users and clears profiles, jobs, matches, runs and queue
messages. It preserves the chosen login/password, verifies/enables the account and
restores one free search. Bot databases are unaffected. Git restores code; deleted
database contents require a separate backup.
