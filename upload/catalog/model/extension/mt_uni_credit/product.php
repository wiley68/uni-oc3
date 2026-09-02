<?php

class ModelExtensionMtUniCreditProduct extends Model
{
    /**
     * @param int $productId
     * @return int[]
     */
    public function getCategories($productId)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return array();
        }

        $query = $this->db->query(
            "SELECT `category_id` FROM `" . DB_PREFIX . "product_to_category`"
                . " WHERE `product_id` = '" . (int) $productId . "'"
        );

        $categories = array();
        if (is_object($query) && isset($query->rows) && is_array($query->rows)) {
            foreach ($query->rows as $row) {
                $categories[] = (int) $row['category_id'];
            }
        }

        return array_values(array_unique($categories));
    }
}
