<?php

/**
 * Test double / recorder for Process 2 leasing mail (tests only — not shipped in upload/).
 */
final class MtUniCreditRecordingProcessTwoMailer implements MtUniCreditProcessTwoMailPort
{
    /** @var array<int, array<string, mixed>> */
    public $sent = array();

    /** @var bool */
    public $forceFailure = false;

    /** @var array<string, bool> lowercase email => force fail */
    public $forceFailureByEmail = array();

    /** @var callable|null fn(audience, email): void — invoked immediately after a successful send record */
    public $afterSuccessfulSend;

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
        $from = (string) (isset($orderContext['store_email']) ? $orderContext['store_email'] : '');
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
        $key = strtolower($email);
        if ($this->forceFailure || !empty($this->forceFailureByEmail[$key])) {
            return false;
        }

        if ($audience === 'admin') {
            $html = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            if (strpos($html, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                return false;
            }
            $this->sent[] = array(
                'audience' => 'admin',
                'to' => $email,
                'html' => $html,
                'has_egn' => $sensitive instanceof MtUniCreditProcessTwoSensitiveData
                    && $sensitive->egn !== ''
                    && strpos($html, $sensitive->egn) !== false,
            );
            if (is_callable($this->afterSuccessfulSend)) {
                call_user_func($this->afterSuccessfulSend, $audience, $email);
            }

            return true;
        }

        if ($audience === 'customer') {
            $html = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            if (preg_match('/\b\d{10}\b/', $html) && strpos($html, 'ЕГН') !== false) {
                return false;
            }
            if (strpos($html, MtUniCreditFinancingLeasingPresenter::TITLE) === false) {
                return false;
            }
            $this->sent[] = array(
                'audience' => 'customer',
                'to' => $email,
                'html' => $html,
                'has_egn' => false,
            );
            if (is_callable($this->afterSuccessfulSend)) {
                call_user_func($this->afterSuccessfulSend, $audience, $email);
            }

            return true;
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
        if ($this->forceFailure) {
            return false;
        }
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
