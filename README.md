# Rocket Coding — Prototype

Laravel 11 prototype for AI-powered clinical documentation → medical coding suggestions.

Prototype scope: ingest a chart note, send it to Claude with a structured-output system prompt, validate the returned JSON against a strict schema (CPT / ICD-10 / modifiers), retry with correction on malformed output, fall back to OpenAI, and persist every attempt to an immutable audit log.

This is a focused architecture demonstration — not a full product. It is designed to show the exact patterns required to productionize an LLM-backed coding suggestion service: structured output, validation, retry, fallback, queue-based async processing, and audit-ready logging.

---

## Architecture

```
┌─────────────────────┐     ┌────────────────────────────┐     ┌─────────────────┐
│ POST /api/coding    │ ──▶ │ ProcessClinicalDocumentJob │ ──▶ │ Redis queue     │
│ (controller)        │     │ (queued)                   │     │ (Supervisor)    │
└─────────────────────┘     └──────────────┬─────────────┘     └─────────────────┘
                                           │
                                           ▼
                           ┌────────────────────────────────┐
                           │ CodingSuggestionService        │
                           │  ├─ build system prompt        │
                           │  ├─ call ClaudeClient          │
                           │  ├─ validate vs. JSON Schema   │
                           │  ├─ retry w/ correction (×2)   │
                           │  ├─ fall back → OpenAIClient   │
                           │  └─ persist LlmRequest (audit) │
                           └────────────────────────────────┘
                                           │
                                           ▼
                           ┌────────────────────────────────┐
                           │ llm_requests table             │
                           │ (immutable audit log)          │
                           └────────────────────────────────┘
```

### Why this shape

- **Thin controller, fat service.** Controllers authenticate and dispatch; all domain logic lives in `CodingSuggestionService`.
- **Contract-first LLM client.** `LlmClient` interface lets us swap Claude ↔ OpenAI without touching the service, and lets tests inject a `FakeClaudeClient`.
- **Schema validation is non-negotiable.** Every response is validated against `CodingResponseSchema` before the service returns. A malformed response is a first-class exception (`MalformedLlmResponseException`) — never silently swallowed.
- **Retry with correction, not retry in hope.** On schema failure, the next retry includes the malformed output plus the schema violations in the prompt — we tell the model exactly what was wrong.
- **Audit trail is immutable.** `llm_requests` records every attempt (success or failure), token counts, cost, duration, and the raw request/response payloads. Required for OIG / CERT audits.
- **Queue-first.** Document processing runs in `ProcessClinicalDocumentJob` so API latency is decoupled from LLM latency. Supervisor manages workers.

---

## Key files

| File | Purpose |
|------|---------|
| [app/Services/Coding/CodingSuggestionService.php](app/Services/Coding/CodingSuggestionService.php) | Main orchestrator — prompt assembly, validation, retry, fallback, logging |
| [app/Services/Anthropic/ClaudeClient.php](app/Services/Anthropic/ClaudeClient.php) | Anthropic Messages API wrapper, token/cost tracking |
| [app/Services/Anthropic/Contracts/LlmClient.php](app/Services/Anthropic/Contracts/LlmClient.php) | Provider-agnostic LLM interface |
| [app/Services/Coding/Schemas/CodingResponseSchema.php](app/Services/Coding/Schemas/CodingResponseSchema.php) | JSON Schema for CPT / ICD-10 / modifier structured output |
| [app/Services/Coding/Prompts/CodingSystemPrompt.php](app/Services/Coding/Prompts/CodingSystemPrompt.php) | System prompt, corrective prompt for retries |
| [app/Models/LlmRequest.php](app/Models/LlmRequest.php) | Audit-log model |
| [app/Jobs/ProcessClinicalDocumentJob.php](app/Jobs/ProcessClinicalDocumentJob.php) | Queue job |
| [tests/Feature/CodingSuggestionServiceTest.php](tests/Feature/CodingSuggestionServiceTest.php) | Feature tests — happy path, malformed → retry → success, all-retries-fail → fallback |

---

## Setup

```bash
# 1. Bootstrap Laravel + install deps
composer install

# 2. Env
cp .env.example .env
php artisan key:generate

# 3. Database (SQLite for local; swap to MySQL in production)
touch database/database.sqlite
php artisan migrate

# 4. Run tests (no API key required — uses FakeClaudeClient)
php artisan test

# 5. (Optional) Start with Docker for full stack
docker compose up -d
```

### Env vars

```
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...        # fallback provider
LLM_PRIMARY_MODEL=claude-opus-4-7
LLM_FALLBACK_MODEL=gpt-4o
LLM_MAX_RETRIES=2
QUEUE_CONNECTION=redis       # or 'sync' for local dev
```

---

## Malformed-JSON handling (the hard part)

1. `json_decode` the raw response with `JSON_THROW_ON_ERROR`. Decode failure → same retry path as schema failure.
2. Validate decoded object against `CodingResponseSchema` via `opis/json-schema`.
3. On validation failure:
    - Log the raw response + violation list to `llm_requests` (status = `schema_invalid`).
    - Retry up to `LLM_MAX_RETRIES` with exponential backoff.
    - On retry, append the malformed output and schema errors to the system prompt and instruct the model to return only corrected JSON — no prose, no apologies.
4. If all retries fail, fall back to the secondary provider (OpenAI).
5. If both providers fail, the job routes to a dead-letter queue for human review. `CodingSuggestionService` throws `MalformedLlmResponseException` — never silently returns garbage.

Every attempt is persisted immutably, keyed by `request_id`, with token counts, cost, duration, and full request/response bodies. Required for OIG / CERT audit trails.

---

## Production notes (not in prototype)

- Supervisor config for queue workers (3–5 workers, `--tries=2 --backoff=10`)
- Nginx + PHP-FPM with tuned pools
- HIPAA: field-level encryption on PHI columns, TLS everywhere, immutable audit logs (append-only MySQL user), IP allowlisting, MFA
- Observability: OpenTelemetry spans around every LLM call, tagged with `model`, `provider`, `request_id`, `patient_id_hash`
- Cost controls: daily token budget per tenant, hard cutoff via middleware, prompt caching for the system prompt

---

Built by Denys (2026).
