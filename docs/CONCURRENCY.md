# Concurrency (REQ-RUNTIME-003)

How NowoMarketingKitBundle stays correct when two admins, two workers or a retried request write the same marketing
tool rows at the same time. Since 1.13.

The bundle writes only from its admin (`web_ui.path_prefix`, default `/admin/marketing`): create, edit, toggle,
delete, catalog seed and YAML import of `MarketingTool` rows. Rendering the scripts only reads
(`DoctrineMarketingToolSource`, array hydration, no lock). Hosts that keep their tools in YAML or in their own
`MarketingToolSourceInterface` and do not use the admin have no write path.

## Table of contents

- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [Host options](#host-options)
- [Worker mode and entity managers](#worker-mode-and-entity-managers)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Write paths

Every write runs in one `Persistence\MarketingToolWriteUnit` (rule 1): one transaction on the manager of
`MarketingTool`, flushed before the commit, through nowo-tech/transaction-doctrine's runner.

| Path | Transaction | Concurrency control (rule 4) | Answer when it loses a race |
|------|-------------|------------------------------|-----------------------------|
| Admin create (`POST /new`) | one write unit | unique key `uniq_marketing_tool_profile_code` (profile, code) + catch | the form again, 409, `X-Error-Code: MARKETING_TOOL_DUPLICATE_CODE`, with a link to a fresh form |
| Admin edit (`POST /{id}/edit`) | one write unit | optimistic `version` column: the form carries the version it was opened with (`lock(OPTIMISTIC)` before the write) and the UPDATE checks it | the form again, 409, `X-Error-Code: MARKETING_TOOL_STALE`, with a link that reads the current row again; a changed profile or code can also meet the unique key (`DUPLICATE_CODE`) |
| Admin toggle (`POST /{id}/toggle`) | one write unit | optimistic `version`: the toggle form carries the version its list row was rendered for, and the UPDATE checks the version read in the request, so two toggles never lose one | back to the freshly read list (redirect) with the stale message flashed and `X-Error-Code: MARKETING_TOOL_STALE`; toggle again |
| Admin delete (`POST /{id}/delete`) | one write unit | none needed: deleting is idempotent for the admin; a delete wins over a concurrent edit | — |
| Catalog seed (`POST /seed`) | one write unit for the whole profile (codes read inside it, all created or none) | unique key + catch | 409, `X-Error-Code: MARKETING_TOOL_DUPLICATE_CODE` (host error page); run it again, it creates only the missing codes |
| YAML import (`POST /import-yaml`) | one write unit for the whole profile (rows read inside it, all written or none) | unique key + optimistic `version` | 409, `X-Error-Code: MARKETING_TOOL_DUPLICATE_CODE` or `MARKETING_TOOL_STALE` (host error page); run it again, it upserts by code |
| Any of the above meeting a row lock, a deadlock or a serialization failure | — | — | 409 + `CONFLICT_001` / `CONFLICT_002` (below) |

`grep -rn "flush()" src/` has one hit: the flush inside `MarketingToolWriteUnit::run()` on a manager handed in by code
that builds the unit itself (never wired by the container since 2.0). The bundle's own manager (the private one by
default, or a dedicated `doctrine.entity_manager`) is flushed by `DoctrineTransactionRunner::forEntityManager()`.

Business keys compare exactly (rule 5): `MarketingToolRepository::findByProfileOrdered()` and `findToolRowsByProfile()`
re-check the profile byte for byte after the read, so a case-insensitive MySQL / MariaDB collation never makes
`Default` list the rows of `default`.

## Conflict answers and codes

`EventListener\MarketingToolConflictListener` (kernel.exception, priority 8: before the framework's
`ErrorListener::logKernelException()` at 0, so an expected 409 is not logged as a CRITICAL storage error) answers for
the bundle's admin controller only; host routes keep the host's error handling. It is registered only while
`web_ui.enabled` is true.

| Failure | Status | Headers | Body (host error page) |
|---------|--------|---------|------------------------|
| Deadlock, lock wait timeout, serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), with nothing committed in the request | 409 (configurable to 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` | "Another change to the marketing services is being saved at the same time. Retry in a moment." |
| The same after a commit earlier in the same request, or a lost savepoint of unknown outcome | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` | "Part of the change may already be saved. Reload the page before trying again." |
| Code already used in the profile (seed, import) | 409 | `X-Error-Code: MARKETING_TOOL_DUPLICATE_CODE` | fixed message |
| Tool changed since it was read (import) | 409 | `X-Error-Code: MARKETING_TOOL_STALE` | fixed message |
| Lost connection, any other database error | not mapped | — | a real error (500) |

- `CONFLICT_001`: nothing was committed; retrying is safe.
- `CONFLICT_002`: part of the request may be committed; read again, do not repeat blindly. "Part committed" covers the
  whole request: transaction-doctrine's request watermark (`ConflictCode::forRequest()`, when its bundle runs with
  `commit_detection`), and the bundle's own commit mark on the ledger its commit-tracking middleware feeds.
