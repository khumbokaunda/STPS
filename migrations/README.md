# Migrations

Apply in order with the `proc_migrate` account.

| File | Purpose |
|---|---|
| `001_secure_procurement_schema_v2.sql` | The authoritative schema (attached, applied unmodified). MySQL 8.0+. |
| `001_secure_procurement_schema_v2_mariadb.sql` | Same schema for **MariaDB / MySQL 5.7** (XAMPP, WAMP). Only difference: collation `utf8mb4_unicode_ci` instead of the MySQL-8-only `utf8mb4_0900_ai_ci`. |
| `002_privileges.sql` | Database privilege split: `proc_migrate`, `proc_app`, `proc_verify`. |
| `003_additive_evidence_payloads.sql` | Additive columns storing exported canonical evidence bytes. |
| `004_seed_roles.sql` | Seed the ten fixed roles. |
| `005_seed_workflows.sql` | Seed default approval workflows + stages (required so approvals work). |

```
mysql -u proc_migrate -p secure_procurement < migrations/001_secure_procurement_schema_v2.sql
mysql -u root       -p                     < migrations/002_privileges.sql
mysql -u proc_migrate -p secure_procurement < migrations/003_additive_evidence_payloads.sql
mysql -u proc_migrate -p secure_procurement < migrations/004_seed_roles.sql
mysql -u proc_migrate -p secure_procurement < migrations/005_seed_workflows.sql
```

### MariaDB / MySQL 5.7 (XAMPP, WAMP, most phpMyAdmin installs)

If import fails with `#1273 - Unknown collation: 'utf8mb4_0900_ai_ci'`, your server
is MariaDB or MySQL 5.7, which lack that MySQL-8 collation. Use the `_mariadb`
variant for step 001 and apply the rest unchanged:

```
mysql -u root -p secure_procurement < migrations/001_secure_procurement_schema_v2_mariadb.sql
```

In phpMyAdmin: create/select the database, open the **Import** tab, and choose
`001_secure_procurement_schema_v2_mariadb.sql`. Then import 002-004 the same way.
Requirements for the CHECK constraints and triggers to be enforced: MariaDB 10.2+
(10.4+ recommended) or MySQL 5.7+.

## Note on migration 003 (a deliberate, additive schema change)

The build specification says: do not silently diverge from the schema; if
something is missing, propose a schema change. Migration 003 is that proposal,
implemented as an **additive** migration (the spec permits additive migrations).

**Why it is needed.** The independent verifier must re-verify approval and
committee-decision signatures without trusting the application. A signature is
over `to_sign = SHA-256(domain 0x1F canonical_json)`, which cannot be
reconstructed from the stored `signed_payload_hash` alone (that is
`SHA-256(canonical)`, and a hash cannot be inverted to recover the canonical
bytes the signature actually covers). `bid_reveals` already stores the exact
canonical bytes in a `LONGBLOB`; `approvals`, `committee_decisions`,
`conflict_declarations`, `awards`, and `contracts` did not.

**What it does.** Adds a nullable `canonical_payload LONGBLOB` to those tables,
storing the exact signed bytes, exactly as `bid_reveals` and `ledger_entries`
already do. No existing column, key, trigger, or check is altered; the append-only
triggers remain in force. This makes the exported evidence fully self-verifying.
