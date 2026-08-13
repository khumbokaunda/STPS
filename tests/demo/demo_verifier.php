<?php
declare(strict_types=1);

/**
 * demo_verifier.php  --  runnable demonstration of the headline result
 * (build spec section 21 demonstration scenario), using SQLite so it runs with
 * no MySQL server.
 *
 * It builds a small, coherent, correctly hash-chained and Merkle-batched ledger
 * with real ECDSA P-256 signatures (a P-256 keypair here SIMULATES the browser
 * client for the test only; in production the server never holds a private key),
 * then:
 *   1. runs the independent Verifier and shows PASS;
 *   2. simulates a malicious DBA running a direct UPDATE on a revealed bid amount
 *      (fixing the obvious payload_hash too), and shows the Verifier returning
 *      FAIL at the commitment check, because bids.commitment_hash still binds the
 *      originally committed bytes.
 *
 * This exercises the exact production Verifier code path (src/ledger/Verifier.php).
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

// ---- simulated browser client (TEST ONLY) --------------------------------
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
        $details = openssl_pkey_get_details($this->priv);
        $pem = $details['key']; // PEM SPKI public key
        $this->spkiBase64 = str_replace(
            ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\r", "\n"],
            '',
            $pem
        );
    }

    /** Sign a to_sign digest as WebCrypto would (message = digest, hash SHA-256). */
    public function sign(string $toSign): string
    {
        openssl_sign($toSign, $sig, $this->priv, OPENSSL_ALGO_SHA256);
        return $sig; // DER; Signer::verifyDigest accepts DER or P1363
    }
}

