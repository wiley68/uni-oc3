<?php

/**
 * Process 2 mail via PHP mail() — no EGN in customer audience.
 */
final class MtUniCreditPhpMailProcessTwoMailer implements MtUniCreditProcessTwoMailPort
{
    /** @var MtUniCreditProcessTwoLeasingMailPresenter */
    private $presenter;

    /** @var MtUniCreditRecordingProcessTwoMailer|null */
    private $recorder;

    /**
     * @param MtUniCreditProcessTwoLeasingMailPresenter|null $presenter
     * @param MtUniCreditRecordingProcessTwoMailer|null $recorder
     */
    public function __construct($presenter = null, $recorder = null)
    {
        $this->presenter = $presenter instanceof MtUniCreditProcessTwoLeasingMailPresenter
            ? $presenter
            : new MtUniCreditProcessTwoLeasingMailPresenter();
        $this->recorder = $recorder instanceof MtUniCreditRecordingProcessTwoMailer ? $recorder : null;
    }

    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return bool
     */
    public function sendProcess2Notifications(array $shop, array $orderContext, $sensitive)
    {
        if ($this->recorder instanceof MtUniCreditRecordingProcessTwoMailer) {
            return $this->recorder->sendProcess2Notifications($shop, $orderContext, $sensitive);
        }

        $orderRef = (string) (isset($orderContext['order_id']) ? $orderContext['order_id'] : '');
        $subject = 'УниКредит лизинг — ' . $orderRef;
        $from = trim((string) (isset($orderContext['store_email']) ? $orderContext['store_email'] : ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }

        $adminEmails = $this->parseAdminEmails($shop, $from);
        $customerEmail = trim((string) (isset($orderContext['customer_email']) ? $orderContext['customer_email'] : ''));
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$from}\r\n";

        $ok = true;
        if ($adminEmails !== array()) {
            $adminHtml = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            if (strpos($adminHtml, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                error_log('mt_uni_credit: Process 2 admin mail missing leasing block');
                $ok = false;
            } else {
                foreach ($adminEmails as $to) {
                    if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $adminHtml, $headers)) {
                        $ok = false;
                    }
                }
            }
        }

        if (
            $customerEmail !== ''
            && !in_array(strtolower($customerEmail), array_map('strtolower', $adminEmails), true)
        ) {
            $customerHtml = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            if (preg_match('/\b\d{10}\b/', $customerHtml) && strpos($customerHtml, 'ЕГН') !== false) {
                error_log('mt_uni_credit: blocked customer Process 2 mail containing EGN');
                $ok = false;
            } elseif (strpos($customerHtml, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                error_log('mt_uni_credit: Process 2 customer mail missing leasing block');
                $ok = false;
            } elseif (!@mail($customerEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $customerHtml, $headers)) {
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
