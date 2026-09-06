<?php

/**
 * Bounded CP response body accumulator for streaming cURL writes (AUD-008-F01).
 *
 * Caps memory during receive: never appends past MAX_RESPONSE_BYTES.
 * Returning 0 from write() aborts the cURL transfer intentionally.
 */
final class MtUniCreditCpBoundedResponseBuffer
{
    /** @var int */
    private $maxBytes;

    /** @var string */
    private $body = '';

    /** @var bool */
    private $abortedForSize = false;

    /**
     * @param int|null $maxBytes
     */
    public function __construct($maxBytes = null)
    {
        $this->maxBytes = $maxBytes !== null
            ? (int) $maxBytes
            : MtUniCreditCpHttpConstants::MAX_RESPONSE_BYTES;
        if ($this->maxBytes < 0) {
            throw new InvalidArgumentException('Response size limit must not be negative.');
        }
    }

    /**
     * cURL WRITEFUNCTION-compatible writer.
     *
     * @param string $chunk
     * @return int bytes accepted, or 0 to abort transfer
     */
    public function write($chunk)
    {
        if ($this->abortedForSize) {
            return 0;
        }

        $chunk = (string) $chunk;
        $chunkLen = strlen($chunk);
        if ($chunkLen === 0) {
            return 0;
        }

        if ((strlen($this->body) + $chunkLen) > $this->maxBytes) {
            $this->abortedForSize = true;

            return 0;
        }

        $this->body .= $chunk;

        return $chunkLen;
    }

    /**
     * @return bool
     */
    public function isAbortedForSize()
    {
        return $this->abortedForSize;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @return int
     */
    public function getMaxBytes()
    {
        return $this->maxBytes;
    }

    /**
     * @return int
     */
    public function getLength()
    {
        return strlen($this->body);
    }
}
