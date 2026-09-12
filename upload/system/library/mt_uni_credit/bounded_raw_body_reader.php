<?php

/**
 * Bounded raw HTTP body reader for signed CP→module requests.
 *
 * Reads at most MAX+1 bytes so oversized bodies are detected without unbounded buffering.
 */
final class MtUniCreditBoundedRawBodyReader
{
    const MAX_INBOUND_BYTES = 1048576;

    /**
     * @param resource $stream
     * @param int $maxBytes
     * @return array{body: string, oversized: bool}
     */
    public static function read($stream, $maxBytes = self::MAX_INBOUND_BYTES)
    {
        $maxBytes = (int) $maxBytes;
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('maxBytes must be non-negative.');
        }

        $limit = $maxBytes + 1;
        $chunks = array();
        $total = 0;

        while (!feof($stream)) {
            $remaining = $limit - $total;
            if ($remaining <= 0) {
                return array('body' => '', 'oversized' => true);
            }

            $chunk = fread($stream, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $chunks[] = $chunk;
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                return array(
                    'body' => '',
                    'oversized' => true,
                );
            }
        }

        return array(
            'body' => implode('', $chunks),
            'oversized' => false,
        );
    }

    /**
     * Read php://input. Content-Length is ignored for oversize decisions — only actual
     * bounded bytes (MAX+1) determine acceptance. Preserve exact raw bytes for HMAC.
     *
     * @param array<string, mixed> $server Unused for authority; kept for call-site compatibility.
     * @param int $maxBytes
     * @return array{body: string, oversized: bool}
     */
    public static function readPhpInput(array $server = array(), $maxBytes = self::MAX_INBOUND_BYTES)
    {
        unset($server);
        $maxBytes = (int) $maxBytes;

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return array('body' => '', 'oversized' => false);
        }

        try {
            return self::read($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
    }
}
