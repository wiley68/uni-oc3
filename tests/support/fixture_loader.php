<?php

/**
 * Load JSON contract fixtures from tests/fixtures.
 *
 * @param string $name Filename relative to tests/fixtures
 * @return array
 */
function mtuc_phase0_load_fixture(string $name): array
{
    $path = MTUC_PHASE0_FIXTURES . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        throw new RuntimeException('Missing fixture: ' . $name);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Unreadable fixture: ' . $name);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON fixture: ' . $name . ' (' . json_last_error_msg() . ')');
    }

    return $data;
}

/**
 * @return string
 */
function mtuc_phase0_fixture_sha256(string $name): string
{
    $path = MTUC_PHASE0_FIXTURES . DIRECTORY_SEPARATOR . $name;
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Cannot hash fixture: ' . $name);
    }

    return $hash;
}
