# gyugyu — Repository instructions for Codex

This file applies to the entire repository. Keep it concise; product requirements and acceptance criteria live in [`docs/SPEC.md`](docs/SPEC.md).

## Project and source of truth

- Project name is **`gyugyu`** (lowercase ASCII only). Do not put alternative names or kanji in the product name.
- Build a lightweight, internal, multi-instance scheduling application. Use the product behavior in `docs/SPEC.md` as the authoritative specification.
- When changing product behavior, update `docs/SPEC.md` and the corresponding tests in the same change. Treat an older, standalone HTML prototype as **visual/interaction reference only**, not as production architecture or a data source.
- Preserve user-requested details even when a framework or a simpler design would make them easier to change.

## Technical direction

- Server: **PHP 8.3+**, PDO SQLite, SQLite migrations. Client: semantic HTML, local CSS, **vanilla JavaScript**. Prefer first-party code and no external CDN or required Node.js production process.
- Keep application code, SQLite databases, backups, private configuration, and credentials **outside the web document root**; expose only files deliberately placed in `public/`.
- Support deployment behind an existing **HTTPS + HTTP Basic authentication** layer, including a non-root URL prefix such as `/gyugyu/`. Do not assume a specific web server or a filesystem root URL.
- Current deployment uses **one shared Basic account**. All authenticated users may add participants and **edit any participant's answers** without a participant PIN, edit token, or individual account. Do not introduce per-person login as a prerequisite. This is a deliberate trust-based MVP policy, not identity verification.
- Because the Basic credential is shared, do **not** claim to know which person performed an edit. Record the action/time and an actor value such as `shared-basic`; no reliable individual audit attribution is available. A future individual-auth model should be possible without migrating participant IDs.

## Non-negotiable product behavior

- Exactly **30-minute** answer slots, displayed as independent day panels stacked vertically. Standard day axis: 06:00 through 06:00 the following day (48 slots).
- Default grid cell width **16px** (optional 20px/24px), participant cell height about **26px**, fixed name column about **148px**. Show **one hour label spanning two cells**; never print `HH:mm` inside every 30-minute cell or stretch grid cells to fill the viewport.
- Answer palette: `○ yes`, `△ maybe`, `× no`, `— unknown`; pointer drag painting, clear, cancel, and save feedback. `unknown` is not `no`.
- Display **three numeric summary rows** (`○`, `△`, `×`) aligned with the slots. Do **not** add a heatmap, percentage gradient, or bar chart as a competing summary.
- Group count per instance **0–9**, with zero valid. Groups are editable from the Web UI: name required, description optional. People and summary filters may select multiple groups. A person is never double-counted.
- Candidate-time mask is editable both in bulk and by dragging; disabling a slot **must not delete historical answers**.
- The schedule title is required. The optional multiline description is shown **only on the instance detail page**, not on the portal listing.
- Ranking is primarily fewest `×`, secondarily most `○`: `score = Y - (N + 1) * X` for a fixed target set of size `N`. Missing answers make a result provisional.
- The portal manages multiple instances, including creation, a normal link to each instance, and confirmation-based soft deletion and recovery. See `docs/SPEC.md` for the shared-account deletion policy.

## Security and data integrity

- All HTML pages, static assets containing private information, API endpoints, and exports must be protected by the deployed Basic-auth boundary; do not rely on JavaScript to enforce access or on an untrusted forwarded identity header.
- For non-GET mutations, use server-side authorization checks for the **shared authenticated** policy, CSRF protection, validation, and transactions. The public PHP built-in server may be used only as an explicitly local development aid.
- Use parameterized SQL, HTML-escaped plain-text rendering, safe CSV escaping against formula injection, and secure session cookies. Avoid importing a large framework to replace a small, auditable implementation.
- Make data changes atomic. Use foreign keys, version/revision checks on concurrent edits, SQLite WAL/busy timeout where appropriate, and migration-controlled schema changes.
- Never commit passwords, Basic credentials, SQLite database files, private configuration, backups, live participant data, or downloaded exports.
- Destructive actions need an explicit confirmation UI and server-side checks. Soft-delete instances and preserve a documented recovery path.

## Development workflow

1. For each task, inspect the affected code and the **relevant sections** of `docs/SPEC.md`; avoid reading unrelated project documents for trivial edits.
2. Implement a **small, testable vertical slice**, rather than converting the entire HTML prototype at once. Keep source, database migrations, and tests aligned.
3. Add automated checks for behavior and regression risks, especially grid density, candidate-mask retention, score ordering, group cardinality, cross-instance data isolation, authentication, CSRF, and concurrent updates.
4. For browser-visible changes, verify them in an actual browser when possible; document any unverified UI behavior instead of claiming it was tested.
5. Maintain a useful `README.md` with local prerequisites, initialization, running tests, and deployment prerequisites once implementation begins.
6. Report what changed, commands/tests run and results, and remaining limitations. Do not claim production readiness until the authentication boundary, security checks, backup/restore, and acceptance tests have been verified.
7. Do not deploy to a live server, push to a remote, migrate production data, delete external resources, or introduce paid services without explicit user authorization. Normal in-scope local edits and non-destructive tests do not need special approval.

## Suggested implementation order

1. Repository skeleton, PHP/SQLite connection, migrations, local setup, authorization-boundary checks, and tests.
2. Persistent portal and instance CRUD, title/description, dates and default candidate windows.
3. Day-grid renderer, edit modes and candidate-mask bulk/drag editing.
4. Participants, shared-account answer editing, groups, masks, numeric summaries and scoring.
5. Exports, lifecycle/soft delete and recovery, concurrency and security regression tests; deployment documentation.

The individual steps are **suggested slices**, not permission to silently start all phases at once. Follow the scope of the current Codex task.
