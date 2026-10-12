# BMPC Universal Branch System — BMPC ONE Monitoring Integration Standard

## 1. Purpose

This document is the universal integration standard for any BMPC application that needs to connect to **BMPC ONE / BMPC Centralized Portal** for centralized monitoring, analytics, reporting, and system visibility.

A connected application remains an independent operational system with its own:

- database;
- backend;
- frontend;
- authentication;
- users;
- roles;
- permissions;
- workflows;
- business rules;
- reports;
- files;
- deployment lifecycle.

BMPC ONE receives only authorized **READ-ONLY monitoring information**.

BMPC ONE must never require direct database access to a connected application.

**Core rule:** BMPC ONE may MONITOR a branch system. BMPC ONE may not OPERATE a branch system.

---

## 2. Universal Architecture

```text
BMPC ONE Browser
      |
      v
BMPC ONE Backend
      |
      | HTTPS + Bearer Monitoring API Key
      v
Branch System Monitoring API
      |
      | read-only application/service/query layer
      v
Branch System Database
```

Never use:

```text
BMPC ONE -> direct database credentials -> branch system database
```

Never use browser-to-branch-system API-key calls.

---

## 3. Mandatory Repository Audit Before Implementation

Before modifying any connected system, perform a complete read-only audit.

Determine:

- application name and purpose;
- technology stack;
- frontend framework;
- backend framework;
- database technology;
- authentication implementation;
- authorization/RBAC implementation;
- user roles and permissions;
- existing APIs and middleware;
- database tables and relationships;
- modules and workflows;
- dashboards;
- analytics;
- reports;
- exports;
- audit/activity logs;
- status fields;
- timestamps;
- branches/departments or other organizational dimensions;
- scheduler jobs;
- queue system;
- caching;
- file storage;
- environment handling;
- rate limiting;
- production error handling;
- existing health endpoints.

Do not assume one BMPC system has the same data model as another.

The actual repository and deployed application are the source of truth.

---

## 4. Preserve Existing Functionality

The integration must not change existing:

- CRUD operations;
- workflows;
- calculations;
- business rules;
- authentication;
- authorization;
- routes except where adding monitoring routes;
- database behavior except monitoring-management tables;
- dashboards;
- reports;
- file uploads;
- notifications;
- validation;
- UI behavior.

Avoid unrelated refactoring.

New database tables may be introduced only when required for Monitoring API management, logging, or configuration.

---

## 5. Universal Monitoring API Base Path

Every integrated BMPC application should expose:

```text
/api/monitoring/v1/
```

Standard endpoints:

```text
GET /api/monitoring/v1/health
GET /api/monitoring/v1/system
GET /api/monitoring/v1/summary
GET /api/monitoring/v1/analytics
GET /api/monitoring/v1/reports
GET /api/monitoring/v1/activity
```

Only expose endpoints supported by real application data.

Application-specific read-only endpoints may be added, but BMPC ONE must not depend on them for the universal connection contract.

All Monitoring API operations are GET-only.

---

## 6. Stable System Identity

Every branch system must have a permanent unique identity.

Example:

```json
{
  "system": {
    "id": "bmpc-example-system",
    "name": "BMPC Example System",
    "api_version": "v1"
  }
}
```

`system.id` requirements:

- stable across requests and deployments;
- unique in BMPC ONE;
- 1-191 characters;
- allowed characters: letters, digits, `.`, `_`, `:`, `-`.

Never generate a new random system ID on each request or deployment.

---

## 7. Capability Discovery

Endpoint:

```text
GET /api/monitoring/v1/system
```

Canonical response:

```json
{
  "success": true,
  "system": {
    "id": "bmpc-example-system",
    "name": "BMPC Example System",
    "api_version": "v1"
  },
  "generated_at": "2026-10-08T15:00:00+08:00",
  "capabilities": {
    "summary": true,
    "analytics": true,
    "reports": true,
    "activity": true,
    "exports": false
  }
}
```

Recognized capabilities:

```text
summary
analytics
reports
activity
exports
```

A capability may be `true` only when its endpoint conforms to this standard.

Unknown capabilities may be ignored by BMPC ONE and must never imply extra permissions.

---

## 8. Health Endpoint

Endpoint:

```text
GET /api/monitoring/v1/health
```

Example:

```json
{
  "success": true,
  "system": {
    "id": "bmpc-example-system",
    "name": "BMPC Example System",
    "api_version": "v1"
  },
  "generated_at": "2026-10-08T15:00:00+08:00",
  "status": "online",
  "database": "connected"
}
```

