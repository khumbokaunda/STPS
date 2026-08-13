-- ============================================================
-- Secure Public Procurement Prototype  --  SCHEMA v2
-- MySQL 8.0+
-- ============================================================
-- v2 changes over v1 (all seven review issues addressed):
--   1. ledger_entries now stores the exact canonical_payload bytes,
--      so an auditor can reproduce payload_hash without touching
--      mutable business rows.
--   2. All columns whose bytes are hashed/signed are stored as exact
--      bytes (LONGBLOB / LONGTEXT), never MySQL JSON (which re-normalises
--      and would break hash reproduction). A non-authoritative JSON
--      copy is kept only for querying.
--   3. ledger_entries.sequence_no is application-assigned (no
--      AUTO_INCREMENT), so the chain is gapless and seq is known
--      before entry_hash is computed.
--   4. One authoritative approval path: individual signed votes live
--      in approvals; committee_decisions is a summary that links its
--      constituent approvals, so quorum is verifiable.
--   5. evaluation_scores is append-only with revisions; a score change
--      is a new signed row, never an in-place UPDATE.
--   6. schema_version recorded on every hashed/signed record so the
--      exact canonicalisation ruleset is always reproducible.
--   7. RFQ timing fields (bid_deadline, reveal_start, reveal_deadline)
--      are immutable after publication (trigger) and ordered (CHECK).
--   Plus: key_id on every signed record; XOR on workflow stage target;
--   commitment_hash de-duplicated; append-only triggers on signed
--   evidence tables.
--
-- Conventions:
--   * UUIDs are 16-byte binary, supplied by the application.
--   * SHA-256 digests are BINARY(32).
--   * ALL DATETIME(6) values are UTC, canonicalised as ISO-8601 "Z".
--   * Signatures/public keys are stored binary/text.
-- ============================================================

CREATE DATABASE IF NOT EXISTS secure_procurement
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE secure_procurement;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS merkle_anchors;
DROP TABLE IF EXISTS ledger_batches;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS crypto_keys;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS inspections;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS contracts;
DROP TABLE IF EXISTS awards;
DROP TABLE IF EXISTS evaluation_reports;
DROP TABLE IF EXISTS evaluation_scores;
DROP TABLE IF EXISTS evaluation_criteria;
DROP TABLE IF EXISTS evaluation_team_members;
DROP TABLE IF EXISTS evaluation_teams;
DROP TABLE IF EXISTS conflict_declarations;
DROP TABLE IF EXISTS committee_decisions;
DROP TABLE IF EXISTS committee_members;
DROP TABLE IF EXISTS committees;
DROP TABLE IF EXISTS bid_reveals;
DROP TABLE IF EXISTS bid_commitments;
DROP TABLE IF EXISTS bids;
DROP TABLE IF EXISTS approvals;
DROP TABLE IF EXISTS rfqs;
DROP TABLE IF EXISTS workflow_stages;
DROP TABLE IF EXISTS approval_workflows;
DROP TABLE IF EXISTS bidding_document_versions;
DROP TABLE IF EXISTS bidding_documents;
DROP TABLE IF EXISTS requisition_items;
DROP TABLE IF EXISTS requisitions;
DROP TABLE IF EXISTS procurement_plans;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS bidders;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS users;
-- FK checks stay OFF through all CREATEs so tables with forward references
-- (approvals, bid_commitments, bid_reveals, evaluation_scores -> crypto_keys)
-- create in file order. Re-enabled at the very end of this file.

-- ============================================================
-- IDENTITY & ACCESS
-- ============================================================

CREATE TABLE users (
    user_id              BINARY(16) PRIMARY KEY,
    username             VARCHAR(100) NOT NULL,
    email                VARCHAR(254) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,          -- Argon2id encoded string
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at           DATETIME(6) NOT NULL,
    disabled_at          DATETIME(6) NULL,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT chk_users_status CHECK (status IN ('active','disabled','locked','pending'))
) ENGINE=InnoDB;

