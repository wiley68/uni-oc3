<?php

/**
 * Module-local operator settings normalization (OC4 semantic authority).
 */
final class MtUniCreditLocalSettings
{
    /**
     * @return array<int, string>
     */
    public static function productButtonActions()
    {
        return array(
            MtUniCreditConstants::BUTTON_ACTION_ADD_TO_CART,
            MtUniCreditConstants::BUTTON_ACTION_BUY,
        );
    }

    /**
     * @param mixed $action
     * @return string
     */
    public static function normalizeProductButtonAction($action)
    {
        $action = trim((string) $action);

        return in_array($action, self::productButtonActions(), true)
            ? $action
            : MtUniCreditConstants::DEFAULT_PRODUCT_BUTTON_ACTION;
    }

    /**
     * @param mixed $spacing
     * @return string
     */
    public static function normalizeButtonTopSpacing($spacing)
    {
        if (!is_numeric($spacing)) {
            return (string) MtUniCreditConstants::DEFAULT_BUTTON_TOP_SPACING;
        }

        $value = (int) $spacing;

        if ($value < 0) {
            $value = 0;
        }

        if ($value > MtUniCreditConstants::MAX_BUTTON_TOP_SPACING) {
            $value = MtUniCreditConstants::MAX_BUTTON_TOP_SPACING;
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function normalizeFlag($value)
    {
        return !empty($value) && (string) $value !== '0' ? '1' : '0';
    }
}
