<?php

/**
 * Test double / recorder for Process 2 leasing mail.
 */
final class MtUniCreditRecordingProcessTwoMailer implements MtUniCreditProcessTwoMailPort
{
    /** @var array<int, array<string, mixed>> */
    public $sent = array();

    /** @var bool */
    public $forceFailure = false;

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
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return bool
     */
    public function sendProcess2Notifications(array $shop, array $orderContext, $sensitive)
    {
        if ($this->forceFailure) {
            return false;
        }

        $adminEmails = $this->parseAdminEmails($shop, (string) (isset($orderContext['store_email']) ? $orderContext['store_email'] : ''));
        $customerEmail = trim((string) (isset($orderContext['customer_email']) ? $orderContext['customer_email'] : ''));
        $ok = true;

        if ($adminEmails !== array()) {
            $adminHtml = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            if (strpos($adminHtml, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                $ok = false;
            } else {
                foreach ($adminEmails as $to) {
                    $this->sent[] = array(
                        'audience' => 'admin',
                        'to' => $to,
                        'html' => $adminHtml,
                        'has_egn' => $sensitive instanceof MtUniCreditProcessTwoSensitiveData
                            && $sensitive->egn !== ''
                            && strpos($adminHtml, $sensitive->egn) !== false,
                    );
                }
            }
        }

        if (
            $customerEmail !== ''
            && !in_array(strtolower($customerEmail), array_map('strtolower', $adminEmails), true)
        ) {
            $customerHtml = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            if (preg_match('/\b\d{10}\b/', $customerHtml) && strpos($customerHtml, 'ЕГН') !== false) {
                $ok = false;
            } elseif (strpos($customerHtml, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                $ok = false;
            } else {
                $this->sent[] = array(
                    'audience' => 'customer',
                    'to' => $customerEmail,
                    'html' => $customerHtml,
                    'has_egn' => false,
                );
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
