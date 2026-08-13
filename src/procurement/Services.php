<?php
declare(strict_types=1);

/**
 * Services  --  a small service container wiring PDO + crypto + auth helpers for
 * the procurement services. Keeps construction in one place (no globals) while
 * staying procedural in spirit.
 */

require_once __DIR__ . '/../db/Db.php';
require_once __DIR__ . '/../db/Uuid.php';
require_once __DIR__ . '/../db/Clock.php';
require_once __DIR__ . '/../crypto/Canonicalizer.php';
require_once __DIR__ . '/../crypto/Hasher.php';
require_once __DIR__ . '/../crypto/Commitment.php';
require_once __DIR__ . '/../crypto/DomainSeparators.php';
require_once __DIR__ . '/../ledger/LedgerWriter.php';
require_once __DIR__ . '/../ledger/LedgerReader.php';
require_once __DIR__ . '/../auth/Rbac.php';
require_once __DIR__ . '/../auth/Authorization.php';
require_once __DIR__ . '/../auth/KeyStore.php';
require_once __DIR__ . '/LedgerActions.php';
require_once __DIR__ . '/SignedEvidence.php';
require_once __DIR__ . '/EntityGuard.php';

final class Services
{
    public PDO $pdo;
    public LedgerWriter $ledger;
    public LedgerReader $ledgerReader;
    public Rbac $rbac;
    public Authorization $authz;
    public KeyStore $keys;
    public SignedEvidence $evidence;
    public EntityGuard $guard;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ledger = new LedgerWriter($pdo);
        $this->ledgerReader = new LedgerReader($pdo);
        $this->rbac = new Rbac($pdo);
        $this->authz = new Authorization($this->rbac);
        $this->keys = new KeyStore($pdo);
        $this->evidence = new SignedEvidence($this->keys);
        $this->guard = new EntityGuard($pdo);
    }

    public static function boot(): self
    {
        return new self(Db::app());
    }
}
