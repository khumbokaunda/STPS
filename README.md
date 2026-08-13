# Secure Public Procurement System (STPS)

A security research prototype demonstrating a cryptographically verifiable,
tamper-evident electronic procurement workflow for public institutions in Malawi.

It is **not** a replacement for the national system (MANePS under the PPDA Act
of 2025). It is a proof of concept for a dissertation.

## The security claim (stated honestly)

> The system does not prevent a privileged operator from modifying the database.
> It makes any unauthorized historical modification independently detectable by an
> auditor who trusts none of the system's own infrastructure.

The word "tamper-proof" is never used. The correct term is **tamper-evident**.

## How it works

Three tiers plus a cryptographic evidence layer plus an external anchor plus an
independent verifier:

```
Bidder / official browser  (WebCrypto, private key stays local)
        |  TLS
Application tier (PHP)  --> Authentication, RBAC, workflow, procurement services
        |                    |
        |                    v
        |              Cryptographic evidence: canonicalize, sign-verify,
        |              hash chain, Merkle batching
        v
MySQL (business tables + append-only ledger + public keys)   <-- may be compromised
        |  Merkle root
        v
External TSA (FreeTSA RFC 3161) and optional OpenTimestamps   <-- outside operator control
        |
        v
Independent auditor (bin/verify.php)  <-- trusts none of the above
```

The application plus the database are assumed potentially compromised (including
a malicious administrator or DBA). The external anchor and the independent
verifier are what make historical tampering detectable.

## Technology stack (fixed)

- Frontend: HTML, CSS, JavaScript, Bootstrap 5. No SPA framework.
- Client cryptography: browser WebCrypto (SubtleCrypto). No third-party browser crypto.
- Backend: PHP 8.1+ (procedural), PDO with prepared statements only.
- Database: MySQL 8.0+ (InnoDB).
- External timestamp authority: FreeTSA (RFC 3161), optional OpenTimestamps.
- Transport: HTTPS / TLS 1.2+ only.
- Server crypto: PHP OpenSSL and hash extensions only. No custom crypto.

## Repository layout

```
/public/            web root (only this is served)
/src/crypto/        Canonicalizer, Hasher, Signer, Commitment, MerkleTree, TsaClient
/src/ledger/        LedgerWriter, LedgerReader
/src/auth/          authentication, sessions, RBAC, authorization
/src/procurement/   lifecycle services
/src/db/            PDO factory, transaction helper
/src/http/          request, response, CSRF, input validation
/bin/verify.php     independent auditor CLI
/bin/anchor.php     scheduled anchoring job
/config/            env-driven config (no secrets in code)
/migrations/        the schema SQL plus additive migrations
/tests/             unit + integration + adversarial
```

## Getting started

1. Copy `config/config.example.php` to `config/config.php` (kept out of the web
   root) and set database and TSA settings via environment variables.
2. Apply the schema with the `proc_migrate` account:
   `mysql -u proc_migrate -p < migrations/001_secure_procurement_schema_v2.sql`
   then `migrations/002_privileges.sql`.
3. Run the test suite: `php tests/run.php`.
4. Serve `public/` behind HTTPS.

## Database privilege split

- `proc_migrate`: full DDL, used only for migrations.
- `proc_app`: runtime account. `SELECT, INSERT, UPDATE, DELETE` on mutable
  business tables, but only `SELECT, INSERT` on `ledger_entries`,
  `ledger_batches`, `merkle_anchors`, `approvals`, `bid_commitments`,
  `bid_reveals`, and `evaluation_scores`.
- `proc_verify`: read-only (`SELECT`), used by the independent verifier.

The append-only triggers are defense in depth. The privilege split is the primary
control at the database layer. Neither replaces external anchoring.

## Coding standards

See the build specification. Notable invariants: never store the bid nonce or
plaintext at commit; never place a private key server side; hash and sign only
the exact canonical bytes stored in `LONGBLOB`/`LONGTEXT`, never a JSON column;
Argon2id for passwords; append-only ledger and signed-evidence tables;
application-assigned gapless `sequence_no`; all timestamps UTC ISO-8601 `Z` with
millisecond precision.
