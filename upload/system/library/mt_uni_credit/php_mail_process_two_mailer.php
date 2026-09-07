<?php

/**
 * Process 2 mail via PHP mail() — no EGN in customer audience.
 */
final class MtUniCreditPhpMailProcessTwoMailer implements MtUniCreditProcessTwoMailPort
{
    /** @var MtUniCreditProcessTwoLeasingMailPresenter */
    private $presenter;

    /**
     * @param MtUniCreditProcessTwoLeasingMailPresenter|null $presenter
     */
    public function __construct($presenter = null)
    {
        $this->presenter = $presenter instanceof MtUniCreditProcessTwoLeasingMailPresenter
            ? $presenter
            : new MtUniCreditProcessTwoLeasingMailPresenter();
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @return array<int, array{audience: string, email: string, recipient_key: string}>
     */
    public function resolveProcess2Recipients(array $shop, array $orderContext)
    {
        $from = trim((string) (isset($orderContext['store_email']) ? $orderContext['store_email'] : ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }
        $adminEmails = $this->parseAdminEmails($shop, $from);
        $customerEmail = trim((string) (isset($orderContext['customer_email']) ? $orderContext['customer_email'] : ''));
        $recipients = array();
        foreach ($adminEmails as $email) {
            $recipients[] = array(
                'audience' => 'admin',
                'email' => $email,
                'recipient_key' => MtUniCreditProcessTwoMailRecipientRepository::normalizeRecipientKey($email),
            );
        }
        if (
            $customerEmail !== ''
            && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)
            && !in_array(strtolower($customerEmail), array_map('strtolower', $adminEmails), true)
        ) {
            $recipients[] = array(
                'audience' => 'customer',
                'email' => $customerEmail,
                'recipient_key' => MtUniCreditProcessTwoMailRecipientRepository::normalizeRecipientKey($customerEmail),
            );
        }

        return $recipients;
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @param string $audience
     * @param string $email
     * @return bool
     */
    public function sendProcess2Recipient(array $shop, array $orderContext, $sensitive, $audience, $email)
    {
        $audience = (string) $audience;
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $orderRef = (string) (isset($orderContext['order_id']) ? $orderContext['order_id'] : '');
        $subject = 'УниКредит лизинг — ' . $orderRef;
        $from = trim((string) (isset($orderContext['store_email']) ? $orderContext['store_email'] : ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$from}\r\n";

        if ($audience === 'admin') {
            $html = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            if (strpos($html, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                error_log('mt_uni_credit: Process 2 admin mail missing leasing block');

                return false;
            }

            return (bool) @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
        }

        if ($audience === 'customer') {
            $html = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            if (preg_match('/\b\d{10}\b/', $html) && strpos($html, 'ЕГН') !== false) {
                error_log('mt_uni_credit: blocked customer Process 2 mail containing EGN');

                return false;
            }
            if (strpos($html, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                error_log('mt_uni_credit: Process 2 customer mail missing leasing block');

                return false;
            }

            return (bool) @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return bool
     */
    public function sendProcess2Notifications(array $shop, array $orderContext, $sensitive)
    {
        $ok = true;
        foreach ($this->resolveProcess2Recipients($shop, $orderContext) as $recipient) {
            if (!$this->sendProcess2Recipient(
                $shop,
                $orderContext,
                $sensitive,
                $recipient['audience'],
                $recipient['email']
            )) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $shop
     * @param string $storeEmail
     * @return array<int, string>
     */
    private function parseAdminEmails(array $shop, $storeEmail)
    {
        $raw = (string) (isset($shop['uni_email']) ? $shop['uni_email'] : '');
        $parts = preg_split('/[,;]+/', $raw);
        if (!is_array($parts)) {
            $parts = array();
        }
        $emails = array();
        foreach ($parts as $part) {
            $email = trim((string) $part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        if ($storeEmail !== '') {
            $filtered = array();
            foreach ($emails as $email) {
                if (strtolower($email) !== strtolower($storeEmail)) {
                    $filtered[] = $email;
                }
            }
            $emails = $filtered;
        }

        return array_values(array_unique($emails));
    }
}
