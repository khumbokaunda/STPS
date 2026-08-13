<?php
declare(strict_types=1);

require_once __DIR__ . '/Hasher.php';
require_once __DIR__ . '/DomainSeparators.php';

/**
 * Commitment  --  sealed-bid commit/reveal hash (build spec section 12).
 *
 *   C = SHA-256( "PROCUREMENT-BID-COMMITMENT-V1" 0x1F rfq_id(16) 0x1F
 *                bidder_id(16) 0x1F canonical_bid 0x1F nonce )
 *
 * The nonce and plaintext bid are NEVER stored at commitment time (invariant 1).
 * At reveal the server recomputes C with the identical construction and requires
 * it to equal bids.commitment_hash. The verifier does the same independently.
 */
final class Commitment
{
    /**
     * @param string $rfqId16    16 raw bytes
     * @param string $bidderId16 16 raw bytes
     * @param string $canonicalBid canonical JSON bytes of the bid
     * @param string $nonce       32 raw random bytes
     * @return string 32-byte commitment C
     */
    public static function compute(
        string $rfqId16,
        string $bidderId16,
        string $canonicalBid,
        string $nonce
    ): string {
        if (strlen($rfqId16) !== 16 || strlen($bidderId16) !== 16) {
            throw new InvalidArgumentException('Commitment: ids must be 16 bytes.');
        }
        return Hasher::sha256Fields([
            DomainSeparators::BID_COMMITMENT,
            $rfqId16,
            $bidderId16,
            $canonicalBid,
            $nonce,
        ]);
    }

    /**
     * The digest a bidder signs at commit time (build spec section 12 step 4):
     *   sigC over SHA-256("PROCUREMENT-BID-COMMITMENT-V1" 0x1F C).
     */
    public static function commitSigningDigest(string $commitmentC): string
    {
        return Hasher::sha256(DomainSeparators::BID_COMMITMENT . Hasher::US . $commitmentC);
    }
}