Accepted status classes:

```text
online: online, ok, healthy, up
warning: degraded, warning, partial
offline: down, error, unhealthy, offline
```

Never expose:

- DB credentials;
- DB hostnames;
- server credentials;
- environment variables;
- private IP addresses;
- encryption keys;
- stack traces.

---

## 9. Summary Endpoint

Endpoint:

```text
GET /api/monitoring/v1/summary
```

Canonical structure:

```json
{
  "success": true,
  "system": {
    "id": "bmpc-example-system",
    "name": "BMPC Example System",
    "api_version": "v1"
  },
  "generated_at": "2026-10-08T15:00:00+08:00",
  "data": {
    "metrics": [
      {
        "key": "total_records",
        "label": "Total Records",
        "value": 1250,
        "type": "number"
      }
    ]
  }
}
```

Rules:

- `data.metrics` required;
- maximum 60 metrics;
- unique `key`;
- numeric `value` only;
- supported `type` only;
- system-specific KPI selection is allowed;
- free-form summary objects are not valid for new v1 implementations.

---

## 10. Analytics Endpoint

Endpoint:

```text
GET /api/monitoring/v1/analytics
```

Optional filters:

```text
?from=YYYY-MM-DD&to=YYYY-MM-DD
```

Canonical structure:

```json
{
  "data": {
    "metrics": [],
    "series": [],
    "distributions": []
  }
}
```

At least one of the three collections must exist.

### Metrics

```json
{
  "key": "total_completed",
  "label": "Total Completed",
  "value": 1250,
  "type": "number"
}
```

### Series

```json
{
  "key": "records_created",
  "label": "Records Created",
  "type": "number",
  "points": [
    { "timestamp": "2026-10-01T00:00:00+08:00", "value": 12 }
  ]
}
```

### Distribution

```json
{
  "key": "status",
  "label": "Status",
  "type": "number",
  "items": [
    { "key": "open", "label": "Open", "value": 184 }
  ]
}
```

Limits:

- maximum 30 series;
- maximum 1000 points per series;
- maximum 30 distributions;
- maximum 100 items per distribution.

Never fabricate metrics.

---

## 11. Reports Endpoint

Endpoint:

```text
GET /api/monitoring/v1/reports
```

Example:

```json
{
  "data": {
    "reports": [
      {
        "id": "monthly-summary",
        "name": "Monthly Summary",
        "description": "Month-end totals.",
        "type": "summary"
      }
    ]
  }
}
```

Rules:

- `data.reports` required;
- maximum 100 entries;
- empty list is valid;
- stable `id` required;
- `name` required;
- `description` and `type` optional;
- report metadata must not expose sensitive information.

Structured report content may be exposed through separately approved read-only endpoints if needed in future versions.

---

## 12. Activity Endpoint

Endpoint:

```text
GET /api/monitoring/v1/activity
```

Canonical response:

```json
{
  "data": {
    "activity": [
      {
        "id": "activity-123",
        "timestamp": "2026-10-08T15:00:00+08:00",
        "type": "monitoring_event",
        "label": "Daily synchronization completed"
      }
    ]
  }
}
```

Rules:

- newest first;
- maximum 100 entries;
- required: `id`, `timestamp`, `label`;
- optional: `type`;
- omit secrets and unnecessary personal data.

---

## 13. Monitoring API Administration

Each branch system should provide an administrator-only Monitoring API management module when practical.

Recommended functions:

- API enabled/disabled status;
- generate API key;
- revoke API key;
- rotate API key;
- disable API key;
- view metadata;
- configure expiration;
- configure scopes;
- view last used;
- view connection/audit activity;
- show integration documentation.

Do not provide a "Reveal API Key" function after creation.

---

## 14. API Key Standard

Recommended format:

```text
bmmon_live_<secure-random-value>
```

Store only:

```text
id
name
key_hash
key_prefix
key_last_four
scopes
status
created_by
created_at
updated_at
expires_at
last_used_at
revoked_at
```

Never store the full raw key.

Show the full key only once after generation.

Rotation should create a new credential and revoke or retire the previous credential according to the system's selected rotation policy.

---

## 15. Authentication

BMPC ONE sends:

```http
Authorization: Bearer <MONITORING_API_KEY>
```

Middleware must verify:

- monitoring API enabled;
- key exists;
- key hash matches;
- active status;
- not revoked;
- not expired;
- required scope exists.

