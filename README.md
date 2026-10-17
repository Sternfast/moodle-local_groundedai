# AI Teacher Assistant (local_groundedai)

A staff-only, read-only AI assistant for Moodle. It lets teachers and managers ask plain-language
questions about **their own courses** — grades, submissions, progress, participants, dates, and course
content — and get grounded answers. It is built so the assistant can **only read**: there is no tool
anywhere that can create, edit, delete, enrol, or grade.

- **Staff only.** Access is granted explicitly, per role or per person, on the Governance page. Learners
  and guests can never open it, and a student/guest role can never be granted access (the plugin halts
  if one ever is).
- **Read-only by design.** The assistant calls a fixed allowlist of read tools. The wall is in code,
  not just in the prompt — a continuous test asserts that running every tool changes no record.
- **Capability-scoped.** Every lookup re-checks the staff member's own Moodle permission in the item's
  own context, so the assistant only ever surfaces data that person could already see.
- **Bring your own key.** Model access is over an OpenAI-compatible endpoint you configure; you choose
  the provider, region, and contract. Nothing is sent anywhere until you set an endpoint and key.

## Requirements

- Moodle 4.5–5.3 (requires 4.5.0, build 2024100700).
- An OpenAI-compatible chat-completions endpoint and API key (a hosted provider, or a self-hosted model
  such as Ollama, LM Studio, llama.cpp or vLLM).

## Install

1. Copy this folder to `local/groundedai` in your Moodle.
2. Visit **Site administration → Notifications** to complete the database upgrade.
3. Configure it (below). The assistant stays switched off until you enable it, set an endpoint, and
   grant someone access.

## Configuration

Everything the plugin adds lives under one place — **Site administration → Plugins → Local plugins →
AI Teacher Assistant** — split across two pages.

### Setup — how the assistant is wired

- **Enable staff assistant** — the master switch, plus a per-site floating launcher users can hide.
- **Provider** — the endpoint base URL, API key, and the two model names (a cheaper model for small
  calls, a stronger one for answers). No default is shipped (fail-closed). A one-click **Test
  connection** button confirms the endpoint and key work before staff rely on it.
- **Conversation memory** — a sliding window of recent turns so follow-ups keep context, and a
  retention period after which a scheduled task purges old conversations (0 = keep).
- **Answer style** — a default "how to answer" template; each user can add their own in preferences.
- **File upload** — the path to an optional `pdftotext` binary (a built-in PDF reader is used when it
  is not set). Upload itself is switched on per grant, under Governance.
- **Telemetry** — an optional OTLP/HTTP endpoint that receives usage **metadata only** (model, token
  counts, duration) — never prompt or response content.

### Governance & compliance — who may use it, and what they may see

- **Access grants** — grant access by **role** or by **person**, the way an AWS security group lists
  rules. Each grant has a **course scope** (all courses / the grantee's enrolled courses / specific
  courses) and an optional **expiry** (never / a date / a number of days). Access is a strict
  allowlist — there is no block list; site administrators always have access; students never do.
- **Per-grant data domains** — each grant chooses which kinds of data it may surface (courses, grades,
  participants, dates, progress, how-to action links). Domains are **on by default** for a new grant;
  switch off what a given role or person should not reach. A domain left off is unreachable for that
  grantee whatever their Moodle capability.
- **Per-grant sensitive features** — student email in people look-ups, file upload, and export to a
  file are each **off by default** and enabled per grant.
- **Access log** — a date-filtered view of who used the assistant and every refusal, read from Moodle's
  standard log store (ids only — no names, emails, or message content), with a CSV export.
- **Compliance evidence pack** — a downloadable attestation of the access controls the plugin enforces,
  mapped to the relevant standards, to support your own assessment.

## Privacy and data handling

- **What leaves Moodle:** the staff member's question and the read-tool results are sent to the
  LLM endpoint you configured. Optional usage metadata goes to the OTLP endpoint if you set one.
- **What is stored:** conversations and messages (and any files a staff member attaches to a chat),
  fully covered by Moodle's Privacy (GDPR) subsystem — inventory, export, and erasure are supported,
  and the external destinations are declared.
- **Minimisation:** no contact PII (such as email) is pulled unless a grant opts in; result sets are
  capped; the access log and audit events record ids only.

An institution-facing **compliance evidence pack** (read-only attestation, privacy posture, and a
FERPA / GDPR / WCAG mapping) is available to support your own assessment. The plugin **supports** these
obligations; it does not, and cannot, make your deployment "compliant" on its own — that depends on your
chosen provider, region, contract, and configuration.

## For developers

See [DEVELOPERS.md](DEVELOPERS.md) for the architecture, the read-only wall, the grants model, how to
run the tests, and how to add a read tool.

## Support

Sternfast.com. Licensed under the GNU GPL v3 or later.
