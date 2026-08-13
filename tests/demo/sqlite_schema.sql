-- Minimal SQLite mirror of the verifier-relevant tables, used ONLY by the
-- runnable demonstration (tests/demo/demo_verifier.php) so the independent
-- Verifier can be exercised end-to-end without a MySQL server. The production
-- schema is migrations/001_secure_procurement_schema_v2.sql (MySQL/InnoDB); this
-- file mirrors just the columns the Verifier reads, with BLOB for BINARY and
-- TEXT for DATETIME(6). It is not part of the application.

CREATE TABLE ledger_entries (
  sequence_no      INTEGER PRIMARY KEY,
  actor_id         BLOB NOT NULL,
  action           TEXT NOT NULL,
  entity_type      TEXT NOT NULL,
  entity_id        BLOB NOT NULL,
  canonical_payload BLOB NOT NULL,
  payload_hash     BLOB NOT NULL,
  prev_entry_hash  BLOB,
  entry_hash       BLOB NOT NULL,
  actor_signature  BLOB,
  key_id           BLOB,
  schema_version   TEXT NOT NULL,
  created_at       TEXT NOT NULL
);

CREATE TABLE ledger_batches (
  batch_id       BLOB PRIMARY KEY,
  from_sequence  INTEGER NOT NULL,
  to_sequence    INTEGER NOT NULL,
  merkle_root    BLOB NOT NULL,
  created_at     TEXT NOT NULL,
  status         TEXT NOT NULL
);

CREATE TABLE merkle_anchors (
  anchor_id           BLOB PRIMARY KEY,
  batch_id            BLOB NOT NULL,
  anchor_type         TEXT NOT NULL,
  merkle_root         BLOB NOT NULL,
  timestamp_token     BLOB,
  external_reference  TEXT,
  anchored_at         TEXT,
  verification_status TEXT NOT NULL
);

CREATE TABLE crypto_keys (
  key_id          BLOB PRIMARY KEY,
  user_id         BLOB NOT NULL,
  algorithm       TEXT NOT NULL,
  public_key      TEXT NOT NULL,
  key_fingerprint BLOB NOT NULL,
  status          TEXT NOT NULL,
  created_at      TEXT NOT NULL,
  revoked_at      TEXT
);

CREATE TABLE roles (role_id BLOB PRIMARY KEY, name TEXT NOT NULL);

CREATE TABLE user_roles (
  user_role_id BLOB PRIMARY KEY,
  user_id      BLOB NOT NULL,
  role_id      BLOB NOT NULL,
  valid_from   TEXT NOT NULL,
  valid_until  TEXT,
  status       TEXT NOT NULL
);

CREATE TABLE committees (committee_id BLOB PRIMARY KEY, name TEXT);

CREATE TABLE committee_members (
  membership_id BLOB PRIMARY KEY,
  committee_id  BLOB NOT NULL,
  user_id       BLOB NOT NULL,
  valid_from    TEXT NOT NULL,
  valid_until   TEXT,
  status        TEXT NOT NULL
);

CREATE TABLE workflow_stages (
  stage_id          BLOB PRIMARY KEY,
  workflow_id       BLOB,
  sequence_no       INTEGER,
  stage_type        TEXT,
  required_role     BLOB,
  required_committee BLOB,
  min_approvers     INTEGER
);

CREATE TABLE committee_decisions (
  decision_id      BLOB PRIMARY KEY,
  committee_id     BLOB NOT NULL,
  entity_type      TEXT NOT NULL,
  entity_id        BLOB NOT NULL,
  decision         TEXT NOT NULL,
  quorum_required  INTEGER NOT NULL,
  decision_hash    BLOB NOT NULL,
  canonical_payload BLOB,
  schema_version   TEXT NOT NULL,
  decided_at       TEXT NOT NULL
);

CREATE TABLE approvals (
  approval_id         BLOB PRIMARY KEY,
  workflow_stage_id   BLOB NOT NULL,
  committee_decision_id BLOB,
  entity_type         TEXT NOT NULL,
  entity_id           BLOB NOT NULL,
  approver_id         BLOB NOT NULL,
  key_id              BLOB NOT NULL,
  decision            TEXT NOT NULL,
  reason              TEXT,
  signed_payload_hash BLOB NOT NULL,
  signature           BLOB NOT NULL,
  canonical_payload   BLOB,
  schema_version      TEXT NOT NULL,
  decided_at          TEXT NOT NULL
);

CREATE TABLE rfqs (
  rfq_id          BLOB PRIMARY KEY,
  bid_deadline    TEXT NOT NULL,
  reveal_start    TEXT,
  reveal_deadline TEXT,
  status          TEXT NOT NULL
);

CREATE TABLE bids (
  bid_id          BLOB PRIMARY KEY,
  rfq_id          BLOB NOT NULL,
  bidder_id       BLOB NOT NULL,
  commitment_hash BLOB NOT NULL,
  status          TEXT NOT NULL,
  committed_at    TEXT NOT NULL
);

CREATE TABLE bid_reveals (
  reveal_id         BLOB PRIMARY KEY,
  bid_id            BLOB NOT NULL,
  canonical_payload BLOB NOT NULL,
  nonce             BLOB NOT NULL,
  payload_hash      BLOB NOT NULL,
  signature         BLOB NOT NULL,
  key_id            BLOB NOT NULL,
  schema_version    TEXT NOT NULL,
  revealed_at       TEXT NOT NULL
);