- `X-Conflict-Code` only ever carries `CONFLICT_001` or `CONFLICT_002`. Domain refusals use `X-Error-Code` and never a
  conflict code. Unique violations are expected "only one" races, not lock conflicts.

The storage error stays the previous exception, for the logs; no SQL or value reaches the client.

## Host options

```yaml
nowo_marketing_kit:
    conflict_response:
        status: ~          # 409 (default) or 503; null follows nowo_transaction_doctrine.conflict_response, else 409
        retry_after: ~     # seconds of a CONFLICT_001 answer; null follows transaction-doctrine, else 1
        commit_tracking: bundle   # bundle (default) | all | off
    doctrine:
        entity_manager: ~  # a dedicated manager for MarketingTool (recommended for DDD hosts)
```

`commit_tracking` decides which commits count for `CONFLICT_002` when transaction-doctrine's `commit_detection` is off:

- `bundle` (default): transaction-doctrine's `CommitTrackingMiddleware` wraps only the connection of the bundle's entity
  manager (`doctrine.entity_manager`, else the default one). Without a dedicated manager this is usually the host's
  default connection, so the host's own queries on it go through the middleware too. The host's other connections are
  never wrapped.
- `all`: every Doctrine connection.
- `off`: no middleware; only the failure itself and transaction-doctrine's request mark decide.

With `web_ui.enabled: false` no admin route answers a conflict, so the bundle registers no ledger, middleware or
listener whatever `commit_tracking` says.

When nowo-tech/transaction-doctrine's bundle is registered with `commit_detection`, its `CommitLedger` is reused and the
bundle registers no middleware: no connection is wrapped twice. On DBAL 3, `Connection::commit()` of a wrapped
connection returns `null` instead of `true`; hosts that check that return value set `commit_tracking: off` or enable
transaction-doctrine's `commit_detection`.

## Worker mode and entity managers

FrankenPHP worker mode with kernel reset off, standard and DDD hosts:

- **The bundle's private manager** (2.0 default, zero config): `DoctrineTransactionRunner::forEntityManager()` on
  transaction-doctrine's `PrivateEntityManager` (same connection and table as the default manager). A failure closes
  only the private manager, which the private registry drops; the application's managers are never flushed, cleared,
  closed or reset, and their pending changes neither block nor ride along with a marketing tool write. Before the
  flush, an application entity held by the private manager fails the unit before anything is written. Covered on the
  real servers by `PrivateEntityManagerDatabaseTest` (CONFLICT_001 edit, CONFLICT_002, concurrent seeds, all-or-nothing
  import) and `ZeroConfigDataLossDatabaseTest`.
