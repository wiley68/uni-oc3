<?php

class ModelExtensionMtUniCreditCart extends Model
{
    /**
     * Thin helper reserved for cart-side category lookups.
     *
     * @param int $productId
     * @return int[]
     */
    public function getCategories($productId)
    {
        $this->load->model('extension/mt_uni_credit/product');

        return $this->model_extension_mt_uni_credit_product->getCategories((int) $productId);
    }
}
