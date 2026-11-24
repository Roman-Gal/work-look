Agent 2: technical research and the WorkLook MVP
Research checked 2026-11-20, Europe/Warsaw.
Current application/deployment state: ../../README.md and ../mydevil-deployment.txt.

Original specification: ../specification.txt. The Symfony MVP lives at ../../.
A user button starts the AI agent; it chooses queries/tools while Messenger runs
the task in the background. API/RSS adapters are tools, not autonomous scrapers.
SOLID.Jobs, DOU RSS and Djinni RSS are implemented and were checked through Symfony CLI.
The original local SQLite stage has been superseded by shared MyDevil PostgreSQL.
AI protocol tests use mock HTTP; a real paid run needs an API key and real profile.
Chosen UI: Symfony UX + Twig, without Angular. Hosting: MyDevil MD2, native workers.

Reading guide
- 01-stack-and-hosting.txt: backend/UI decisions, local workflow and hosting.
- 02-job-sources.txt: Polish/Ukrainian platforms, requests and observed responses.
- 03-adapter-contract.txt: proposed contract, normalization, errors and MVP sequence.
- requests.http: individual IDE HTTP-client examples; execute selectively.
- probe_get.py: repeatable one-shot curl GET checks without third-party Python packages.
- evidence/*.json: timestamp, URL, HTTP status, content type, shape and checksum.

Selected approach
1. PHP/Symfony 7.4 LTS, PostgreSQL and Messenger's Doctrine transport.
2. Twig/UX in one Symfony app; dashboard, profile, manual start and results exist.
3. Start with public SOLID.Jobs JSON and DOU/Djinni RSS.
4. Keep JustJoinIT/NoFluffJobs experimental: HTTP access alone is not a documented
   contract or permission for regular export.

Development boundaries
- This phase uses JSON API and XML RSS, without HTML scrapers or Playwright fallback.
- Extracting JSON-LD/__NEXT_DATA__ from an HTML page is still page parsing and is
  outside the current scope.
- probe_get.py is research tooling, not a Python backend or ingestion worker.
- HTTP 200 + JSON does not establish filters, paging, full descriptions or freshness.
- structured_success means only a finished GET, HTTP 200 and parsed JSON/RSS.
  Evidence shapes describe a sample item, not every possible response variant.
- Official documentation was read with browsing tools; actual API/RSS requests
  were sent from the local computer through curl without login/cookies.

Repeat selected checks from the repository root:
  python docs/research/probe_get.py solidjobs dou djinni

Each selected target makes one GET with no automatic retries or redirect following.
The default response cap is 5 MB and timeout is 25 seconds.
The utility overwrites evidence for selected targets; preserve prior audit history
separately if needed. Exit 1 means at least one target failed structural validation.
Do not poll every endpoint repeatedly; this is a manual research utility.
Research checks started no schedules, ngrok tunnels, job applications or messages.