Never log the raw key or Authorization header.

---

## 16. Universal Read-Only Scopes

Recommended scopes:

```text
monitoring:read
analytics:read
reports:read
activity:read
exports:read
```

A system should implement only the scopes it needs.

Never create operational write scopes for BMPC ONE.

---

## 17. Data Minimization

Prefer aggregate information.

Good:

```json
{ "key": "open_cases", "label": "Open Cases", "value": 184, "type": "number" }
```

Avoid transmitting all 184 case records merely to display the count.

Never expose:

- passwords;
- password hashes;
- password manager data;
- reset tokens;
- verification tokens;
- sessions;
- personal access tokens;
- monitoring API keys;
- encryption keys;
- private environment values;
- server credentials;
- unnecessary PII;
- confidential file contents without an approved monitoring purpose.

---

## 18. Performance and Operational Safety

Monitoring must not materially degrade the branch system.

Use:

- efficient aggregation;
- proper indexes;
- bounded queries;
- date-range validation;
- caching where justified;
- maximum response sizes;
- rate limiting;
- query timeouts where supported.

Monitoring endpoints must not perform large record exports unless explicitly approved.

---

## 19. Rate Limiting

Protect all Monitoring API routes.

Return HTTP 429 when appropriate.

Recommended policy:

- health/system: moderate rate limit;
- summary: moderate rate limit;
- analytics: stricter rate limit;
- reports/activity: moderate rate limit.

Central monitoring should use bounded retries and backoff rather than aggressive polling.

---

## 20. Monitoring Audit Logs

Record monitoring API activity.

Suggested metadata:

```text
api_key_id
endpoint
method
http_status
request_timestamp
duration_ms
source_ip
```

Never record:

- raw API key;
- Authorization header;
- passwords;
- secret environment values;
- sensitive full responses.

---

## 21. Error Structure

Standard error:

```json
{
  "success": false,
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Invalid monitoring API credentials."
  }
}
```

Allowed error codes:

```text
UNAUTHORIZED
FORBIDDEN
RATE_LIMITED
INVALID_DATE_RANGE
NOT_FOUND
SERVICE_UNAVAILABLE
INTERNAL_ERROR
```

Never expose stack traces or raw exceptions in production.

---

## 22. BMPC ONE Connection Model

BMPC ONE should require only:

```text
System Name
System URL
Monitoring API Key
```

Example:

```text
System Name: BMPC Example System
System URL: https://system.example.com
Monitoring API Key: bmmon_live_************************
```

Connection sequence:

```text
1. Validate URL/reachability
2. Authenticate
3. GET /api/monitoring/v1/health
4. GET /api/monitoring/v1/system
5. Verify api_version
6. Verify stable system.id
7. Save connection
8. Check declared capabilities independently
```

An incompatible optional capability must not make the entire system connection fail.

---

## 23. No Direct Browser Integration

Required flow:

```text
BMPC ONE Browser
    -> BMPC ONE Backend
        -> Branch Monitoring API
```

Do not put branch-system Monitoring API keys in browser JavaScript.

Do not enable unrestricted CORS solely for BMPC ONE.

---

## 24. Metric Types

Supported metric types:

| type | meaning | rendering |
|---|---|---|
| `number` | count or quantity | `1,250` |
| `currency` | Philippine peso major units | `₱1,250.50` |
| `percentage` | 0-100 | `12.5%` |
| `duration` | seconds | `45s`, `2 h` |

Any other metric type is non-conforming in v1.

---

## 25. Transport Contract

Production requirements:

- HTTPS required;
- GET-only Monitoring API;
- `Authorization: Bearer <key>`;
- JSON object response;
- no HTML login redirects;
- no empty successful bodies;
- max response body 1 MiB;
- BMPC ONE connect timeout target 5 s;
- BMPC ONE request timeout target 10 s;
- maximum three redirects;
- never forward API key to a different host after redirect.

---

## 26. Common Success Envelope

```json
{
  "success": true,
  "system": {
    "id": "bmpc-example-system",
    "name": "BMPC Example System",
    "api_version": "v1"
  },
  "generated_at": "2026-10-08T15:00:00+08:00",
  "data": {}
}
```

`success` must be `true` when present.

`generated_at` should use ISO-8601.

Unknown extra fields may be ignored by BMPC ONE.

---

## 27. Versioning

Current version:

```text
v1
```

Rules:

- additive, backward-compatible changes stay in v1;
- breaking response-shape or semantic changes require v2;
- BMPC ONE may normalize leading `v` when comparing versions;
- unsupported versions must be rejected safely.

