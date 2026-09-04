<?php

/**
 * Presentation audiences for leasing rows / mail.
 */
final class MtUniCreditFinancingPresentationAudience
{
    /** Thank You, customer order email, Process 2 customer leasing mail — never EGN/phone2. */
    const CUSTOMER = 'customer';

    /** Process 2 merchant/admin leasing mail — EGN + phone2 allowed. */
    const ADMIN_EMAIL = 'admin_email';

    /** Admin Order detail panel — EGN + phone2 for Process 2. */
    const ADMIN_PANEL = 'admin_panel';
}
