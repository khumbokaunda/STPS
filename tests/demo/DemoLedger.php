<?php
declare(strict_types=1);

/**
 * DemoLedger  --  builds a small, coherent, correctly hash-chained and
 * Merkle-batched evidence store in SQLite, with real ECDSA P-256 signatures, so
 * the production Verifier (src/ledger/Verifier.php) can be exercised offline.
 *
 * Used by tests/demo/demo_verifier.php (headline PASS/FAIL demonstration) and
 * tests/adversarial/test_adversarial_sqlite.php (each attack must be detected).
 *
 * The P-256 keypairs here SIMULATE the browser client for tests only; in
 * production the server never holds a private key.
 */

require_once __DIR__ . '/../../src/db/Uuid.php';
require_once __DIR__ . '/../../src/db/Clock.php';
require_once __DIR__ . '/../../src/crypto/Canonicalizer.php';
require_once __DIR__ . '/../../src/crypto/Hasher.php';
require_once __DIR__ . '/../../src/crypto/Commitment.php';
require_once __DIR__ . '/../../src/crypto/MerkleTree.php';
require_once __DIR__ . '/../../src/crypto/DomainSeparators.php';
require_once __DIR__ . '/../../src/ledger/LedgerHash.php';
require_once __DIR__ . '/../../src/ledger/Verifier.php';

final class DemoClient
{
    public $priv;
    public string $spkiBase64;

    public function __construct()
    {
        $this->priv = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $pem = openssl_pkey_get_details($this->priv)['key'];
        $this->spkiBase64 = str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"],
            '',
            $pem
        );
    }

    public function sign(string $toSign): string
    {
        openssl_sign($toSign, $sig, $this->priv, OPENSSL_ALGO_SHA256);
        return $sig;
    }
}

final class DemoLedger
{
    /** @var array<string,string> raw-byte id handles for tests to reference */
    public array $ids = [];
    public PDO $pdo;

