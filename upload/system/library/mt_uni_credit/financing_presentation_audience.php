<?php

/**
 * Presentation audiences for leasing rows / mail.
 */
final class MtUniCreditFinancingPresentationAudience
{
    /** Thank You, customer order email, Process 2 customer leasing mail — never EGN/phone2. */
    const CUSTOMER = 'customer';

    /** Process 2 merchant/admin leasing mail. */
    const ADMIN_EMAIL = 'admin_email';

    /** Admin Order detail panel. */
    const ADMIN_PANEL = 'admin_panel';
}