---

## 28. Universal Test Requirements

At minimum test:

- API disabled;
- generate key;
- valid key;
- missing key;
- invalid key;
- revoked key;
- expired key;
- wrong scope;
- stable system identity;
- health contract;
- system contract;
- summary contract;
- analytics contract;
- reports contract;
- activity contract;
- date filters;
- invalid date range;
- rate limiting;
- payload-size limits;
- malformed response protection;
- audit logging;
- read-only enforcement;
- no credentials in logs;
- no operational write capability;
- branch application's existing regression tests;
- frontend build/type checks where applicable;
- integration conformance against BMPC ONE fixtures.

---

## 29. BMPC ONE Validation Semantics

BMPC ONE connection success should depend only on:

- reachability;
- authentication;
- valid health response;
- valid `/system` identity;
- supported API version.

After connection, each capability is checked independently and may be:

```text
available
incompatible
unreachable
not provided
```

An incompatible optional capability does not change overall Online/Offline status.

Recommended BMPC ONE internal classifications:

```text
HEALTH_CONTRACT_INVALID
SYSTEM_CONTRACT_INVALID
SUMMARY_CONTRACT_INVALID
ANALYTICS_CONTRACT_INVALID
REPORTS_CONTRACT_INVALID
ACTIVITY_CONTRACT_INVALID
UNSUPPORTED_API_VERSION
REMOTE_AUTH_FAILED
REMOTE_NOT_FOUND
REMOTE_TIMEOUT
malformed_response
payload_too_large
redirect_blocked
url_blocked
dns_failed
unreachable
rate_limited
remote_error
remote_rejected
identity_mismatch
```

Do not persist raw secrets, Authorization headers, stack traces, or arbitrary remote response bodies in central logs.

---

## 30. Generic Implementation Pattern

Each branch system should adapt this pattern to its framework:

```text
Monitoring API routes
    -> monitoring authentication middleware
        -> controller
            -> monitoring service/query layer
                -> existing application models/database
```

Do not duplicate operational data into monitoring tables merely to make an endpoint easier unless there is a justified cached/materialized reporting design.

Monitoring-management tables may include:

```text
monitoring_api_keys
monitoring_api_logs
monitoring_settings
```

Follow the application's existing migration, model, naming, timestamp, and RBAC conventions.

---

## 31. Environment Configuration

Recommended universal variables:

```env
MONITORING_API_ENABLED=true
MONITORING_API_SYSTEM_ID=bmpc-example-system
MONITORING_API_SYSTEM_NAME="BMPC Example System"
MONITORING_API_VERSION=v1
MONITORING_API_RATE_LIMIT=60
```

Real API keys should not be committed to source control.

If keys are database-managed, do not duplicate them into `.env`.

---

## 32. Implementation Safety Rules

Do not:

- merge the branch database into BMPC ONE;
- give BMPC ONE database credentials;
- alter operational business logic;
- broaden user permissions unnecessarily;
- introduce write endpoints for BMPC ONE;
- expose secrets or private configuration;
- refactor unrelated modules;
- run destructive production migrations;
- commit, push, or deploy until explicitly approved.

---

## 33. Final Verification Before Approval

Verify:

- zero regression in existing business workflows;
- monitoring API is read-only;
- API key lifecycle works;
- raw keys are never stored;
- scopes are enforced;
- rate limiting works;
- audit logging works;
- no secrets are leaked;
- summary/analytics values are derived from real data;
- capability declaration matches actual implementation;
- BMPC ONE Test Connection succeeds;
- stable identity is preserved;
- existing application health remains good;
- application regression suite passes.

---

## 34. Required Final Implementation Report

Report:

1. system detected;
2. technology stack;
3. modules audited;
4. monitoring metrics selected;
5. source tables/business logic for metrics;
6. endpoints created;
7. capabilities declared;
8. database changes;
9. API key implementation;
10. scopes;
11. data-minimization decisions;
12. security controls;
13. rate limits;
14. monitoring audit logging;
15. performance considerations;
16. tests and results;
17. files changed;
18. migrations;
19. environment requirements;
20. deployment requirements;
21. BMPC ONE conformance results.

**Do not commit, push, or deploy until explicit approval is given.**

---

## 35. Core Rule

Each BMPC application remains independently responsible for its operational processes and data.

BMPC ONE provides centralized visibility.

BMPC ONE does not become the operational backend of the connected application.
