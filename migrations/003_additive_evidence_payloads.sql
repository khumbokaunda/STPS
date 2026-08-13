-- ============================================================
-- Additive migration 003  (does not modify v2 tables destructively)
-- ============================================================
-- Rationale: the independent verifier (bin/verify.php) must verify approval and
-- committee-decision signatures without trusting the application. A signature is
-- over to_sign = SHA-256(domain 0x1F canonical_json), which cannot be derived
-- from the stored signed_payload_hash alone. To make the exported evidence
-- self-verifying, we store the EXACT canonical bytes that were signed, exactly
-- as bid_reveals and ledger_entries already do (build spec rule: hash/sign only
-- the exact canonical bytes in LONGBLOB, never a JSON column).
--
-- This is purely additive: new nullable LONGBLOB columns. No existing column,
-- key, trigger, or check is altered. Append-only triggers remain in force.

USE secure_procurement;

ALTER TABLE approvals
    ADD COLUMN canonical_payload LONGBLOB NULL AFTER signature;

ALTER TABLE committee_decisions
    ADD COLUMN canonical_payload LONGBLOB NULL AFTER decision_hash;

ALTER TABLE conflict_declarations
    ADD COLUMN canonical_payload LONGBLOB NULL AFTER declaration_hash;

ALTER TABLE awards
    ADD COLUMN canonical_payload LONGBLOB NULL AFTER award_hash;

ALTER TABLE contracts
    ADD COLUMN canonical_payload LONGBLOB NULL AFTER contract_hash;
