# Development progress

## Inspection and development plan

27 November 2026. The starting workspace contained PHP stubs, an existing native PDO connection, two CELCO brand assets and seeded lookup data. Read-only inspection covered SHOW CREATE TABLE for all relations, including the four views, indexes, check constraints, foreign keys, table engines and enum values. No database triggers or application users were present. The operational database is reserved for read-only inspection throughout development.

| Phase | Deliverable | Verification |
| --- | --- | --- |
| 1 | Configuration, authentication, role permissions, shared layouts, dashboard, material and master catalogs | PHP lint, HTTP access checks and isolated CRUD tests |
| 2 | Stock documents, locking, balance updates, serial assignment and ledger | Isolated receipt/issue, rollback, duplicate and competing issue tests |
| 3 | Requisitions, returns, transfers, counts, adjustments, disposal, reversal and notifications | Isolated lifecycle, reconciliation and acceptance scenarios |
| 4 | Reports, spreadsheet workflows, administration, documentation and deployment | Export/import tests, access checks and full syntax pass |

Implementation is in progress. CELCO must supply its administrator credentials, valuation policy and production hosting configuration. No operational migration is proposed.

## First administrator setup

The one-time web setup is at `auth/setup-admin.php`. It only accepts registration while there is no active administrator and requires `CELCO_ADMIN_SETUP_TOKEN` to be set in the web server environment as exactly 64 lowercase hexadecimal characters. Generate a token with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`, configure it in Apache (for XAMPP, add `SetEnv CELCO_ADMIN_SETUP_TOKEN "your-generated-token"` to `apache/conf/httpd.conf`), and restart Apache. Enter the token in the setup form; do not put it in the URL or commit it. The route returns 404 after an active administrator is created. The CLI setup remains available through `php cli/create-admin.php`.

