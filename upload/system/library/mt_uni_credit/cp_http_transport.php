<?php

interface MtUniCreditCpHttpTransport
{
    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return MtUniCreditCpHttpResponse
     */
    public function request($method, $url, array $headers, $payload);
}
