-- ============================================================
-- Migration 004  --  seed the fixed roles (build spec section 14)
-- ============================================================
-- Membership in a committee is a separate fact from holding a role. Role
-- assignment to users is time-bounded (user_roles.valid_from/valid_until) and is
-- done per deployment, not here.

USE secure_procurement;

-- UUID bytes are produced with UNHEX(REPLACE(UUID(),'-','')) rather than the
-- MySQL-8-only UUID_TO_BIN(), so this seed runs on both MySQL 8.0+ and MariaDB.
INSERT INTO roles (role_id, name, description) VALUES
  (UNHEX(REPLACE(UUID(),'-','')), 'Requisitioner',        'Raises and submits requisitions'),
  (UNHEX(REPLACE(UUID(),'-','')), 'HeadOfDepartment',     'Departmental approval authority'),
  (UNHEX(REPLACE(UUID(),'-','')), 'PDUOfficer',           'Procurement and Disposal Unit officer'),
  (UNHEX(REPLACE(UUID(),'-','')), 'EvaluationTeamMember', 'Evaluates bids and signs scores'),
  (UNHEX(REPLACE(UUID(),'-','')), 'IPDCMember',           'Internal Procurement and Disposal Committee member'),
  (UNHEX(REPLACE(UUID(),'-','')), 'ControllingOfficer',   'Constitutes teams and signs contracts'),
  (UNHEX(REPLACE(UUID(),'-','')), 'StoresOfficer',        'Records deliveries and inspections'),
  (UNHEX(REPLACE(UUID(),'-','')), 'FinanceOfficer',       'Submits invoices and records payments'),
  (UNHEX(REPLACE(UUID(),'-','')), 'SystemAdministrator',  'System actor for automated steps'),
  (UNHEX(REPLACE(UUID(),'-','')), 'Bidder',               'External supplier account')
ON DUPLICATE KEY UPDATE description = VALUES(description);