- **A manager handed in by code that builds the write unit itself** (`ownManager: false`; the container never does
  since 2.0): an open manager is never cleared, closed or reset
  (rule 2). The unit refuses to start with a `LogicException` when the manager has pending changes on entities other
  than `MarketingTool` (the host's work would be carried into a transaction that may roll back): the bundle never
  flushes the host's work. After a failure a closed manager is replaced through the registry, and an open one forgets
  only the marketing tool rows.
- **Entities rewritten after loading.** Entities whose loaded state a listener rewrites in place, such as the encrypted
  fields of nowo-tech/doctrine-encrypt-bundle (decrypted after loading), always count as pending changes. With such
  entities managed by a manager handed in that way, every write on it throws the `LogicException` above. The 2.0
  default (the private manager) is not affected.
- **Values that are never saved are forgotten.** When the unit refuses to start, and after an invalid submit (422),
  the tool the form bound is detached from the manager, so a later flush of the host's own work never writes the
  refused or unvalidated values.
- **Dedicated manager** (`doctrine.entity_manager` set to a manager other than the host's default, the DDD option):
  `DoctrineTransactionRunner::forEntityManager()`; after a storage failure that manager is closed and reset.
- Nested in a host transaction, the unit runs on a savepoint and repairs the nesting level after a failure (rule 3); a
  lost savepoint is never taken as success.
- `ClosedEntityManagerSubscriber` still replaces a closed bundle manager and detaches stale tool rows at the start of
  each admin request, so the version a page shows is the one in the database.
- No retry inside the unit, no I/O while a lock is held, no listener swallows a transient failure (rules 8 and 9). The
  admin pages are POST + redirect; a retried create after `CONFLICT_001` writes nothing twice, the unique (profile,
  code) key keeps a blind repeat from creating a duplicate, and the seed and import are idempotent (rule 7).

## Tests and CI

- `tests/Integration/Database/MarketingToolConcurrencyDatabaseTest.php` (group `database`): two connections on MySQL
  8.4, MariaDB 11.4 and PostgreSQL 16, each session with a 2 s `innodb_lock_wait_timeout` / `lock_timeout`:
  - two admins creating one code: `CONFLICT_001` while the first insert is open, then the domain 409, one row;
  - an edit of a stale tool: refused by the version, the newer row stays whole;
  - two concurrent toggles: the second is refused by the version, no toggle is lost, the retry flips once more;
  - an edit that meets a locked row: `CONFLICT_001`, nothing written, the retry writes once;
  - two catalog seeds at once: `CONFLICT_001` for the second, then one row per code;
  - a YAML import that meets a locked row: nothing written (all or nothing), the retry writes all, a second run
    upserts without duplicates;
  - a lock conflict after a commit earlier in the request: `CONFLICT_002`, counted by the real middleware.
- Unit tests cover the mapping of every conflict code, the domain codes, the host routes, the write unit on the host's
  and the bundle's manager, the version fields and the commit-tracking scoping.
- CI job "Real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16)" on DBAL 3 and 4, and "Dependency floor
  (nowo-tech/transaction-* lowest, demo lock)", both required checks on `main` (`make test-with-db` locally).

## Residual risks

- **Delete wins over a concurrent edit.** A delete does not check the version: an admin who deletes a tool another
  admin just edited deletes the edited row. Accepted: deleting is the admin's explicit intent, and nothing is
  half-saved.
- **The YAML import overwrites admin edits made before it read the rows.** That is what the import is for; an edit
  committed while the import runs makes the import fail whole (`MARKETING_TOOL_STALE`) instead of being overwritten.
- **Case-insensitive collations.** On MySQL / MariaDB the unique key treats `GTM` and `gtm` (or two profiles that differ
  only in case) as one, so the second create answers `MARKETING_TOOL_DUPLICATE_CODE`. Profile lookups are exact
  (rule 5). Hosts that want case-distinct codes use a binary collation for the `profile` and `code` columns.
- **Old list pages.** A toggle posted from a page rendered before 1.13 carries no version; it is still protected
  against a concurrent write inside the request (the UPDATE checks the version read there), but not against a change
  made between rendering and posting.
- **Low traffic.** The admin is an admin-only, low-traffic path; no retry is built in. A host that wants automatic
  retries aliases transaction-contract's runner to a retrying adapter.
