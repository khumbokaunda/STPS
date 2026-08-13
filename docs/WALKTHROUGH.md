# End-to-end walkthrough

This runs the whole procurement lifecycle through the browser UI, then verifies
it. Commands assume XAMPP on Windows; adjust paths for your setup.

## 0. One-time setup

1. Apply the schema and migrations (see `migrations/README.md`). On MariaDB/XAMPP
   use the `_mariadb` schema file. Run `002_privileges.sql` as **root**; run
   `001`, `003`, `004` as **proc_migrate**.
2. Copy `config/config.example.php` to `config/config.php` and point it at your
   database. For local HTTP dev set `'require_https' => false`.
3. Serve `public/` (Apache vhost with the bundled `.htaccess`, or
   `php -S localhost:4413 public/index.php`).

## 1. Provision a cast of users

Each command creates a user with an Argon2id password and assigns roles:

```
php bin/create_user.php --username req    --email req@x.test    --password "pw-req-123"    --role Requisitioner
php bin/create_user.php --username hod     --email hod@x.test    --password "pw-hod-123"    --role HeadOfDepartment
php bin/create_user.php --username pdu     --email pdu@x.test    --password "pw-pdu-123"    --role PDUOfficer
php bin/create_user.php --username co      --email co@x.test     --password "pw-co-1234"    --role ControllingOfficer
php bin/create_user.php --username evalr   --email evalr@x.test  --password "pw-eval-123"   --role EvaluationTeamMember
php bin/create_user.php --username ipdc1   --email ipdc1@x.test  --password "pw-ipdc-123"   --role IPDCMember
php bin/create_user.php --username stores  --email stores@x.test --password "pw-str-123"    --role StoresOfficer
php bin/create_user.php --username fin     --email fin@x.test     --password "pw-fin-123"   --role FinanceOfficer
php bin/create_user.php --username admin   --email admin@x.test  --password "pw-adm-123"    --role SystemAdministrator
php bin/create_user.php --username acme    --email acme@x.test   --password "pw-bid-123"    --role Bidder --bidder-name "Acme Ltd"
```

Every user, on first sign-in, clicks **Generate & enroll a device key** on the
dashboard. The private key stays in that browser; only the public key is enrolled.
Use separate browsers/profiles per user so each has its own key.

## 2. Walk the lifecycle

1. **admin** signs in, opens **Admin**, and creates a **department** (e.g. "IT",
   code "IT-01"). (Bidder org "Acme Ltd" already exists from step 1.)
2. **req** creates a **requisition** (New requisition), which is signed in the
   browser and ledgered.
3. **hod** (or the workflow's role) opens **Approvals** and signs an approval.
   The requisition moves to `approved`.
4. **pdu** opens **RFQs**, **prepares a draft RFQ** from the approved requisition,
   then **publishes** it (sets bid deadline and reveal window; the timing is
   signed and captured in the ledger and becomes immutable).
5. **acme** (Bidder) opens **Open RFQs** and **commits a sealed bid** (only the
   commitment and its signature are sent; the amount and nonce stay local). After
   the deadline, **pdu** **closes** the RFQ, then **acme** opens **My bids** and
   **reveals** on the same browser. The server recomputes the commitment and
   accepts it only if it matches.
6. **co** opens **Evaluation**, **constitutes a team** (evaluator username `evalr`)
   and **adds a criterion**. **evalr** submits a **signed score** (append-only).
7. **pdu/co** opens **Awards** and **records the award** for the revealed winning
   bid. Optionally **ipdc1** opens a committee decision and casts a signed vote.
8. **co** opens **Contracts** and **signs the contract**.
9. **pdu/co** opens **Execution** and **issues a purchase order**; **stores**
   records a **delivery** and signs an **inspection**.
10. **fin** opens **Finance**, submits an **invoice** and records a **payment**.

Every one of those steps appends exactly one ledger entry inside the same
transaction as the business write.

## 3. Verify (the headline)

Open **Audit** in the UI to run the checks against the live database, or run the
independent CLI with read-only credentials:

```
php bin/verify.php
```

Expect **PASS**. Then, to demonstrate tamper-evidence, have a "malicious DBA"
run a direct edit, e.g.:

```
UPDATE bid_reveals SET canonical_payload = ... ;   -- change a revealed amount
```

Re-run `php bin/verify.php` and it returns **FAIL** with the exact break. The
offline demo `php tests/demo/demo_verifier.php` shows this without a database.

## Notes

- Signed actions need a device key enrolled in that browser. If an action says
  "no signing key on this device", enroll one from the dashboard.
- The workflow advancement in this prototype is intentionally simple (a single
  signed approval advances a requisition). The cryptographic evidence, ledger,
  anchoring, and verifier are the rigorous parts.
