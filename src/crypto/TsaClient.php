<?php
declare(strict_types=1);

/**
 * TsaClient  --  RFC 3161 timestamping against FreeTSA (build spec section 11).
 *
 * The external anchor is the trust root, not the database. The database can be
 * rewritten by a privileged insider; the anchor cannot. This client builds a
 * timestamp query over a Merkle root, POSTs it to the TSA, and returns the DER
 * timestamp token, which is stored in merkle_anchors.timestamp_token.
 *
 * Verification (bin/verify.php and verifyToken()) uses the bundled FreeTSA CA and
 * TSA certificates via the openssl ts command.
 */
final class TsaClient
{
    private string $url;
    private string $caFile;
    private string $tsaCert;
    private int $timeout;

    public function __construct(array $tsaConfig)
    {
        $this->url = $tsaConfig['url'];
        $this->caFile = $tsaConfig['ca_file'];
        $this->tsaCert = $tsaConfig['tsa_cert'];
        $this->timeout = (int) ($tsaConfig['timeout'] ?? 30);
    }

    /**
     * Build an RFC 3161 timestamp query (messageImprint = SHA-256 of the root),
     * POST it, and return the DER timestamp token bytes.
     *
     * @param string $merkleRoot32 raw 32-byte Merkle root
     * @return string DER timestamp response (.tsr) bytes
     */
    public function timestamp(string $merkleRoot32): string
    {
        if (strlen($merkleRoot32) !== 32) {
            throw new InvalidArgumentException('TsaClient: root must be 32 bytes.');
        }
        $tsq = $this->buildQuery($merkleRoot32);
        return $this->postQuery($tsq);
    }

    /** Build a timestamp query (.tsq) DER over the given 32-byte digest. */
    public function buildQuery(string $digest32): string
    {
        $tmpDigest = tempnam(sys_get_temp_dir(), 'tsq_digest_');
        $tmpQuery = tempnam(sys_get_temp_dir(), 'tsq_out_');
        try {
            file_put_contents($tmpDigest, $digest32);
            // -digest expects hex; use the raw file via -data would hash the file.
            // We already have the digest, so pass it as hex with -digest.
            $hex = bin2hex($digest32);
            $cmd = sprintf(
                'openssl ts -query -digest %s -sha256 -cert -out %s 2>&1',
                escapeshellarg($hex),
                escapeshellarg($tmpQuery)
            );
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                throw new RuntimeException('openssl ts -query failed: ' . implode("\n", $out));
            }
            return (string) file_get_contents($tmpQuery);
        } finally {
            @unlink($tmpDigest);
            @unlink($tmpQuery);
        }
    }

    /** POST a .tsq to the TSA and return the .tsr token bytes. */
    private function postQuery(string $tsq): string
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $tsq,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/timestamp-query'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            // TLS 1.2+ only; verify the TSA endpoint's certificate.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('TSA request failed: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new RuntimeException("TSA returned HTTP {$code}.");
        }
        return (string) $response;
    }

    /**
     * Verify a stored .tsr token against a root using the bundled CA and TSA
     * certificates. Returns true on a valid timestamp over that exact digest.
     */
    public function verifyToken(string $token, string $merkleRoot32): bool
    {
        $tmpToken = tempnam(sys_get_temp_dir(), 'tsr_');
        try {
            file_put_contents($tmpToken, $token);
            $cmd = sprintf(
                'openssl ts -verify -digest %s -in %s -CAfile %s -untrusted %s 2>&1',
                escapeshellarg(bin2hex($merkleRoot32)),
                escapeshellarg($tmpToken),
                escapeshellarg($this->caFile),
                escapeshellarg($this->tsaCert)
            );
            exec($cmd, $out, $rc);
            $joined = implode("\n", $out);
            return $rc === 0 && str_contains($joined, 'Verification: OK');
        } finally {
            @unlink($tmpToken);
        }
    }

    /** Extract the genTime (UTC) from a .tsr token, or null on failure. */
    public function tokenTime(string $token): ?string
    {
        $tmpToken = tempnam(sys_get_temp_dir(), 'tsr_');
        try {
            file_put_contents($tmpToken, $token);
            $cmd = sprintf('openssl ts -reply -in %s -text 2>&1', escapeshellarg($tmpToken));
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                return null;
            }
            foreach ($out as $line) {
                if (preg_match('/Time stamp:\s*(.+)$/', $line, $m)) {
                    return trim($m[1]);
                }
            }
            return null;
        } finally {
            @unlink($tmpToken);
        }
    }
}