CREATE TABLE departments (
    department_id        BINARY(16) PRIMARY KEY,
    name                 VARCHAR(200) NOT NULL,
    code                 VARCHAR(50) NOT NULL,
    created_at           DATETIME(6) NOT NULL,
    UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB;

CREATE TABLE bidders (
    bidder_id                    BINARY(16) PRIMARY KEY,
    legal_name                   VARCHAR(255) NOT NULL,
    registration_number          VARCHAR(100) NULL,
    tax_identifier               VARCHAR(100) NULL,
    ppda_registration_status     VARCHAR(50) NULL,
    verification_status          VARCHAR(30) NOT NULL DEFAULT 'pending',
    verified_at                  DATETIME(6) NULL,
    created_at                   DATETIME(6) NOT NULL,
    UNIQUE KEY uq_bidders_registration (registration_number),
    KEY idx_bidders_tax (tax_identifier),
    CONSTRAINT chk_bidders_verification CHECK (verification_status IN ('pending','verified','rejected','suspended'))
) ENGINE=InnoDB;

ALTER TABLE users
    ADD COLUMN department_id BINARY(16) NULL AFTER email,
    ADD COLUMN bidder_id BINARY(16) NULL AFTER department_id;
ALTER TABLE users
    ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    ADD CONSTRAINT fk_users_bidder FOREIGN KEY (bidder_id) REFERENCES bidders(bidder_id);

CREATE TABLE roles (
    role_id              BINARY(16) PRIMARY KEY,
    name                 VARCHAR(100) NOT NULL,
    description          VARCHAR(500) NULL,
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_id        BINARY(16) PRIMARY KEY,
    name                 VARCHAR(150) NOT NULL,
    description          VARCHAR(500) NULL,
    UNIQUE KEY uq_permissions_name (name)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_role_id         BINARY(16) PRIMARY KEY,
    user_id              BINARY(16) NOT NULL,
    role_id              BINARY(16) NOT NULL,
    valid_from           DATETIME(6) NOT NULL,
    valid_until          DATETIME(6) NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_user_role_period (user_id, role_id, valid_from),
    KEY idx_user_roles_user (user_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id),
    CONSTRAINT chk_user_roles_status CHECK (status IN ('active','expired','revoked'))
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_permission_id   BINARY(16) PRIMARY KEY,
    role_id              BINARY(16) NOT NULL,
    permission_id        BINARY(16) NOT NULL,
    UNIQUE KEY uq_role_permission (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(role_id),
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(permission_id)
) ENGINE=InnoDB;

-- ============================================================
-- PROCUREMENT
-- ============================================================

CREATE TABLE procurement_plans (
    plan_id              BINARY(16) PRIMARY KEY,
    financial_year       VARCHAR(20) NOT NULL,
    status               VARCHAR(30) NOT NULL DEFAULT 'draft',
    plan_hash            BINARY(32) NULL,
    schema_version       VARCHAR(60) NULL,               -- fix 6
    created_at           DATETIME(6) NOT NULL,
    KEY idx_plans_year (financial_year),
    CONSTRAINT chk_plans_status CHECK (status IN ('draft','submitted','approved','closed'))
) ENGINE=InnoDB;

CREATE TABLE requisitions (
    requisition_id       BINARY(16) PRIMARY KEY,
    plan_id              BINARY(16) NULL,
    department_id        BINARY(16) NOT NULL,
    requester_id         BINARY(16) NOT NULL,
    reference_no         VARCHAR(80) NOT NULL,
    title                VARCHAR(255) NOT NULL,
    estimated_value      DECIMAL(20,2) NOT NULL,
    currency_code        CHAR(3) NOT NULL DEFAULT 'MWK',
    procurement_type     VARCHAR(30) NOT NULL,
    status               VARCHAR(40) NOT NULL DEFAULT 'draft',
    canonical_hash       BINARY(32) NULL,
    schema_version       VARCHAR(60) NULL,               -- fix 6
    created_at           DATETIME(6) NOT NULL,
    submitted_at         DATETIME(6) NULL,
    UNIQUE KEY uq_requisitions_reference (reference_no),
    KEY idx_requisitions_department (department_id),
    KEY idx_requisitions_requester (requester_id),
    KEY idx_requisitions_plan (plan_id),
    CONSTRAINT fk_requisitions_plan FOREIGN KEY (plan_id) REFERENCES procurement_plans(plan_id),
    CONSTRAINT fk_requisitions_department FOREIGN KEY (department_id) REFERENCES departments(department_id),
    CONSTRAINT fk_requisitions_requester FOREIGN KEY (requester_id) REFERENCES users(user_id),
    CONSTRAINT chk_requisitions_value CHECK (estimated_value >= 0),
    CONSTRAINT chk_requisitions_status CHECK (status IN ('draft','submitted','approved','rejected','cancelled','procurement'))
) ENGINE=InnoDB;

CREATE TABLE requisition_items (
    item_id              BINARY(16) PRIMARY KEY,
    requisition_id       BINARY(16) NOT NULL,
    description          VARCHAR(1000) NOT NULL,
    quantity             DECIMAL(20,4) NOT NULL,
    unit                 VARCHAR(50) NOT NULL,
    estimated_unit_cost  DECIMAL(20,2) NOT NULL,
    KEY idx_requisition_items_requisition (requisition_id),
    CONSTRAINT fk_requisition_items_requisition FOREIGN KEY (requisition_id) REFERENCES requisitions(requisition_id) ON DELETE CASCADE,
    CONSTRAINT chk_requisition_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_requisition_items_cost CHECK (estimated_unit_cost >= 0)
) ENGINE=InnoDB;

CREATE TABLE bidding_documents (
    document_id          BINARY(16) PRIMARY KEY,
    requisition_id       BINARY(16) NOT NULL,
    status               VARCHAR(30) NOT NULL DEFAULT 'draft',
    approved_version_id  BINARY(16) NULL,
    created_at           DATETIME(6) NOT NULL,
    UNIQUE KEY uq_bidding_documents_requisition (requisition_id),
    CONSTRAINT fk_bidding_documents_requisition FOREIGN KEY (requisition_id) REFERENCES requisitions(requisition_id),
    CONSTRAINT chk_bidding_documents_status CHECK (status IN ('draft','pending_approval','approved','superseded','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE bidding_document_versions (
    version_id           BINARY(16) PRIMARY KEY,
    document_id          BINARY(16) NOT NULL,
    version_no           INT UNSIGNED NOT NULL,
    content_hash         BINARY(32) NOT NULL,
    schema_version       VARCHAR(60) NOT NULL,           -- fix 6
    storage_reference    VARCHAR(1000) NOT NULL,
    created_by           BINARY(16) NOT NULL,
    created_at           DATETIME(6) NOT NULL,
    UNIQUE KEY uq_document_version (document_id, version_no),
    KEY idx_document_versions_hash (content_hash),
    CONSTRAINT fk_document_versions_document FOREIGN KEY (document_id) REFERENCES bidding_documents(document_id) ON DELETE CASCADE,
    CONSTRAINT fk_document_versions_creator FOREIGN KEY (created_by) REFERENCES users(user_id),
    CONSTRAINT chk_document_version CHECK (version_no > 0)
) ENGINE=InnoDB;

ALTER TABLE bidding_documents
    ADD CONSTRAINT fk_bidding_documents_approved_version
    FOREIGN KEY (approved_version_id) REFERENCES bidding_document_versions(version_id);

CREATE TABLE rfqs (
    rfq_id                       BINARY(16) PRIMARY KEY,
    requisition_id               BINARY(16) NOT NULL,
    bidding_document_version_id  BINARY(16) NOT NULL,
    reference_no                 VARCHAR(80) NOT NULL,
    procurement_method           VARCHAR(80) NOT NULL,
    published_at                 DATETIME(6) NULL,
    bid_deadline                 DATETIME(6) NOT NULL,
    reveal_start                 DATETIME(6) NULL,
    reveal_deadline              DATETIME(6) NULL,
    status                       VARCHAR(30) NOT NULL DEFAULT 'draft',
    created_at                   DATETIME(6) NOT NULL,
    UNIQUE KEY uq_rfqs_reference (reference_no),
    KEY idx_rfqs_deadline (bid_deadline),
    KEY idx_rfqs_requisition (requisition_id),
    CONSTRAINT fk_rfqs_requisition FOREIGN KEY (requisition_id) REFERENCES requisitions(requisition_id),
    CONSTRAINT fk_rfqs_document_version FOREIGN KEY (bidding_document_version_id) REFERENCES bidding_document_versions(version_id),
    CONSTRAINT chk_rfqs_status CHECK (status IN ('draft','published','closed','reveal_open','revealed','evaluation','awarded','cancelled')),
    CONSTRAINT chk_rfq_reveal_start CHECK (reveal_start IS NULL OR reveal_start >= bid_deadline),                              -- fix 7
    CONSTRAINT chk_rfq_reveal_deadline CHECK (reveal_deadline IS NULL OR (reveal_start IS NOT NULL AND reveal_deadline > reveal_start))  -- fix 7
) ENGINE=InnoDB;

-- ============================================================
-- WORKFLOW  (configurable, polymorphic, committee-aware)
-- ============================================================

CREATE TABLE approval_workflows (
    workflow_id          BINARY(16) PRIMARY KEY,
    name                 VARCHAR(200) NOT NULL,
    min_amount           DECIMAL(20,2) NOT NULL,
    max_amount           DECIMAL(20,2) NULL,
    procurement_type     VARCHAR(30) NOT NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at           DATETIME(6) NOT NULL,
    KEY idx_workflows_band (procurement_type, min_amount, max_amount),
    CONSTRAINT chk_workflow_min CHECK (min_amount >= 0),
    CONSTRAINT chk_workflow_max CHECK (max_amount IS NULL OR max_amount >= min_amount),
    CONSTRAINT chk_workflow_status CHECK (status IN ('active','inactive'))
) ENGINE=InnoDB;

CREATE TABLE workflow_stages (
    stage_id             BINARY(16) PRIMARY KEY,
    workflow_id          BINARY(16) NOT NULL,
    sequence_no          INT UNSIGNED NOT NULL,
    stage_type           VARCHAR(60) NOT NULL,
    required_role        BINARY(16) NULL,
    required_committee   BINARY(16) NULL,
    min_approvers        INT UNSIGNED NOT NULL DEFAULT 1,
    requires_all         BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY uq_workflow_stage_sequence (workflow_id, sequence_no),
    KEY idx_workflow_stages_role (required_role),
    KEY idx_workflow_stages_committee (required_committee),
    CONSTRAINT fk_workflow_stages_workflow FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id) ON DELETE CASCADE,
    CONSTRAINT fk_workflow_stages_role FOREIGN KEY (required_role) REFERENCES roles(role_id),
    CONSTRAINT chk_workflow_stage_approvers CHECK (min_approvers >= 1),
    CONSTRAINT chk_workflow_stage_target CHECK ((required_role IS NOT NULL) + (required_committee IS NOT NULL) = 1)  -- fix (XOR)
) ENGINE=InnoDB;

-- ============================================================
-- COMMITTEES  (declared before approvals so FKs resolve)
-- ============================================================

CREATE TABLE committees (
    committee_id         BINARY(16) PRIMARY KEY,
    committee_type       VARCHAR(60) NOT NULL,
    name                 VARCHAR(255) NOT NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at           DATETIME(6) NOT NULL,
    KEY idx_committees_type (committee_type),
    CONSTRAINT chk_committees_status CHECK (status IN ('active','inactive','dissolved'))
) ENGINE=InnoDB;

ALTER TABLE workflow_stages
    ADD CONSTRAINT fk_workflow_stages_committee FOREIGN KEY (required_committee) REFERENCES committees(committee_id);

CREATE TABLE committee_members (
    membership_id        BINARY(16) PRIMARY KEY,
    committee_id         BINARY(16) NOT NULL,
    user_id              BINARY(16) NOT NULL,
    position             VARCHAR(100) NULL,
    valid_from           DATETIME(6) NOT NULL,
    valid_until          DATETIME(6) NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    KEY idx_committee_members_committee (committee_id),
    KEY idx_committee_members_user (user_id),
    CONSTRAINT fk_committee_members_committee FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    CONSTRAINT fk_committee_members_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT chk_committee_members_status CHECK (status IN ('active','expired','revoked'))
) ENGINE=InnoDB;

-- committee_decisions is a SUMMARY (fix 4). The authoritative signed
-- votes are rows in approvals linked via approvals.committee_decision_id.
-- quorum_required lets an auditor recompute the outcome from those votes.
CREATE TABLE committee_decisions (
    decision_id          BINARY(16) PRIMARY KEY,
    committee_id         BINARY(16) NOT NULL,
    entity_type          VARCHAR(60) NOT NULL,
    entity_id            BINARY(16) NOT NULL,
    decision             VARCHAR(20) NOT NULL,
    quorum_required      INT UNSIGNED NOT NULL DEFAULT 1,  -- fix 4
    decision_hash        BINARY(32) NOT NULL,
    schema_version       VARCHAR(60) NOT NULL,             -- fix 6
    decided_at           DATETIME(6) NOT NULL,
    KEY idx_committee_decisions_entity (entity_type, entity_id),
    KEY idx_committee_decisions_committee (committee_id),
    CONSTRAINT fk_committee_decisions_committee FOREIGN KEY (committee_id) REFERENCES committees(committee_id),
    CONSTRAINT chk_committee_decisions_decision CHECK (decision IN ('approved','rejected','deferred')),
    CONSTRAINT chk_committee_decisions_quorum CHECK (quorum_required >= 1)
) ENGINE=InnoDB;

-- approvals: the single authoritative, signed, append-only approval record (fix 4)
CREATE TABLE approvals (
    approval_id              BINARY(16) PRIMARY KEY,
    workflow_stage_id        BINARY(16) NOT NULL,
    committee_decision_id    BINARY(16) NULL,             -- set when this vote is part of a committee decision
    entity_type              VARCHAR(60) NOT NULL,
    entity_id                BINARY(16) NOT NULL,
    approver_id              BINARY(16) NOT NULL,
    key_id                   BINARY(16) NOT NULL,         -- key that produced the signature
    decision                 VARCHAR(20) NOT NULL,
    reason                   VARCHAR(2000) NULL,
    signed_payload_hash      BINARY(32) NOT NULL,
    signature                VARBINARY(2048) NOT NULL,
    schema_version           VARCHAR(60) NOT NULL,        -- fix 6
    decided_at               DATETIME(6) NOT NULL,
    KEY idx_approvals_entity (entity_type, entity_id),
    KEY idx_approvals_stage (workflow_stage_id),
    KEY idx_approvals_approver (approver_id),
    KEY idx_approvals_decision (committee_decision_id),
    CONSTRAINT fk_approvals_stage FOREIGN KEY (workflow_stage_id) REFERENCES workflow_stages(stage_id),
    CONSTRAINT fk_approvals_decision FOREIGN KEY (committee_decision_id) REFERENCES committee_decisions(decision_id),
    CONSTRAINT fk_approvals_approver FOREIGN KEY (approver_id) REFERENCES users(user_id),
    CONSTRAINT fk_approvals_key FOREIGN KEY (key_id) REFERENCES crypto_keys(key_id),
    CONSTRAINT chk_approvals_decision CHECK (decision IN ('approved','rejected'))
) ENGINE=InnoDB;

CREATE TABLE conflict_declarations (
    declaration_id       BINARY(16) PRIMARY KEY,
    user_id              BINARY(16) NOT NULL,
    entity_type          VARCHAR(60) NOT NULL,
    entity_id            BINARY(16) NOT NULL,
    has_conflict         BOOLEAN NOT NULL,
    declaration_hash     BINARY(32) NOT NULL,
    schema_version       VARCHAR(60) NOT NULL,            -- fix 6
    declared_at          DATETIME(6) NOT NULL,
    KEY idx_conflicts_entity (entity_type, entity_id),
    KEY idx_conflicts_user (user_id),
    CONSTRAINT fk_conflicts_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ============================================================
-- EVALUATION TEAM
-- ============================================================

CREATE TABLE evaluation_teams (
    team_id              BINARY(16) PRIMARY KEY,
    rfq_id               BINARY(16) NOT NULL,
    constituted_by       BINARY(16) NOT NULL,
    constituted_at       DATETIME(6) NOT NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_evaluation_team_rfq (rfq_id),
    CONSTRAINT fk_evaluation_teams_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id),
    CONSTRAINT fk_evaluation_teams_constituted_by FOREIGN KEY (constituted_by) REFERENCES users(user_id),
    CONSTRAINT chk_evaluation_teams_status CHECK (status IN ('active','completed','dissolved'))
) ENGINE=InnoDB;

CREATE TABLE evaluation_team_members (
    membership_id        BINARY(16) PRIMARY KEY,
    team_id              BINARY(16) NOT NULL,
    user_id              BINARY(16) NOT NULL,
    position             VARCHAR(100) NULL,
    valid_from           DATETIME(6) NOT NULL,
    valid_until          DATETIME(6) NULL,
    UNIQUE KEY uq_evaluation_team_member (team_id, user_id),
    KEY idx_evaluation_team_members_user (user_id),
    CONSTRAINT fk_evaluation_team_members_team FOREIGN KEY (team_id) REFERENCES evaluation_teams(team_id) ON DELETE CASCADE,
    CONSTRAINT fk_evaluation_team_members_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ============================================================
-- SEALED BIDS  (commit / reveal)
-- ============================================================

CREATE TABLE bids (
    bid_id               BINARY(16) PRIMARY KEY,
    rfq_id               BINARY(16) NOT NULL,
    bidder_id            BINARY(16) NOT NULL,
    commitment_hash      BINARY(32) NOT NULL,     -- authoritative operational commitment C
    escrow_ciphertext    MEDIUMBLOB NULL,         -- meaningful only with threshold-controlled decryption
    status               VARCHAR(30) NOT NULL DEFAULT 'committed',
    committed_at         DATETIME(6) NOT NULL,
    UNIQUE KEY uq_bid_one_per_bidder (rfq_id, bidder_id),
    KEY idx_bids_rfq (rfq_id),
    KEY idx_bids_commitment (commitment_hash),
    CONSTRAINT fk_bids_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id),
    CONSTRAINT fk_bids_bidder FOREIGN KEY (bidder_id) REFERENCES bidders(bidder_id),
    CONSTRAINT chk_bids_status CHECK (status IN ('committed','revealed','expired','invalid','evaluated','awarded','not_awarded'))
) ENGINE=InnoDB;

-- signed commitment record (append-only). Signs bids.commitment_hash; no
-- duplicate hash column here (fix: de-duplicated).
CREATE TABLE bid_commitments (
    commitment_id        BINARY(16) PRIMARY KEY,
    bid_id               BINARY(16) NOT NULL,
    signature            VARBINARY(2048) NOT NULL,
    key_id               BINARY(16) NOT NULL,
    schema_version       VARCHAR(60) NOT NULL,           -- fix 6
    committed_at         DATETIME(6) NOT NULL,
    UNIQUE KEY uq_bid_commitment (bid_id),
    KEY idx_bid_commitments_key (key_id),
    CONSTRAINT fk_bid_commitments_bid FOREIGN KEY (bid_id) REFERENCES bids(bid_id) ON DELETE CASCADE,
    CONSTRAINT fk_bid_commitments_key FOREIGN KEY (key_id) REFERENCES crypto_keys(key_id)
) ENGINE=InnoDB;

-- reveal record (append-only). canonical_payload holds EXACT bytes (fix 2);
-- nonce appears only here, never at commitment (fix 1 discipline).
CREATE TABLE bid_reveals (
    reveal_id             BINARY(16) PRIMARY KEY,
    bid_id                BINARY(16) NOT NULL,
    canonical_payload     LONGBLOB NOT NULL,             -- exact hashed/signed bytes
    payload_json          JSON NULL,                     -- non-authoritative, for querying only
    nonce                 VARBINARY(64) NOT NULL,
    payload_hash          BINARY(32) NOT NULL,
    signature             VARBINARY(2048) NOT NULL,
    key_id                BINARY(16) NOT NULL,
    schema_version        VARCHAR(60) NOT NULL,          -- fix 6
    revealed_at           DATETIME(6) NOT NULL,
    UNIQUE KEY uq_bid_reveal (bid_id),
    KEY idx_bid_reveals_hash (payload_hash),
    KEY idx_bid_reveals_key (key_id),
    CONSTRAINT fk_bid_reveals_bid FOREIGN KEY (bid_id) REFERENCES bids(bid_id) ON DELETE CASCADE,
    CONSTRAINT fk_bid_reveals_key FOREIGN KEY (key_id) REFERENCES crypto_keys(key_id)
) ENGINE=InnoDB;

-- ============================================================
-- EVALUATION  (append-only scoring with revisions -- fix 5)
-- ============================================================

CREATE TABLE evaluation_criteria (
    criterion_id          BINARY(16) PRIMARY KEY,
    rfq_id                BINARY(16) NOT NULL,
    name                  VARCHAR(255) NOT NULL,
    description           VARCHAR(2000) NULL,
    weight                DECIMAL(8,4) NOT NULL,
    maximum_score         DECIMAL(12,4) NOT NULL,
    sequence_no           INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_evaluation_criterion_sequence (rfq_id, sequence_no),
    CONSTRAINT fk_evaluation_criteria_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id) ON DELETE CASCADE,
    CONSTRAINT chk_evaluation_criteria_weight CHECK (weight >= 0),
    CONSTRAINT chk_evaluation_criteria_max_score CHECK (maximum_score > 0)
) ENGINE=InnoDB;

CREATE TABLE evaluation_scores (
    score_id               BINARY(16) PRIMARY KEY,
    criterion_id           BINARY(16) NOT NULL,
    bid_id                 BINARY(16) NOT NULL,
    evaluator_id           BINARY(16) NOT NULL,
    key_id                 BINARY(16) NOT NULL,
    revision_no            INT UNSIGNED NOT NULL DEFAULT 1,   -- fix 5
    supersedes_score_id    BINARY(16) NULL,                    -- fix 5
    score                  DECIMAL(12,4) NOT NULL,
    justification          VARCHAR(4000) NULL,
    payload_hash           BINARY(32) NOT NULL,
    signature              VARBINARY(2048) NOT NULL,
    schema_version         VARCHAR(60) NOT NULL,               -- fix 6
    created_at             DATETIME(6) NOT NULL,
    UNIQUE KEY uq_evaluation_score_rev (criterion_id, bid_id, evaluator_id, revision_no),  -- fix 5
    KEY idx_evaluation_scores_bid (bid_id),
    KEY idx_evaluation_scores_evaluator (evaluator_id),
    KEY idx_evaluation_scores_supersedes (supersedes_score_id),
    CONSTRAINT fk_evaluation_scores_criterion FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(criterion_id),
    CONSTRAINT fk_evaluation_scores_bid FOREIGN KEY (bid_id) REFERENCES bids(bid_id),
    CONSTRAINT fk_evaluation_scores_evaluator FOREIGN KEY (evaluator_id) REFERENCES users(user_id),
    CONSTRAINT fk_evaluation_scores_key FOREIGN KEY (key_id) REFERENCES crypto_keys(key_id),
    CONSTRAINT fk_evaluation_scores_supersedes FOREIGN KEY (supersedes_score_id) REFERENCES evaluation_scores(score_id),
    CONSTRAINT chk_evaluation_score_range CHECK (score >= 0),
    CONSTRAINT chk_evaluation_score_revision CHECK (revision_no >= 1)
) ENGINE=InnoDB;

CREATE TABLE evaluation_reports (
    report_id              BINARY(16) PRIMARY KEY,
    rfq_id                 BINARY(16) NOT NULL,
    evaluation_team_id     BINARY(16) NOT NULL,
    report_hash            BINARY(32) NOT NULL,
    schema_version         VARCHAR(60) NOT NULL,          -- fix 6
    recommendation         VARCHAR(2000) NOT NULL,
    submitted_at           DATETIME(6) NOT NULL,
    UNIQUE KEY uq_evaluation_report_rfq (rfq_id),
    CONSTRAINT fk_evaluation_reports_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id),
    CONSTRAINT fk_evaluation_reports_team FOREIGN KEY (evaluation_team_id) REFERENCES evaluation_teams(team_id)
) ENGINE=InnoDB;

-- ============================================================
-- AWARD / CONTRACT / EXECUTION
-- ============================================================

CREATE TABLE awards (
    award_id               BINARY(16) PRIMARY KEY,
    rfq_id                 BINARY(16) NOT NULL,
    winning_bid_id         BINARY(16) NOT NULL,
    award_value            DECIMAL(20,2) NOT NULL,
    currency_code          CHAR(3) NOT NULL DEFAULT 'MWK',
    award_hash             BINARY(32) NOT NULL,
    schema_version         VARCHAR(60) NOT NULL,          -- fix 6
    status                 VARCHAR(30) NOT NULL DEFAULT 'pending',
    awarded_at             DATETIME(6) NULL,
    UNIQUE KEY uq_award_rfq (rfq_id),
    CONSTRAINT fk_awards_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(rfq_id),
    CONSTRAINT fk_awards_winning_bid FOREIGN KEY (winning_bid_id) REFERENCES bids(bid_id),
    CONSTRAINT chk_awards_value CHECK (award_value >= 0),
    CONSTRAINT chk_awards_status CHECK (status IN ('pending','approved','notified','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE contracts (
    contract_id                 BINARY(16) PRIMARY KEY,
    award_id                    BINARY(16) NOT NULL,
    bidder_id                   BINARY(16) NOT NULL,
    contract_number             VARCHAR(100) NOT NULL,
    contract_value              DECIMAL(20,2) NOT NULL,
    currency_code               CHAR(3) NOT NULL DEFAULT 'MWK',
    contract_hash               BINARY(32) NOT NULL,
    schema_version              VARCHAR(60) NOT NULL,     -- fix 6
    requires_ppda_no_objection  BOOLEAN NOT NULL DEFAULT FALSE,
    ppda_no_objection_reference VARCHAR(255) NULL,
    controlling_officer_id      BINARY(16) NULL,
    signed_at                   DATETIME(6) NULL,
    status                      VARCHAR(30) NOT NULL DEFAULT 'draft',
    UNIQUE KEY uq_contract_number (contract_number),
    KEY idx_contracts_bidder (bidder_id),
    CONSTRAINT fk_contracts_award FOREIGN KEY (award_id) REFERENCES awards(award_id),
    CONSTRAINT fk_contracts_bidder FOREIGN KEY (bidder_id) REFERENCES bidders(bidder_id),
    CONSTRAINT fk_contracts_controlling_officer FOREIGN KEY (controlling_officer_id) REFERENCES users(user_id),
    CONSTRAINT chk_contract_value CHECK (contract_value >= 0),
    CONSTRAINT chk_contract_no_objection CHECK (requires_ppda_no_objection = FALSE OR ppda_no_objection_reference IS NOT NULL),
    CONSTRAINT chk_contract_status CHECK (status IN ('draft','pending_signature','pending_no_objection','signed','active','completed','terminated'))
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    purchase_order_id       BINARY(16) PRIMARY KEY,
    contract_id             BINARY(16) NOT NULL,
    po_number               VARCHAR(100) NOT NULL,
    total_value             DECIMAL(20,2) NOT NULL,
    currency_code           CHAR(3) NOT NULL DEFAULT 'MWK',
    status                  VARCHAR(30) NOT NULL DEFAULT 'draft',
    issued_at               DATETIME(6) NULL,
    UNIQUE KEY uq_purchase_order_number (po_number),
    CONSTRAINT fk_purchase_orders_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id),
    CONSTRAINT chk_purchase_order_value CHECK (total_value >= 0),
    CONSTRAINT chk_purchase_order_status CHECK (status IN ('draft','issued','partially_fulfilled','fulfilled','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE deliveries (
    delivery_id             BINARY(16) PRIMARY KEY,
    purchase_order_id       BINARY(16) NOT NULL,
    delivery_reference      VARCHAR(100) NOT NULL,
    delivered_at            DATETIME(6) NOT NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'received',
    UNIQUE KEY uq_delivery_reference (delivery_reference),
    CONSTRAINT fk_deliveries_purchase_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id),
    CONSTRAINT chk_delivery_status CHECK (status IN ('received','inspected','rejected','accepted'))
) ENGINE=InnoDB;

CREATE TABLE inspections (
    inspection_id           BINARY(16) PRIMARY KEY,
    delivery_id             BINARY(16) NOT NULL,
    inspector_id            BINARY(16) NOT NULL,
    result                  VARCHAR(30) NOT NULL,
    inspection_hash         BINARY(32) NOT NULL,
    schema_version          VARCHAR(60) NOT NULL,         -- fix 6
    inspected_at            DATETIME(6) NOT NULL,
    KEY idx_inspections_delivery (delivery_id),
    CONSTRAINT fk_inspections_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(delivery_id),
    CONSTRAINT fk_inspections_inspector FOREIGN KEY (inspector_id) REFERENCES users(user_id),
    CONSTRAINT chk_inspection_result CHECK (result IN ('accepted','partially_accepted','rejected'))
) ENGINE=InnoDB;

CREATE TABLE invoices (
    invoice_id              BINARY(16) PRIMARY KEY,
    contract_id             BINARY(16) NOT NULL,
    invoice_number          VARCHAR(100) NOT NULL,
    amount                  DECIMAL(20,2) NOT NULL,
    currency_code           CHAR(3) NOT NULL DEFAULT 'MWK',
    status                  VARCHAR(30) NOT NULL DEFAULT 'submitted',
    submitted_at            DATETIME(6) NOT NULL,
    UNIQUE KEY uq_invoice_number (invoice_number),
    CONSTRAINT fk_invoices_contract FOREIGN KEY (contract_id) REFERENCES contracts(contract_id),
    CONSTRAINT chk_invoice_amount CHECK (amount > 0),
    CONSTRAINT chk_invoice_status CHECK (status IN ('submitted','approved','rejected','paid','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id              BINARY(16) PRIMARY KEY,
    invoice_id              BINARY(16) NOT NULL,
    amount                  DECIMAL(20,2) NOT NULL,
    payment_reference       VARCHAR(150) NOT NULL,
    paid_at                 DATETIME(6) NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'pending',
    UNIQUE KEY uq_payment_reference (payment_reference),
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT chk_payment_amount CHECK (amount > 0),
    CONSTRAINT chk_payment_status CHECK (status IN ('pending','authorized','paid','failed','reversed'))
) ENGINE=InnoDB;

-- ============================================================
-- CRYPTOGRAPHIC IDENTITY
-- ============================================================

CREATE TABLE crypto_keys (
    key_id                  BINARY(16) PRIMARY KEY,
    user_id                 BINARY(16) NOT NULL,
    algorithm               VARCHAR(50) NOT NULL,
    public_key              TEXT NOT NULL,
    key_fingerprint         BINARY(32) NOT NULL,
    status                  VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at              DATETIME(6) NOT NULL,
    revoked_at              DATETIME(6) NULL,
    KEY idx_crypto_keys_user (user_id),
    UNIQUE KEY uq_crypto_keys_fingerprint (key_fingerprint),
    CONSTRAINT fk_crypto_keys_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    CONSTRAINT chk_crypto_keys_algorithm CHECK (algorithm IN ('ECDSA-P256')),
    CONSTRAINT chk_crypto_keys_status CHECK (status IN ('active','revoked','expired'))
) ENGINE=InnoDB;

-- ============================================================
-- APPEND-ONLY HASH-CHAIN LEDGER
-- sequence_no is application-assigned under SELECT ... FOR UPDATE (fix 3).
-- canonical_payload holds the exact bytes hashed into payload_hash (fix 1).
-- ============================================================

CREATE TABLE ledger_entries (
    sequence_no             BIGINT UNSIGNED NOT NULL,        -- fix 3: NOT auto-increment
    actor_id                BINARY(16) NOT NULL,
    action                  VARCHAR(100) NOT NULL,
    entity_type             VARCHAR(60) NOT NULL,
    entity_id               BINARY(16) NOT NULL,
    canonical_payload       LONGBLOB NOT NULL,               -- fix 1: exact event bytes
    payload_hash            BINARY(32) NOT NULL,
    prev_entry_hash         BINARY(32) NULL,
    entry_hash              BINARY(32) NOT NULL,
    actor_signature         VARBINARY(2048) NULL,
    key_id                  BINARY(16) NULL,
    schema_version          VARCHAR(60) NOT NULL,            -- fix 6
    created_at              DATETIME(6) NOT NULL,
    PRIMARY KEY (sequence_no),
    UNIQUE KEY uq_ledger_entry_hash (entry_hash),
    KEY idx_ledger_entity (entity_type, entity_id),
    KEY idx_ledger_actor (actor_id),
    KEY idx_ledger_created (created_at),
    CONSTRAINT fk_ledger_actor FOREIGN KEY (actor_id) REFERENCES users(user_id),
    CONSTRAINT fk_ledger_key FOREIGN KEY (key_id) REFERENCES crypto_keys(key_id),
    CONSTRAINT chk_ledger_genesis CHECK (
        (sequence_no = 1 AND prev_entry_hash IS NULL)
        OR
        (sequence_no > 1 AND prev_entry_hash IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE ledger_batches (
    batch_id                BINARY(16) PRIMARY KEY,
    from_sequence           BIGINT UNSIGNED NOT NULL,
    to_sequence             BIGINT UNSIGNED NOT NULL,
    merkle_root             BINARY(32) NOT NULL,
    created_at              DATETIME(6) NOT NULL,
    status                  VARCHAR(30) NOT NULL DEFAULT 'created',
    UNIQUE KEY uq_ledger_batch_range (from_sequence, to_sequence),
    KEY idx_ledger_batches_root (merkle_root),
    CONSTRAINT chk_ledger_batch_range CHECK (to_sequence >= from_sequence),
    CONSTRAINT chk_ledger_batch_status CHECK (status IN ('created','anchored','verified','failed'))
) ENGINE=InnoDB;

CREATE TABLE merkle_anchors (
    anchor_id               BINARY(16) PRIMARY KEY,
    batch_id                BINARY(16) NOT NULL,
    anchor_type             VARCHAR(40) NOT NULL,
    merkle_root             BINARY(32) NOT NULL,
    timestamp_token         MEDIUMBLOB NULL,
    external_reference      VARCHAR(1000) NULL,
    anchored_at             DATETIME(6) NULL,
    verification_status     VARCHAR(30) NOT NULL DEFAULT 'pending',
    KEY idx_merkle_anchors_root (merkle_root),
    KEY idx_merkle_anchors_batch (batch_id),
    CONSTRAINT fk_merkle_anchors_batch FOREIGN KEY (batch_id) REFERENCES ledger_batches(batch_id),
    CONSTRAINT chk_merkle_anchor_type CHECK (anchor_type IN ('RFC3161','OPENTIMESTAMPS')),
    CONSTRAINT chk_merkle_anchor_status CHECK (verification_status IN ('pending','verified','failed'))
) ENGINE=InnoDB;

-- ============================================================
-- APPEND-ONLY PROTECTION (defense-in-depth; not a substitute for
-- external anchoring, since a privileged DBA can drop triggers)
-- ============================================================

DELIMITER $$

CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are append-only'; END$$
CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are append-only'; END$$

CREATE TRIGGER trg_approvals_no_update BEFORE UPDATE ON approvals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals are append-only'; END$$
CREATE TRIGGER trg_approvals_no_delete BEFORE DELETE ON approvals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approvals are append-only'; END$$

CREATE TRIGGER trg_bid_commitments_no_update BEFORE UPDATE ON bid_commitments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bid_commitments are append-only'; END$$
CREATE TRIGGER trg_bid_commitments_no_delete BEFORE DELETE ON bid_commitments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bid_commitments are append-only'; END$$

CREATE TRIGGER trg_bid_reveals_no_update BEFORE UPDATE ON bid_reveals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bid_reveals are append-only'; END$$
CREATE TRIGGER trg_bid_reveals_no_delete BEFORE DELETE ON bid_reveals FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bid_reveals are append-only'; END$$

CREATE TRIGGER trg_eval_scores_no_update BEFORE UPDATE ON evaluation_scores FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation_scores are append-only'; END$$
CREATE TRIGGER trg_eval_scores_no_delete BEFORE DELETE ON evaluation_scores FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'evaluation_scores are append-only'; END$$

-- fix 7: RFQ timing immutable once published
CREATE TRIGGER trg_rfq_lock_timing BEFORE UPDATE ON rfqs FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft' THEN
        IF NOT (NEW.bid_deadline    <=> OLD.bid_deadline)
        OR NOT (NEW.reveal_start    <=> OLD.reveal_start)
        OR NOT (NEW.reveal_deadline <=> OLD.reveal_deadline) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'RFQ timing is immutable after publication';
        END IF;
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- VERIFICATION VIEWS
-- ============================================================

CREATE VIEW v_ledger_chain AS
SELECT sequence_no, actor_id, action, entity_type, entity_id,
       HEX(payload_hash) AS payload_hash_hex,
       HEX(prev_entry_hash) AS prev_entry_hash_hex,
       HEX(entry_hash) AS entry_hash_hex,
       schema_version, created_at
FROM ledger_entries ORDER BY sequence_no;

CREATE VIEW v_active_crypto_keys AS
SELECT key_id, user_id, algorithm, public_key,
       HEX(key_fingerprint) AS fingerprint_hex, created_at
FROM crypto_keys WHERE status = 'active';

-- current evaluation score = highest revision per (criterion, bid, evaluator)
CREATE VIEW v_current_evaluation_scores AS
SELECT s.*
FROM evaluation_scores s
JOIN (
    SELECT criterion_id, bid_id, evaluator_id, MAX(revision_no) AS max_rev
    FROM evaluation_scores
    GROUP BY criterion_id, bid_id, evaluator_id
) m ON m.criterion_id = s.criterion_id AND m.bid_id = s.bid_id
   AND m.evaluator_id = s.evaluator_id AND m.max_rev = s.revision_no;

-- ============================================================
-- IMPLEMENTATION NOTES (v2)
-- ============================================================
-- 1.  Never store bid nonce or plaintext at commitment time.
-- 2.  Never put private signing keys in MySQL.
-- 3.  Hash/sign only the exact canonical bytes (LONGBLOB/LONGTEXT),
--     never a JSON-typed column (MySQL re-normalises JSON).
-- 4.  Ledger append: BEGIN; SELECT ... ORDER BY sequence_no DESC LIMIT 1
--     FOR UPDATE; seq = prev.seq + 1; compute entry_hash; INSERT; COMMIT.
-- 5.  Store the exact canonical_payload in ledger_entries so payload_hash
--     is reproducible after business rows mutate.
-- 6.  Record schema_version (canonicalisation ruleset + domain separator)
--     on every hashed/signed record.
-- 7.  Give the application DB account INSERT/SELECT on ledger + signed
--     evidence tables, but not UPDATE/DELETE. Triggers are backup only.
-- 8.  All DATETIME(6) are UTC; canonicalise as ISO-8601 "Z".
-- 9.  Polymorphic entity_type/entity_id pairs (approvals, committee_
--     decisions, conflict_declarations, ledger_entries) cannot use a real
--     FK; the application must validate the referenced entity.
-- 10. escrow_ciphertext is meaningful only with threshold-controlled
--     decryption (M-of-N). Treated as a Phase-2 enhancement.


-- All tables now exist; re-enable foreign key enforcement.
SET FOREIGN_KEY_CHECKS = 1;
