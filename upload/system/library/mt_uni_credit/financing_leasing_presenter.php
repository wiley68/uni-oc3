<?php

/**
 * Shared UniCredit leasing rows for emails / Thank You / Admin.
 */
final class MtUniCreditFinancingLeasingPresenter
{
    const TITLE = 'УниКредит лизинг';
    const ADMIN_TITLE = 'УниКредит — кредитна заявка';
    const PROCESS2_MESSAGE = 'Очаквайте контакт за потвърждаване на направената от Вас заявка.';

    const SMARTUCF_TERMINAL_FAILURE_MESSAGE =
    'Поръчката Ви е регистрирана успешно в магазина, но заявката за финансиране не беше приета/стартирана успешно от банковата система. '
        . 'Не изпращайте поръчката повторно. При необходимост търговецът ще се свърже с Вас.';

    const LABEL_BANK_STATUS = 'Статус към банката';
    const LABEL_CP_INTERNAL_ID = 'КП поръчка (ID)';
    const LABEL_CP_SHOP_ORDER_ID = 'КП shop order_id';
    const LABEL_MONTHS = 'Срок (месеци)';
    const LABEL_KOP = 'КОП';
    const LABEL_FIRST = 'Първоначална вноска';
    const LABEL_LOAN = 'Сума на заема';
    const LABEL_MONTHLY = 'Месечна вноска';
    const LABEL_TOTAL = 'Обща дължима сума';
    const LABEL_GLP_GPR = 'ГЛП / ГПР';
    const LABEL_MESSAGE = 'Съобщение';
    const LABEL_EGN = 'ЕГН';
    const LABEL_PHONE2 = 'Втори телефон';

    /**
     * @param MtUniCreditFinancingPresentationSnapshot $snapshot
     * @param string $bankStatusLabel
     * @param string $audience
     * @param MtUniCreditProcessTwoSensitiveData|null $sensitive
     * @return array<int, array{label: string, value: string}>
     */
    public function rows(
        MtUniCreditFinancingPresentationSnapshot $snapshot,
        $bankStatusLabel,
        $audience,
        $sensitive = null
    ) {
        $rows = array();
        $status = trim((string) $bankStatusLabel);
        if ($status !== '') {
            $rows[] = array('label' => self::LABEL_BANK_STATUS, 'value' => $status);
        }

        if ($snapshot->controlPanelOrderId !== null && $snapshot->controlPanelOrderId > 0) {
            $rows[] = array(
                'label' => self::LABEL_CP_INTERNAL_ID,
                'value' => (string) $snapshot->controlPanelOrderId,
            );
        }
        if ($snapshot->shopOrderId > 0) {
            $rows[] = array(
                'label' => self::LABEL_CP_SHOP_ORDER_ID,
                'value' => (string) $snapshot->shopOrderId,
            );
        }
        if ($snapshot->months > 0) {
            $rows[] = array('label' => self::LABEL_MONTHS, 'value' => (string) $snapshot->months);
        }
        if ($snapshot->kopCode !== '') {
            $rows[] = array('label' => self::LABEL_KOP, 'value' => $snapshot->kopCode);
        }

        $rows[] = array('label' => self::LABEL_FIRST, 'value' => $this->money($snapshot->firstInstallment));
        $rows[] = array('label' => self::LABEL_LOAN, 'value' => $this->money($snapshot->financedAmount));
        $rows[] = array('label' => self::LABEL_MONTHLY, 'value' => $this->money($snapshot->monthlyInstallment));
        $rows[] = array('label' => self::LABEL_TOTAL, 'value' => $this->money($snapshot->totalPayable));
        $rows[] = array(
            'label' => self::LABEL_GLP_GPR,
            'value' => $this->money($snapshot->glp) . '% / ' . $this->money($snapshot->gpr) . '%',
        );

        if ($snapshot->process2) {
            if (
                $this->includesEgn($audience)
                && $sensitive instanceof MtUniCreditProcessTwoSensitiveData
                && $sensitive->egn !== ''
            ) {
                $rows[] = array('label' => self::LABEL_EGN, 'value' => $sensitive->egn);
            }
            if (
                $this->includesPhone2($audience)
                && $sensitive instanceof MtUniCreditProcessTwoSensitiveData
                && $sensitive->phone2 !== ''
            ) {
                $rows[] = array('label' => self::LABEL_PHONE2, 'value' => $sensitive->phone2);
            }
            if ($audience === MtUniCreditFinancingPresentationAudience::CUSTOMER) {
                $rows[] = array('label' => self::LABEL_MESSAGE, 'value' => self::PROCESS2_MESSAGE);
            }
        } elseif (
            $audience === MtUniCreditFinancingPresentationAudience::CUSTOMER
            && $status === MtUniCreditBankStatus::LABEL_SEND_FAILED_SMARTUCF
        ) {
            $rows[] = array('label' => self::LABEL_MESSAGE, 'value' => self::SMARTUCF_TERMINAL_FAILURE_MESSAGE);
        }

        return $rows;
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @param string $title
     * @return string
     */
    public function renderHtml(array $rows, $title = self::TITLE)
    {
        if ($rows === array()) {
            return '';
        }
        $html = '<div class="mt-uni-credit-leasing-block">';
        if ($title !== '') {
            $html .= '<h3 class="mt-uni-credit-leasing-block__title">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . '</h3>';
        }
        $html .= '<table class="mt-uni-credit-leasing-block__table" style="border-collapse:collapse;width:100%;">';
        foreach ($rows as $row) {
            $html .= '<tr><th style="text-align:left;padding:4px 16px 4px 0;vertical-align:top;">'
                . htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8')
                . '</th><td style="padding:4px 0;">'
                . htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }
        $html .= '</table></div>';

        return $html;
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @param string $title
     * @return string
     */
    public function renderBrHtml(array $rows, $title = self::TITLE)
    {
        if ($rows === array()) {
            return '';
        }
        $parts = array();
        if ($title !== '') {
            $parts[] = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $parts[] = '';
        }
        foreach ($rows as $row) {
            $parts[] = htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8')
                . ': '
                . htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8');
        }

        return implode('<br/>', $parts);
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     * @param string $title
     * @return string
     */
    public function renderText(array $rows, $title = self::TITLE)
    {
        if ($rows === array()) {
            return '';
        }
        $lines = array();
        if ($title !== '') {
            $lines[] = $title;
            $lines[] = '';
        }
        foreach ($rows as $row) {
            $lines[] = $row['label'] . ': ' . $row['value'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param float $amount
     * @return string
     */
    public function money($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param string $audience
     * @return bool
     */
    public function includesEgn($audience)
    {
        return $audience === MtUniCreditFinancingPresentationAudience::ADMIN_EMAIL
            || $audience === MtUniCreditFinancingPresentationAudience::ADMIN_PANEL;
    }

    /**
     * @param string $audience
     * @return bool
     */
    public function includesPhone2($audience)
    {
        return $audience !== MtUniCreditFinancingPresentationAudience::CUSTOMER;
    }
}
