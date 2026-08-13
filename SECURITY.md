# Security model and threat mapping

This prototype is **tamper-evident**, not tamper-proof. The word "tamper-proof"
is never used.

> The system does not prevent a privileged operator from modifying the database.
> It makes any unauthorized historical modification independently detectable by an
> auditor who trusts none of the system's own infrastructure.

## Trust boundary

The application tier and the database are assumed **potentially compromised**,
including a malicious administrator or DBA. The two things that make historical
tampering detectable are outside operator control:

1. The external RFC 3161 timestamp authority (FreeTSA) that anchors each Merkle
   root. The database can be rewritten by an insider; an anchored root cannot.
2. The independent verifier (`bin/verify.php`), run by an auditor with read-only
   credentials or against an export, trusting none of the application's code or
   write credentials.

## Threats mapped to controls (build spec section 17)

| Threat | Control | Where |
|---|---|---|
| External attacker (injection, IDOR, CSRF, session) | Prepared statements everywhere; object-level authorization; CSRF tokens; secure sessions; TLS-only | `src/db/Db.php` (PDO, no string SQL), `src/auth/Authorization.php`, `src/http/Csrf.php`, `src/auth/Session.php`, `public/index.php` |
| Password database theft | Argon2id (memory 65536, time 4) | `src/auth/Password.php` |
| Bidder alters bid after commit | Commitment mismatch at reveal; reveal also re-signed | `src/procurement/BidService.php`, `src/ledger/Verifier.php` check 8 |
| Early bid disclosure by insider | No nonce or plaintext stored at commit; server-authoritative deadline | `BidService::commit`, schema `bids` (no nonce column) |
| Signature forgery | Client-side private keys (non-extractable, IndexedDB); server holds only public keys | `public/assets/js/webcrypto-client.js`, `src/auth/KeyStore.php` |
| Approver / evaluator repudiation | Signed, append-only evidence with recorded key_id | `approvals`, `evaluation_scores`, append-only triggers + privilege split |
| Evaluator silently reverses scores | Append-only score revisions, each ledgered | `EvaluationService::submitScore` (revision_no, supersedes_score_id) |
| Ledger row edit or delete by app account | Append-only triggers + privilege split (SELECT/INSERT only) | schema triggers, `migrations/002_privileges.sql` |
| DBA rewrites history | Merkle root anchored to external TSA; verifier detects mismatch | `bin/anchor.php`, `bin/verify.php` checks 6, 7 |
| Deadline tampering | RFQ timing trigger + publication ledger entry capturing exact timing | schema `trg_rfq_lock_timing`, `RfqService::publish` |
| Replay of a signed action | Per-action id + state machine rejects repeats; unique keys | `bids` unique (rfq_id, bidder_id); status state machine |
| Unauthorized approval | Role + workflow stage + committee membership checks at decision time | `ApprovalService`, `Rbac`, `Verifier` check 11 |
| Concurrent ledger writes fork the chain | Single-writer append under `SELECT ... FOR UPDATE` | `src/ledger/LedgerWriter.php` |

## What the verifier checks (build spec section 16)

`bin/verify.php` performs, in order, reporting the first failure with the exact
`sequence_no` and entity:

1. Recompute every `payload_hash` from `canonical_payload`.
2. Recompute every `entry_hash`.
3. Chain linkage (`prev_entry_hash` equals the previous `entry_hash`).
4. Sequence continuity 1..N, no gaps.
5. Verify actor / approval / reveal signatures against the key valid at the time.
6. Rebuild every batch's Merkle root.
7. Verify RFC 3161 tokens against the batch root (bundled TSA certs).
8. Recompute every revealed bid's commitment.
9. Recompute committee decision outcomes from linked signed votes and quorum.
10. Timing invariants (commit before deadline, reveal within window).
11. Authorization at decision time.
12. A single PASS, or FAIL with the first detected break.

The offline demonstration `tests/demo/demo_verifier.php` and the adversarial
suite `tests/adversarial/test_adversarial_sqlite.php` exercise the real verifier
end to end and show every attack being detected.

## Cryptographic primitives (fixed)

Argon2id (passwords); SHA-256 (hashing, commitments, Merkle); ECDSA P-256 with
SHA-256 (signatures); 32-byte CSPRNG nonces; TLS 1.2+; AES-256-GCM (optional
escrow); RFC 3161 external timestamps. Forbidden: MD5, SHA-1, DES, RC4, ECB,
fast-hash password storage, server-side custody of user private keys.

## Recovery caveat

A browser-bound private key is lost if the device or browser store is lost. For
production the correct path is WebAuthn or a hardware token. This prototype
provides a re-enrollment path and treats old signatures as historical evidence
tied to the old key (which is why `crypto_keys` records status, created_at, and
revoked_at, and every signed row records its `key_id`).
