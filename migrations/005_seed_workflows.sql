-- ============================================================
-- Migration 005  --  seed default approval workflows + stages
-- ============================================================
-- Without at least one approval_workflows row (with matching band and
-- procurement_type) and its workflow_stages, the approvals screen reports
-- "No role stage you can satisfy for this item", because the service cannot
-- find a stage whose required role the approver holds.
--
-- This seeds one default workflow per procurement type, each covering the whole
-- amount band (min 0, no max), with two ordered role stages:
--   stage 1: HeadOfDepartment
--   stage 2: PDUOfficer
-- Adjust bands/roles/committees to your policy; committee stages set
-- required_committee instead of required_role (exactly one, per the schema CHECK).
--
-- Idempotent: it removes any previously-seeded "* default" workflows first
-- (which cascades to their stages) before reinserting.
--
-- Apply as proc_migrate AFTER 004_seed_roles.sql:
--   mysql -u proc_migrate -p secure_procurement < migrations/005_seed_workflows.sql

USE secure_procurement;

DELETE FROM approval_workflows
 WHERE name IN ('GOODS default', 'WORKS default', 'SERVICES default', 'CONSULTING default');

-- Helper role ids (fail loudly if roles are not seeded).
SET @hod = (SELECT role_id FROM roles WHERE name = 'HeadOfDepartment');
SET @pdu = (SELECT role_id FROM roles WHERE name = 'PDUOfficer');

-- --- GOODS ---
SET @wf = UNHEX(REPLACE(UUID(), '-', ''));
INSERT INTO approval_workflows (workflow_id, name, min_amount, max_amount, procurement_type, status, created_at)
  VALUES (@wf, 'GOODS default', 0, NULL, 'GOODS', 'active', NOW(6));
INSERT INTO workflow_stages (stage_id, workflow_id, sequence_no, stage_type, required_role, required_committee, min_approvers, requires_all) VALUES
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 1, 'role', @hod, NULL, 1, FALSE),
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 2, 'role', @pdu, NULL, 1, FALSE);

-- --- WORKS ---
SET @wf = UNHEX(REPLACE(UUID(), '-', ''));
INSERT INTO approval_workflows (workflow_id, name, min_amount, max_amount, procurement_type, status, created_at)
  VALUES (@wf, 'WORKS default', 0, NULL, 'WORKS', 'active', NOW(6));
INSERT INTO workflow_stages (stage_id, workflow_id, sequence_no, stage_type, required_role, required_committee, min_approvers, requires_all) VALUES
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 1, 'role', @hod, NULL, 1, FALSE),
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 2, 'role', @pdu, NULL, 1, FALSE);

-- --- SERVICES ---
SET @wf = UNHEX(REPLACE(UUID(), '-', ''));
INSERT INTO approval_workflows (workflow_id, name, min_amount, max_amount, procurement_type, status, created_at)
  VALUES (@wf, 'SERVICES default', 0, NULL, 'SERVICES', 'active', NOW(6));
INSERT INTO workflow_stages (stage_id, workflow_id, sequence_no, stage_type, required_role, required_committee, min_approvers, requires_all) VALUES
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 1, 'role', @hod, NULL, 1, FALSE),
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 2, 'role', @pdu, NULL, 1, FALSE);

-- --- CONSULTING ---
SET @wf = UNHEX(REPLACE(UUID(), '-', ''));
INSERT INTO approval_workflows (workflow_id, name, min_amount, max_amount, procurement_type, status, created_at)
  VALUES (@wf, 'CONSULTING default', 0, NULL, 'CONSULTING', 'active', NOW(6));
INSERT INTO workflow_stages (stage_id, workflow_id, sequence_no, stage_type, required_role, required_committee, min_approvers, requires_all) VALUES
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 1, 'role', @hod, NULL, 1, FALSE),
  (UNHEX(REPLACE(UUID(), '-', '')), @wf, 2, 'role', @pdu, NULL, 1, FALSE);