    public static function bindLob(PDOStatement $st, string $name, ?string $val): void
    {
        $st->bindValue($name, $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    }

    /** Build the full clean dataset and return the instance (PASS-verifiable). */
    public static function build(): self
    {
        $self = new self();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(file_get_contents(__DIR__ . '/sqlite_schema.sql'));
        $self->pdo = $pdo;

        $bidderUser = Uuid::bin(); $approverUser = Uuid::bin(); $pduUser = Uuid::bin();
        $bidderOrg = Uuid::bin();
        $bidderClient = new DemoClient(); $approverClient = new DemoClient();
        $bidderKeyId = Uuid::bin(); $approverKeyId = Uuid::bin();
        $roleReq = Uuid::bin(); $stageId = Uuid::bin();
        $t0 = new DateTimeImmutable('2026-08-01T09:00:00.000Z', new DateTimeZone('UTC'));

        $k = $pdo->prepare('INSERT INTO crypto_keys (key_id,user_id,algorithm,public_key,key_fingerprint,status,created_at) VALUES (:id,:u,:a,:p,:f,:s,:c)');
        foreach ([[$bidderKeyId,$bidderUser,$bidderClient],[$approverKeyId,$approverUser,$approverClient]] as [$kid,$uid,$cli]) {
            self::bindLob($k, ':id', $kid); self::bindLob($k, ':u', $uid);
            $k->bindValue(':a', 'ECDSA-P256'); $k->bindValue(':p', $cli->spkiBase64);
            self::bindLob($k, ':f', hash('sha256', $cli->spkiBase64, true));
            $k->bindValue(':s', 'active'); $k->bindValue(':c', Clock::mysql($t0)); $k->execute();
        }

        $rr = $pdo->prepare('INSERT INTO roles (role_id,name) VALUES (:i,:n)');
        self::bindLob($rr, ':i', $roleReq); $rr->bindValue(':n', 'IPDCMember'); $rr->execute();
        $ur = $pdo->prepare('INSERT INTO user_roles (user_role_id,user_id,role_id,valid_from,valid_until,status) VALUES (:i,:u,:r,:f,NULL,:s)');
        self::bindLob($ur, ':i', Uuid::bin()); self::bindLob($ur, ':u', $approverUser); self::bindLob($ur, ':r', $roleReq);
        $ur->bindValue(':f', Clock::mysql($t0)); $ur->bindValue(':s', 'active'); $ur->execute();
        $ws = $pdo->prepare('INSERT INTO workflow_stages (stage_id,required_role,required_committee,min_approvers) VALUES (:i,:r,NULL,1)');
        self::bindLob($ws, ':i', $stageId); self::bindLob($ws, ':r', $roleReq); $ws->execute();

        $rfqId = Uuid::bin();
        $bidDeadline = new DateTimeImmutable('2026-08-05T12:00:00.000Z', new DateTimeZone('UTC'));
        $revealStart = $bidDeadline;
        $revealDeadline = new DateTimeImmutable('2026-08-06T12:00:00.000Z', new DateTimeZone('UTC'));
        $rf = $pdo->prepare('INSERT INTO rfqs (rfq_id,bid_deadline,reveal_start,reveal_deadline,status) VALUES (:i,:bd,:rs,:rd,:st)');
        self::bindLob($rf, ':i', $rfqId);
        $rf->bindValue(':bd', Clock::mysql($bidDeadline));
        $rf->bindValue(':rs', Clock::mysql($revealStart));
        $rf->bindValue(':rd', Clock::mysql($revealDeadline));
        $rf->bindValue(':st', 'revealed'); $rf->execute();

        $bidId = Uuid::bin();
        $committedAt = new DateTimeImmutable('2026-08-04T10:00:00.000Z', new DateTimeZone('UTC'));
        $revealedAt = new DateTimeImmutable('2026-08-05T13:00:00.000Z', new DateTimeZone('UTC'));
        $bidPayload = Canonicalizer::encode((object) [
            'schema' => DomainSeparators::BID_COMMITMENT,
            'amount' => Canonicalizer::decimalString('98000000', 2),
            'currency' => 'MWK',
            'rfq_id' => Canonicalizer::uuidToHex($rfqId),
        ]);
        $nonce = random_bytes(32);
        $commitmentC = Commitment::compute($rfqId, $bidderOrg, $bidPayload, $nonce);
        $b = $pdo->prepare('INSERT INTO bids (bid_id,rfq_id,bidder_id,commitment_hash,status,committed_at) VALUES (:i,:r,:bo,:c,:s,:t)');
        self::bindLob($b, ':i', $bidId); self::bindLob($b, ':r', $rfqId); self::bindLob($b, ':bo', $bidderOrg);
        self::bindLob($b, ':c', $commitmentC); $b->bindValue(':s', 'revealed'); $b->bindValue(':t', Clock::mysql($committedAt)); $b->execute();

        $revealSig = $bidderClient->sign(Hasher::toSign(DomainSeparators::BID_REVEAL, $bidPayload));
        $rv = $pdo->prepare('INSERT INTO bid_reveals (reveal_id,bid_id,canonical_payload,nonce,payload_hash,signature,key_id,schema_version,revealed_at) VALUES (:i,:b,:p,:n,:h,:s,:k,:sv,:t)');
        self::bindLob($rv, ':i', Uuid::bin()); self::bindLob($rv, ':b', $bidId);
        self::bindLob($rv, ':p', $bidPayload); self::bindLob($rv, ':n', $nonce);
        self::bindLob($rv, ':h', Hasher::sha256($bidPayload)); self::bindLob($rv, ':s', $revealSig);
        self::bindLob($rv, ':k', $bidderKeyId); $rv->bindValue(':sv', DomainSeparators::BID_REVEAL);
        $rv->bindValue(':t', Clock::mysql($revealedAt)); $rv->execute();

        $awardId = Uuid::bin();
        $decidedAt = new DateTimeImmutable('2026-08-07T09:00:00.000Z', new DateTimeZone('UTC'));
        $approvalCanonical = Canonicalizer::encode((object) [
            'schema' => DomainSeparators::APPROVAL,
            'entity' => 'award',
            'entity_id' => Canonicalizer::uuidToHex($awardId),
            'decision' => 'approved',
            'decided_at' => Clock::iso($decidedAt),
        ]);
        $approvalSig = $approverClient->sign(Hasher::toSign(DomainSeparators::APPROVAL, $approvalCanonical));
        $ap = $pdo->prepare('INSERT INTO approvals (approval_id,workflow_stage_id,committee_decision_id,entity_type,entity_id,approver_id,key_id,decision,reason,signed_payload_hash,signature,canonical_payload,schema_version,decided_at) VALUES (:i,:ws,NULL,:et,:eid,:ap,:k,:d,NULL,:sph,:sig,:cp,:sv,:t)');
        self::bindLob($ap, ':i', Uuid::bin()); self::bindLob($ap, ':ws', $stageId);
        $ap->bindValue(':et', 'award'); self::bindLob($ap, ':eid', $awardId);
        self::bindLob($ap, ':ap', $approverUser); self::bindLob($ap, ':k', $approverKeyId);
        $ap->bindValue(':d', 'approved'); self::bindLob($ap, ':sph', Hasher::sha256($approvalCanonical));
        self::bindLob($ap, ':sig', $approvalSig); self::bindLob($ap, ':cp', $approvalCanonical);
        $ap->bindValue(':sv', DomainSeparators::APPROVAL); $ap->bindValue(':t', Clock::mysql($decidedAt)); $ap->execute();

        $events = [
            ['actor'=>$pduUser,'action'=>'RFQ_PUBLISHED','etype'=>'rfq','eid'=>$rfqId,'sig'=>null,'key'=>null,'fields'=>['bid_deadline'=>Clock::iso($bidDeadline)],'at'=>$t0],
            ['actor'=>$bidderUser,'action'=>'BID_COMMITTED','etype'=>'bid','eid'=>$bidId,'sig'=>null,'key'=>$bidderKeyId,'fields'=>['commitment_hash'=>bin2hex($commitmentC)],'at'=>$committedAt],
            ['actor'=>$bidderUser,'action'=>'BID_REVEALED','etype'=>'bid','eid'=>$bidId,'sig'=>$revealSig,'key'=>$bidderKeyId,'fields'=>['payload_hash'=>bin2hex(Hasher::sha256($bidPayload))],'at'=>$revealedAt],
            ['actor'=>$approverUser,'action'=>'APPROVAL_RECORDED','etype'=>'award','eid'=>$awardId,'sig'=>$approvalSig,'key'=>$approverKeyId,'fields'=>['decision'=>'approved'],'at'=>$decidedAt],
        ];
        $prev = null; $seq = 1; $leaves = [];
        $ins = $pdo->prepare('INSERT INTO ledger_entries (sequence_no,actor_id,action,entity_type,entity_id,canonical_payload,payload_hash,prev_entry_hash,entry_hash,actor_signature,key_id,schema_version,created_at) VALUES (:seq,:a,:ac,:et,:eid,:cp,:ph,:prev,:eh,:sig,:k,:sv,:c)');
        foreach ($events as $e) {
            $mysql = Clock::mysql($e['at']);
            $iso = Clock::iso(Clock::fromMysql($mysql));
            $payload = Canonicalizer::encode(array_merge(
                ['schema'=>DomainSeparators::LEDGER],
                ['action'=>$e['action'],'entity_type'=>$e['etype'],'entity_id'=>Canonicalizer::uuidToHex($e['eid']),'actor_id'=>Canonicalizer::uuidToHex($e['actor']),'created_at'=>$iso],
                $e['fields']
            ));
            $ph = Hasher::sha256($payload);
            $eh = LedgerHash::compute($seq, $prev, $e['actor'], $e['action'], $e['etype'], $e['eid'], $ph, $iso, DomainSeparators::LEDGER);
            $ins->bindValue(':seq', $seq, PDO::PARAM_INT);
            self::bindLob($ins, ':a', $e['actor']); $ins->bindValue(':ac', $e['action']);
            $ins->bindValue(':et', $e['etype']); self::bindLob($ins, ':eid', $e['eid']);
            self::bindLob($ins, ':cp', $payload); self::bindLob($ins, ':ph', $ph);
            self::bindLob($ins, ':prev', $prev); self::bindLob($ins, ':eh', $eh);
            self::bindLob($ins, ':sig', $e['sig']); self::bindLob($ins, ':k', $e['key']);
            $ins->bindValue(':sv', DomainSeparators::LEDGER); $ins->bindValue(':c', $mysql);
            $ins->execute();
            $leaves[] = $eh; $prev = $eh; $seq++;
        }
        $root = MerkleTree::root($leaves);
        $batchId = Uuid::bin();
        $bt = $pdo->prepare('INSERT INTO ledger_batches (batch_id,from_sequence,to_sequence,merkle_root,created_at,status) VALUES (:i,1,:to,:r,:c,:s)');
        self::bindLob($bt, ':i', $batchId); $bt->bindValue(':to', count($leaves), PDO::PARAM_INT);
        self::bindLob($bt, ':r', $root); $bt->bindValue(':c', Clock::mysql($decidedAt)); $bt->bindValue(':s', 'anchored'); $bt->execute();

        $self->ids = [
            'bid' => $bidId, 'rfq' => $rfqId, 'award' => $awardId, 'bidderOrg' => $bidderOrg,
            'bidPayload' => $bidPayload, 'nonce' => $nonce, 'commitmentC' => $commitmentC,
            'approverUser' => $approverUser, 'roleReq' => $roleReq,
        ];
        return $self;
    }
}
