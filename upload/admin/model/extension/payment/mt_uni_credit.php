<?php

require_once DIR_SYSTEM . 'library/mt_uni_credit/bootstrap.php';

class ModelExtensionPaymentMtUniCredit extends Model
{
    /**
     * Payment extension install owns payment-method defaults only.
     *
     * @return void
     */
    public function install()
    {
        MtUniCreditInstaller::ensureDefaults(
            $this,
            MtUniCreditConstants::PAYMENT_SETTINGS_CODE,
            MtUniCreditConstants::defaultPaymentSettings()
        );
    }

    /**
     * Remove payment settings only. Module settings and financing evidence remain untouched.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting(MtUniCreditConstants::PAYMENT_SETTINGS_CODE);
    }
}
