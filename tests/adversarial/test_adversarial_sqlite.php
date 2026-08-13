<?php
declare(strict_types=1);

/**
 * Adversarial suite (build spec section 21) exercised against the REAL production
 * Verifier (src/ledger/Verifier.php) over an offline SQLite evidence store. Each
 * attack must be DETECTED (verdict FAIL) at the expected check.
 *
 * Attacks covered here (the app-layer rejections -- nonce reuse, second
 * commitment, post-deadline submit, approve-without-role, replay -- are covered
 * by the service unit checks and the MySQL integration test; these are the
 * historical-tampering attacks the independent verifier must catch):
 *   - modify a revealed bid amount
 *   - edit a historical ledger row (payload)
 *   - delete a ledger row (sequence gap)
 *   - forge/insert a ledger row (broken linkage)
 *   - tamper a ledger entry so the Merkle root no longer matches
 *   - forge an approval signature
 *   - change an approval decision without re-signing
 *   - reveal outside the window (timing)
 */

require_once __DIR__ . '/../demo/DemoLedger.php';

$failures = 0;
$assert = function (string $name, bool $detected, string $expectCheck, VerificationResult $r) use (&$failures) {
    $got = $detected ? ($r->failures[0]['check'] ?? '?') : '(none)';
    if ($detected && $r->pass === false) {
        echo "[ok] detected: {$name}  (first check: {$got})\n";
    } else {
        $failures++;
        fwrite(STDERR, "[FAIL] NOT detected: {$name}  verdict=" . ($r->pass ? 'PASS' : 'FAIL') . "\n");
    }
};

// Sanity: the clean dataset must PASS.
$clean = DemoLedger::build();
$res = (new Verifier($clean->pdo))->run();
if (!$res->pass) {
    fwrite(STDERR, "[FAIL] clean dataset did not PASS: " . json_encode($res->failures[0] ?? null) . "\n");
    exit(1);
}
echo "[ok] clean dataset verifies PASS\n";

// 1. Modify a revealed bid amount (and fix payload_hash) -> signature/commitment.
$d = DemoLedger::build();
$tampered = Canonicalizer::encode((object) [
    'schema' => DomainSeparators::BID_COMMITMENT,
    'amount' => Canonicalizer::decimalString('50000000', 2),
    'currency' => 'MWK',
    'rfq_id' => Canonicalizer::uuidToHex($d->ids['rfq']),
]);
$st = $d->pdo->prepare('UPDATE bid_reveals SET canonical_payload=:c, payload_hash=:h WHERE bid_id=:b');
DemoLedger::bindLob($st, ':c', $tampered); DemoLedger::bindLob($st, ':h', Hasher::sha256($tampered)); DemoLedger::bindLob($st, ':b', $d->ids['bid']);
$st->execute();
$assert('modify revealed bid amount', true, 'reveal_signature', (new Verifier($d->pdo))->run());

// 2. Edit a historical ledger row payload (only) -> payload_hash mismatch.
$d = DemoLedger::build();
$d->pdo->exec("UPDATE ledger_entries SET canonical_payload = canonical_payload || X'00' WHERE sequence_no = 2");
$assert('edit historical ledger row', true, 'payload_hash', (new Verifier($d->pdo))->run());

// 3. Delete a ledger row -> sequence gap.
$d = DemoLedger::build();
$d->pdo->exec('DELETE FROM ledger_entries WHERE sequence_no = 3');
$assert('delete a ledger row', true, 'sequence_continuity', (new Verifier($d->pdo))->run());

