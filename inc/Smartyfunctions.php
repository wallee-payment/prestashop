<?php
/**
 * wallee Prestashop
 *
 * This Prestashop module enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2026 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

class WalleeSmartyfunctions
{
    public static function translate($params, $smarty)
    {
        $text = $params['text'];
        return WalleeHelper::translate($text);
    }

    /**
     * Returns the URL to the refund detail view in wallee.
     *
     * @return string
     */
    public static function getRefundUrl($params, $smarty)
    {
        $refundJob = $params['refund'];
        return WalleeHelper::getRefundUrl($refundJob);
    }

    public static function getRefundAmount($params, $smarty)
    {
        $refundJob = $params['refund'];
        return WalleeBackendStrategyprovider::getStrategy()->getRefundTotal(
            $refundJob->getRefundParameters()
        );
    }

    public static function getRefundType($params, $smarty)
    {
        $refundJob = $params['refund'];
        return WalleeBackendStrategyprovider::getStrategy()->getWalleeRefundType(
            $refundJob->getRefundParameters()
        );
    }

    /**
     * Returns the URL to the completion detail view in wallee.
     *
     * @return string
     */
    public static function getCompletionUrl($params, $smarty)
    {
        $completionJob = $params['completion'];
        return WalleeHelper::getCompletionUrl($completionJob);
    }

    /**
     * Returns the URL to the void detail view in wallee.
     *
     * @return string
     */
    public static function getVoidUrl($params, $smarty)
    {
        $voidJob = $params['void'];
        return WalleeHelper::getVoidUrl($voidJob);
    }
    
    /**
     * Returns the URL to the void detail view in wallee.
     *
     * @return string
     */
    public static function cleanHtml($params, $smarty)
    {
        return strip_tags($params['text'], '<a><b><strong><i><img><span><div>');
    }

    /**
     * Formats a price in the current context's currency and locale.
     *
     * Replacement for Tools::displayPrice(), which was removed in PrestaShop 9.
     *
     * @return string
     */
    public static function displayPrice($params, $smarty)
    {
        $context = Context::getContext();
        $formatted = $context->getCurrentLocale()->formatPrice($params['price'], $context->currency->iso_code);
        return htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Returns the URL to the void detail view in wallee.
     *
     * @return string
     */
    public static function outputMethodForm($params, $smarty)
    {
        return $params['form'];
    }
}