function bindLob(PDOStatement $st, string $name, ?string $val): void
{
    $st->bindValue($name, $val, $val === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
}

// ---- build the SQLite evidence store -------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(file_get_contents(__DIR__ . '/sqlite_schema.sql'));

// Actors and keys.
$bidderUser = Uuid::bin();
$approverUser = Uuid::bin();
$pduUser = Uuid::bin();
$bidderOrg = Uuid::bin();

$bidderClient = new DemoClient();
$approverClient = new DemoClient();

$bidderKeyId = Uuid::bin();
$approverKeyId = Uuid::bin();

$roleReq = Uuid::bin();      // required role on the approval stage
$stageId = Uuid::bin();

$t0 = new DateTimeImmutable('2026-08-01T09:00:00.000Z', new DateTimeZone('UTC'));
function mysqlAt(DateTimeImmutable $t): string { return Clock::mysql($t); }

// crypto_keys
$k = $pdo->prepare('INSERT INTO crypto_keys (key_id,user_id,algorithm,public_key,key_fingerprint,status,created_at) VALUES (:id,:u,:a,:p,:f,:s,:c)');
foreach ([[$bidderKeyId,$bidderUser,$bidderClient],[$approverKeyId,$approverUser,$approverClient]] as [$kid,$uid,$cli]) {
    bindLob($k, ':id', $kid); bindLob($k, ':u', $uid);
    $k->bindValue(':a', 'ECDSA-P256'); $k->bindValue(':p', $cli->spkiBase64);
    bindLob($k, ':f', hash('sha256', $cli->spkiBase64, true));
    $k->bindValue(':s', 'active'); $k->bindValue(':c', mysqlAt($t0));
    $k->execute();
}

// roles / user_roles / workflow_stages so the authorization check passes.
$rr = $pdo->prepare('INSERT INTO roles (role_id,name) VALUES (:i,:n)');
bindLob($rr, ':i', $roleReq); $rr->bindValue(':n', 'IPDCMember'); $rr->execute();
$ur = $pdo->prepare('INSERT INTO user_roles (user_role_id,user_id,role_id,valid_from,valid_until,status) VALUES (:i,:u,:r,:f,NULL,:s)');
bindLob($ur, ':i', Uuid::bin()); bindLob($ur, ':u', $approverUser); bindLob($ur, ':r', $roleReq);
$ur->bindValue(':f', mysqlAt($t0)); $ur->bindValue(':s', 'active'); $ur->execute();
$ws = $pdo->prepare('INSERT INTO workflow_stages (stage_id,required_role,required_committee,min_approvers) VALUES (:i,:r,NULL,1)');
bindLob($ws, ':i', $stageId); bindLob($ws, ':r', $roleReq); $ws->execute();

// RFQ with a timing window.
$rfqId = Uuid::bin();
$bidDeadline = new DateTimeImmutable('2026-08-05T12:00:00.000Z', new DateTimeZone('UTC'));
$revealStart = new DateTimeImmutable('2026-08-05T12:00:00.000Z', new DateTimeZone('UTC'));
$revealDeadline = new DateTimeImmutable('2026-08-06T12:00:00.000Z', new DateTimeZone('UTC'));
$rf = $pdo->prepare('INSERT INTO rfqs (rfq_id,bid_deadline,reveal_start,reveal_deadline,status) VALUES (:i,:bd,:rs,:rd,:st)');
bindLob($rf, ':i', $rfqId);
$rf->bindValue(':bd', mysqlAt($bidDeadline));
$rf->bindValue(':rs', mysqlAt($revealStart));
$rf->bindValue(':rd', mysqlAt($revealDeadline));
$rf->bindValue(':st', 'revealed');
$rf->execute();

// A sealed bid: commit before deadline, reveal in window.
$bidId = Uuid::bin();
$committedAt = new DateTimeImmutable('2026-08-04T10:00:00.000Z', new DateTimeZone('UTC'));
$revealedAt = new DateTimeImmutable('2026-08-05T13:00:00.000Z', new DateTimeZone('UTC'));

$bidPayload = Canonicalizer::encode((object) [
    'schema'   => DomainSeparators::BID_COMMITMENT,
    'amount'   => Canonicalizer::decimalString('98000000', 2),
    'currency' => 'MWK',
    'rfq_id'   => Canonicalizer::uuidToHex($rfqId),
]);
$nonce = random_bytes(32);
$commitmentC = Commitment::compute($rfqId, $bidderOrg, $bidPayload, $nonce);

$b = $pdo->prepare('INSERT INTO bids (bid_id,rfq_id,bidder_id,commitment_hash,status,committed_at) VALUES (:i,:r,:bo,:c,:s,:t)');
bindLob($b, ':i', $bidId); bindLob($b, ':r', $rfqId); bindLob($b, ':bo', $bidderOrg);
bindLob($b, ':c', $commitmentC); $b->bindValue(':s', 'revealed'); $b->bindValue(':t', mysqlAt($committedAt));
$b->execute();

// Reveal record: real signature over the canonical bid (BID_REVEAL domain).
$revealSig = $bidderClient->sign(Hasher::toSign(DomainSeparators::BID_REVEAL, $bidPayload));
$rv = $pdo->prepare('INSERT INTO bid_reveals (reveal_id,bid_id,canonical_payload,nonce,payload_hash,signature,key_id,schema_version,revealed_at) VALUES (:i,:b,:p,:n,:h,:s,:k,:sv,:t)');
bindLob($rv, ':i', Uuid::bin()); bindLob($rv, ':b', $bidId);
bindLob($rv, ':p', $bidPayload); bindLob($rv, ':n', $nonce);
bindLob($rv, ':h', Hasher::sha256($bidPayload)); bindLob($rv, ':s', $revealSig);
bindLob($rv, ':k', $bidderKeyId); $rv->bindValue(':sv', DomainSeparators::BID_REVEAL);
$rv->bindValue(':t', mysqlAt($revealedAt)); $rv->execute();

// A signed award approval (real signature over an APPROVAL canonical payload).
$awardId = Uuid::bin();
$decidedAt = new DateTimeImmutable('2026-08-07T09:00:00.000Z', new DateTimeZone('UTC'));
$approvalCanonical = Canonicalizer::encode((object) [
    'schema'    => DomainSeparators::APPROVAL,
    'entity'    => 'award',
    'entity_id' => Canonicalizer::uuidToHex($awardId),
    'decision'  => 'approved',
    'decided_at' => Clock::iso($decidedAt),
]);
$approvalSig = $approverClient->sign(Hasher::toSign(DomainSeparators::APPROVAL, $approvalCanonical));
$ap = $pdo->prepare('INSERT INTO approvals (approval_id,workflow_stage_id,committee_decision_id,entity_type,entity_id,approver_id,key_id,decision,reason,signed_payload_hash,signature,canonical_payload,schema_version,decided_at) VALUES (:i,:ws,NULL,:et,:eid,:ap,:k,:d,NULL,:sph,:sig,:cp,:sv,:t)');
bindLob($ap, ':i', Uuid::bin()); bindLob($ap, ':ws', $stageId);
$ap->bindValue(':et', 'award'); bindLob($ap, ':eid', $awardId);
bindLob($ap, ':ap', $approverUser); bindLob($ap, ':k', $approverKeyId);
$ap->bindValue(':d', 'approved'); bindLob($ap, ':sph', Hasher::sha256($approvalCanonical));
bindLob($ap, ':sig', $approvalSig); bindLob($ap, ':cp', $approvalCanonical);
$ap->bindValue(':sv', DomainSeparators::APPROVAL); $ap->bindValue(':t', mysqlAt($decidedAt));
$ap->execute();

// ---- build the hash-chained ledger over these events ---------------------
$events = [
    ['actor' => $pduUser,    'action' => 'RFQ_PUBLISHED',  'etype' => 'rfq',   'eid' => $rfqId,   'sig' => null,          'key' => null,          'fields' => ['bid_deadline' => Clock::iso($bidDeadline)], 'at' => $t0],
    ['actor' => $bidderUser, 'action' => 'BID_COMMITTED',  'etype' => 'bid',   'eid' => $bidId,   'sig' => null,          'key' => $bidderKeyId,  'fields' => ['commitment_hash' => bin2hex($commitmentC)], 'at' => $committedAt],
    ['actor' => $bidderUser, 'action' => 'BID_REVEALED',   'etype' => 'bid',   'eid' => $bidId,   'sig' => $revealSig,    'key' => $bidderKeyId,  'fields' => ['payload_hash' => bin2hex(Hasher::sha256($bidPayload))], 'at' => $revealedAt],
    ['actor' => $approverUser,'action' => 'APPROVAL_RECORDED','etype' => 'award','eid' => $awardId,'sig' => $approvalSig,  'key' => $approverKeyId, 'fields' => ['decision' => 'approved'], 'at' => $decidedAt],
];

$prev = null;
$seq = 1;
$leaves = [];
$ins = $pdo->prepare('INSERT INTO ledger_entries (sequence_no,actor_id,action,entity_type,entity_id,canonical_payload,payload_hash,prev_entry_hash,entry_hash,actor_signature,key_id,schema_version,created_at) VALUES (:seq,:a,:ac,:et,:eid,:cp,:ph,:prev,:eh,:sig,:k,:sv,:c)');
foreach ($events as $e) {
    $mysql = mysqlAt($e['at']);
    $iso = Clock::iso(Clock::fromMysql($mysql));
    $payload = Canonicalizer::encode(array_merge(
        ['schema' => DomainSeparators::LEDGER],
        ['action' => $e['action'], 'entity_type' => $e['etype'],
         'entity_id' => Canonicalizer::uuidToHex($e['eid']),
         'actor_id' => Canonicalizer::uuidToHex($e['actor']), 'created_at' => $iso],
        $e['fields']
    ));
    $ph = Hasher::sha256($payload);
    $eh = LedgerHash::compute($seq, $prev, $e['actor'], $e['action'], $e['etype'], $e['eid'], $ph, $iso, DomainSeparators::LEDGER);
    $ins->bindValue(':seq', $seq, PDO::PARAM_INT);
    bindLob($ins, ':a', $e['actor']); $ins->bindValue(':ac', $e['action']);
    $ins->bindValue(':et', $e['etype']); bindLob($ins, ':eid', $e['eid']);
    bindLob($ins, ':cp', $payload); bindLob($ins, ':ph', $ph);
    bindLob($ins, ':prev', $prev); bindLob($ins, ':eh', $eh);
    bindLob($ins, ':sig', $e['sig']); bindLob($ins, ':k', $e['key']);
    $ins->bindValue(':sv', DomainSeparators::LEDGER); $ins->bindValue(':c', $mysql);
    $ins->execute();
    $leaves[] = $eh;
    $prev = $eh;
    $seq++;
}

// One Merkle batch over all entries (no external TSA token in this offline demo;
// the Verifier notes that RFC 3161 tokens were not checked).
$root = MerkleTree::root($leaves);
$batchId = Uuid::bin();
$bt = $pdo->prepare('INSERT INTO ledger_batches (batch_id,from_sequence,to_sequence,merkle_root,created_at,status) VALUES (:i,1,:to,:r,:c,:s)');
bindLob($bt, ':i', $batchId); $bt->bindValue(':to', count($leaves), PDO::PARAM_INT);
bindLob($bt, ':r', $root); $bt->bindValue(':c', mysqlAt($decidedAt)); $bt->bindValue(':s', 'anchored');
$bt->execute();

// ==========================================================================
echo "STEP 1  --  clean procurement, run the independent verifier:\n\n";
$result = (new Verifier($pdo))->run();
foreach ($result->notes as $n) { echo "  note: {$n}\n"; }
echo "\n  VERDICT: " . ($result->pass ? "PASS" : "FAIL") . "\n";
if (!$result->pass) {
    echo "  UNEXPECTED failure: " . json_encode($result->failures[0]) . "\n";
    exit(2);
}

echo "\n----------------------------------------------------------------------\n";
echo "STEP 2  --  a malicious DBA runs a direct UPDATE on the revealed amount\n";
echo "           (and fixes the obvious payload_hash), then we re-verify:\n\n";

$tampered = Canonicalizer::encode((object) [
    'schema'   => DomainSeparators::BID_COMMITMENT,
    'amount'   => Canonicalizer::decimalString('50000000', 2),  // lowered from 98,000,000
    'currency' => 'MWK',
    'rfq_id'   => Canonicalizer::uuidToHex($rfqId),
]);
$upd = $pdo->prepare('UPDATE bid_reveals SET canonical_payload = :cp, payload_hash = :ph WHERE bid_id = :b');
bindLob($upd, ':cp', $tampered); bindLob($upd, ':ph', Hasher::sha256($tampered)); bindLob($upd, ':b', $bidId);
$upd->execute();

$result2 = (new Verifier($pdo))->run();
echo "  VERDICT: " . ($result2->pass ? "PASS" : "FAIL") . "\n";
if ($result2->pass) {
    echo "  UNEXPECTED: tampering was not detected!\n";
    exit(2);
}
$f = $result2->failures[0];
echo "  First detected break:\n";
echo "    check:  {$f['check']}\n";
echo "    entity: " . ($f['entity'] ?? '(n/a)') . "\n";
echo "    detail: {$f['detail']}\n";

echo "\nDemonstration complete: a clean run verifies PASS; a direct DBA edit of a\n";
echo "revealed bid FAILs verification. It is caught first at the reveal signature\n";
echo "(the DBA cannot forge the bidder's private key over the altered bytes), and\n";
echo "the commitment check would catch it too, since bids.commitment_hash still\n";
echo "binds the bytes committed and anchored before the deadline. Tamper-evident.\n";
exit(0);
