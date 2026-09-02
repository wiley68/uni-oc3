<?php

/** Central Control Panel HTTP transport defaults (Phase 4). */
final class MtUniCreditCpHttpConstants
{
    const CONNECT_TIMEOUT_SECONDS = 5;

    const TOTAL_TIMEOUT_SECONDS = 15;

    /** Maximum response body size accepted from CP (1 MiB). */
    const MAX_RESPONSE_BYTES = 1048576;

    const REFRESH_MARGIN_SECONDS = 60;
}
