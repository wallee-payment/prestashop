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

use PrestaShop\PrestaShop\Adapter\ServiceLocator;

class WalleeVersionadapter
{
    public static function getConfigurationInterface()
    {
        return ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\ConfigurationInterface');
    }

    /**
     * Returns the precision to use when rounding prices.
     *
     * PrestaShop 9 removed the _PS_PRICE_COMPUTE_PRECISION_ constant, so the configuration
     * lookup returns null there, which silently degrades every ps_round() call to 0 decimals.
     */
    public static function getPriceComputePrecision()
    {
        if (defined('_PS_PRICE_COMPUTE_PRECISION_')) {
            return (int) _PS_PRICE_COMPUTE_PRECISION_;
        }

        $context = Context::getContext();
        if ($context !== null && Validate::isLoadedObject($context->currency)) {
            return (int) $context->getComputingPrecision();
        }

        return 2;
    }

    public static function getAddressFactory()
    {
        return ServiceLocator::get('\\PrestaShop\\PrestaShop\\Adapter\\AddressFactory');
    }

    public static function clearCartRuleStaticCache()
    {
	    call_user_func(array(
	      'CartRule',
	      'resetStaticCache'
	    ));
    }

    public static function getAdminOrderTemplate()
    {
	    return 'views/templates/admin/hook/admin_order.tpl';
    }

    public static function isVoucherOnlyWallee($postData)
    {
	    return isset($postData['cancel_product']['voucher'])
	      && isset($postData['cancel_product']['voucher_refund_type'])
	      && $postData['cancel_product']['voucher'] == 1
	      && $postData['cancel_product']['voucher_refund_type'] == 1
	      && ! isset($postData['cancel_product']['wallee_offline'])
	      && ! isset($postData['cancel_product']['credit_slip']);
    }
}
