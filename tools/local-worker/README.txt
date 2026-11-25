WorkLook local batch operator
============================

This is a manual transfer utility, not a daemon or a scheduled automation.
It uses the Python standard library and Windows DPAPI. No OpenAI API key is needed.

Initial setup on Windows, inside the repository:
 python tools/local-worker/worklook.py setup --origin https://rh-additional.usermd.net

Default private workspace: sibling directory JOBFIND/WorkLook-Local.
The setup creates an encrypted credential and prints only its SHA256 server setting.
Install that hash as LOCAL_WORKER_TOKEN_SHA256 in the host's private .env.local.
The operator token can export all explicitly submitted requests and publish reviewed
results. It cannot administer accounts, read arbitrary paths or modify the shared KB.
Rotate/revoke it by replacing/clearing the server hash. Keep all local data private.
Never copy .connection.json into a job folder, Git, a prompt or an email.

Daily workflow:
 01-Download.cmd: download pending PDF/prompt packages and reviewed shared KB.
 02-Download-and-Codex.cmd: do the same, then run Codex CLI sequentially.
 03-Publish-reviewed.cmd: synchronize deletion/status, ask for explicit review,
                        upload results and send the owner's notification.

For the manual desktop-app route, click 01-Download.cmd, open WorkLook-Local as
the workspace in the Codex app and ask it to follow RUN_TODAY.md. Each job's
TASK.md and package.json are the relevant inputs; source PDF photos/images are ignored. Result files must be
jobs/<id>/result.json matching result.schema.json. Review candidate facts, job
status, links, gaps and CV wording, then use 03-Publish-reviewed.cmd.
Do not confuse the Windows launchers with commands to paste into the chat.

The CLI button enforces forced_login_method=chatgpt and removes API key environment
variables. New packages run three serial Codex invocations: 01-Intake.md produces
01-profile.json; 02-Search.md produces 02-vacancies.json; 03-Adapt.md produces
result.json. Only the search invocation enables live web search. The source text
and URL annotations are used; photos, images and OCR are excluded.
Each stage has its own JSON schema, log and input/output hash receipt in
pipeline-state.json. Limits/errors preserve completed stages and isolate incomplete
output. A retry reuses valid stages; changing inputs invalidates dependent stages.
Search is refreshed if resumed on another processing day. Result language follows
the CV unless explicitly selected, independently of the interface language.
Candidate links are classified and named; source coverage and explicit adaptation
notes accompany each match. Enhanced-mode inferences need customer confirmation.
It needs an installed, signed-in Codex CLI
on PATH or at the executable location saved during setup. Launchers pin the local
Python executable so they also work when double-clicked from Explorer. It consumes
the operator's subscription allowance only when clicked.
CLI flags are based on the installed integration and official configuration reference:
https://developers.openai.com/codex/config-reference/
Real inference is deliberately not run
by installation or tests. Job folders keep private logs. A failed/limited run
stops the batch, retains finished outputs, and names partial output
result.incomplete.json. No background timer or paid API fallback is configured.

Downloads verify PDF SHA256 and preserve draft result.json files across syncs.
Private prompts accompany only their owner's package; KB/shared.json contains
only published, reviewed shared entries. Source PDF filenames never control paths.
Deleted server request folders are removed on the next sync; unrelated operator
files are preserved. Local copies can persist until the operator syncs again.
An offline computer cannot receive a deletion immediately.

Publishing is idempotent: an existing completed result cannot be overwritten.
The reviewed JSON is transferred as raw document bytes (application/octet-stream,
fixed result.json filename). The deployed endpoint parses the same JSON and retains
all token, operator-review, source-hash, size and content checks. This handles a
hosting false positive observed with legitimate CV text in application/json bodies.
No hosting protection or account permission is changed. Older reviewed result.json
files remain compatible and do not need to be regenerated.
Failed email remains pending; repeat the publish command to retry. SMTP cannot
provide a strict exactly-once guarantee if the process fails immediately after
acceptance but before the database records delivery. Normal repeated uploads
are deduplicated. Emails contain a login-protected result link, never CV attachments.
Prepared PDFs are rendered by the website with bundled Zakon fonts and are owner-only.

Before public service operation, verify the controller identity/contact notice,
hosting/provider data arrangements, OpenAI account data controls and actual backup
retention. The checkbox acknowledges the service processing notice; it is not
blanket marketing consent. It does not by itself establish full GDPR compliance.
