-- ============================================================
-- Migration 002  --  database privilege split (build spec section 18)
-- ============================================================
-- The privilege split is the PRIMARY control at the database layer; the
-- append-only triggers are defense in depth. Neither replaces external
-- anchoring. Apply this with an administrative account after 001.
--
-- Replace the passwords below with values supplied out of band (never commit
-- real secrets). Adjust host ('%' / 'localhost') to your deployment.
--
--   proc_migrate : full DDL, used ONLY to apply schema + migrations.
--   proc_app     : runtime. SELECT/INSERT/UPDATE/DELETE on mutable business
--                  tables, but only SELECT/INSERT on the ledger and signed
--                  evidence tables (no UPDATE/DELETE).
--   proc_verify  : read-only (SELECT), used by the independent verifier.

-- ---- accounts (set real passwords out of band) ----
CREATE USER IF NOT EXISTS 'proc_migrate'@'%' IDENTIFIED BY 'CHANGE_ME_migrate';
CREATE USER IF NOT EXISTS 'proc_app'@'%'     IDENTIFIED BY 'CHANGE_ME_app';
CREATE USER IF NOT EXISTS 'proc_verify'@'%'  IDENTIFIED BY 'CHANGE_ME_verify';

-- ---- proc_migrate: full DDL on the schema ----
GRANT ALL PRIVILEGES ON secure_procurement.* TO 'proc_migrate'@'%';

-- ---- proc_verify: read-only ----
GRANT SELECT ON secure_procurement.* TO 'proc_verify'@'%';

-- ---- proc_app: mutable business tables (full DML) ----
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.users TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.departments TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.bidders TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.roles TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.permissions TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.user_roles TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.role_permissions TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.procurement_plans TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.requisitions TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.requisition_items TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.bidding_documents TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.bidding_document_versions TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.rfqs TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.approval_workflows TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.workflow_stages TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.committees TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.committee_members TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.conflict_declarations TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.evaluation_teams TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.evaluation_team_members TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.evaluation_criteria TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.evaluation_reports TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.bids TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.awards TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.contracts TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.purchase_orders TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.deliveries TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.inspections TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.invoices TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.payments TO 'proc_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON secure_procurement.crypto_keys TO 'proc_app'@'%';

-- ---- proc_app: append-only ledger + signed evidence (SELECT + INSERT ONLY) ----
-- No UPDATE / DELETE. This is what stops the runtime account from rewriting
-- history even if the application were subverted.
GRANT SELECT, INSERT ON secure_procurement.ledger_entries TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.ledger_batches TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.merkle_anchors TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.approvals TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.committee_decisions TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.bid_commitments TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.bid_reveals TO 'proc_app'@'%';
GRANT SELECT, INSERT ON secure_procurement.evaluation_scores TO 'proc_app'@'%';

-- Views are read-only.
GRANT SELECT ON secure_procurement.v_ledger_chain TO 'proc_app'@'%';
GRANT SELECT ON secure_procurement.v_active_crypto_keys TO 'proc_app'@'%';
GRANT SELECT ON secure_procurement.v_current_evaluation_scores TO 'proc_app'@'%';

FLUSH PRIVILEGES;

-- Note on merkle_anchors: anchoring writes a token and flips a batch status. The
-- batch status flip is on ledger_batches (INSERT only here), so anchoring records
-- status via a fresh row model or an administrative anchoring account. In this
-- prototype bin/anchor.php runs under proc_migrate (batch status UPDATE); the
-- runtime proc_app never needs to update anchoring rows.
