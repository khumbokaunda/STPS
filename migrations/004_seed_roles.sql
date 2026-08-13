-- ============================================================
-- Migration 004  --  seed the fixed roles (build spec section 14)
-- ============================================================
-- Membership in a committee is a separate fact from holding a role. Role
-- assignment to users is time-bounded (user_roles.valid_from/valid_until) and is
-- done per deployment, not here.

USE secure_procurement;

INSERT INTO roles (role_id, name, description) VALUES
  (UUID_TO_BIN(UUID()), 'Requisitioner',        'Raises and submits requisitions'),
  (UUID_TO_BIN(UUID()), 'HeadOfDepartment',     'Departmental approval authority'),
  (UUID_TO_BIN(UUID()), 'PDUOfficer',           'Procurement and Disposal Unit officer'),
  (UUID_TO_BIN(UUID()), 'EvaluationTeamMember', 'Evaluates bids and signs scores'),
  (UUID_TO_BIN(UUID()), 'IPDCMember',           'Internal Procurement and Disposal Committee member'),
  (UUID_TO_BIN(UUID()), 'ControllingOfficer',   'Constitutes teams and signs contracts'),
  (UUID_TO_BIN(UUID()), 'StoresOfficer',        'Records deliveries and inspections'),
  (UUID_TO_BIN(UUID()), 'FinanceOfficer',       'Submits invoices and records payments'),
  (UUID_TO_BIN(UUID()), 'SystemAdministrator',  'System actor for automated steps'),
  (UUID_TO_BIN(UUID()), 'Bidder',               'External supplier account')
ON DUPLICATE KEY UPDATE description = VALUES(description);