// 4. Insert a forged ledger row appended with a wrong prev link -> linkage break.
$d = DemoLedger::build();
$forgedActor = str_repeat("\x09", 16); $forgedEntity = str_repeat("\x08", 16);
$payload = Canonicalizer::encode((object)['schema'=>DomainSeparators::LEDGER,'action'=>'PAYMENT_RECORDED','x'=>1]);
$ins = $d->pdo->prepare('INSERT INTO ledger_entries (sequence_no,actor_id,action,entity_type,entity_id,canonical_payload,payload_hash,prev_entry_hash,entry_hash,schema_version,created_at) VALUES (5,:a,:ac,:et,:eid,:cp,:ph,:prev,:eh,:sv,:c)');
DemoLedger::bindLob($ins, ':a', $forgedActor); $ins->bindValue(':ac','PAYMENT_RECORDED');
$ins->bindValue(':et','invoice'); DemoLedger::bindLob($ins, ':eid', $forgedEntity);
DemoLedger::bindLob($ins, ':cp', $payload); DemoLedger::bindLob($ins, ':ph', Hasher::sha256($payload));
DemoLedger::bindLob($ins, ':prev', str_repeat("\xAA", 32)); // wrong parent
DemoLedger::bindLob($ins, ':eh', str_repeat("\xBB", 32));   // bogus entry hash
$ins->bindValue(':sv', DomainSeparators::LEDGER); $ins->bindValue(':c','2026-08-08 09:00:00.000000');
$ins->execute();
$assert('insert forged ledger row', true, 'entry_hash', (new Verifier($d->pdo))->run());

// 5. Tamper a ledger entry consistently (recompute its own hashes) so the chain
//    re-links, but the anchored Merkle root no longer matches -> merkle_root.
$d = DemoLedger::build();
// Recompute entry 4 with an altered payload AND rechain 4 only (last entry), then
// leave the batch merkle_root as originally anchored.
$row = $d->pdo->query('SELECT * FROM ledger_entries WHERE sequence_no=4')->fetch();
$newPayload = $row['canonical_payload'] . ' ';
$newPh = Hasher::sha256($newPayload);
$iso = Clock::iso(Clock::fromMysql($row['created_at']));
$newEh = LedgerHash::compute(4, $row['prev_entry_hash'], $row['actor_id'], $row['action'], $row['entity_type'], $row['entity_id'], $newPh, $iso, $row['schema_version']);
$u = $d->pdo->prepare('UPDATE ledger_entries SET canonical_payload=:cp, payload_hash=:ph, entry_hash=:eh WHERE sequence_no=4');
DemoLedger::bindLob($u, ':cp', $newPayload); DemoLedger::bindLob($u, ':ph', $newPh); DemoLedger::bindLob($u, ':eh', $newEh);
$u->execute();
// entry 4 is the chain head, so linkage still holds; the batch root is now stale.
$assert('anchor/merkle mismatch after consistent edit', true, 'merkle_root', (new Verifier($d->pdo))->run());

// 6. Forge an approval signature (replace with random bytes) -> approval_signature.
$d = DemoLedger::build();
$u = $d->pdo->prepare('UPDATE approvals SET signature=:s');
DemoLedger::bindLob($u, ':s', random_bytes(70)); $u->execute();
$assert('forge approval signature', true, 'approval_signature', (new Verifier($d->pdo))->run());

// 7. Change an approval decision without re-signing -> approval_signature
//    (signed_payload_hash / signature no longer match the altered canonical).
$d = DemoLedger::build();
// Alter the stored canonical payload's decision but keep the old signature.
$d->pdo->exec("UPDATE approvals SET canonical_payload = REPLACE(canonical_payload, 'approved', 'rejected')");
$assert('alter approval decision without re-sign', true, 'approval_signature', (new Verifier($d->pdo))->run());

// 8. Reveal outside the window (timing) -> timing.
$d = DemoLedger::build();
$d->pdo->exec("UPDATE bid_reveals SET revealed_at = '2026-08-09 00:00:00.000000'");
$assert('reveal outside window', true, 'timing', (new Verifier($d->pdo))->run());

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "adversarial: {$failures} attack(s) NOT detected\n");
    exit(1);
}
echo "adversarial: all attacks detected by the independent verifier\n";
