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

if (!defined('_PS_VERSION_')) {
    exit();
}

use PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType;

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'wallee_autoloader.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'wallee-sdk' . DIRECTORY_SEPARATOR .
    'autoload.php');
class Wallee extends PaymentModule
{

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->name = 'wallee';
        $this->tab = 'payments_gateways';
        $this->author = 'wallee AG';
        $this->bootstrap = true;
        $this->need_instance = 0;
        $this->version = '1.0.16';
        $this->displayName = 'wallee';
        $this->description = $this->l('This PrestaShop module enables to process payments with %s.');
        $this->description = sprintf($this->description, 'wallee');
        $this->module_key = 'PrestaShop_ProductKey_V8';
        $this->ps_versions_compliancy = array(
            'min' => '8',
            'max' => _PS_VERSION_
        );
        parent::__construct();
        $this->confirmUninstall = sprintf(
            $this->l('Are you sure you want to uninstall the %s module?', 'abstractmodule'),
            'wallee'
        );

        // Remove Fee Item
        if (isset($this->context->cart) && Validate::isLoadedObject($this->context->cart)) {
            WalleeFeehelper::removeFeeSurchargeProductsFromCart($this->context->cart);
        }
        if (!empty($this->context->cookie->wle_error)) {
            $errors = $this->context->cookie->wle_error;
            if (is_string($errors)) {
                $this->context->controller->errors[] = $errors;
            } elseif (is_array($errors)) {
                foreach ($errors as $error) {
                    $this->context->controller->errors[] = $error;
                }
            }
            unset($_SERVER['HTTP_REFERER']); // To disable the back button in the error message
            $this->context->cookie->wle_error = null;
        }
    }

    public function addError($error)
    {
        $this->_errors[] = $error;
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getIdentifier()
    {
        return $this->identifier;
    }

    public function install()
    {
        if (!WalleeBasemodule::checkRequirements($this)) {
            return false;
        }
        if (!parent::install()) {
            return false;
        }
        return WalleeBasemodule::install($this);
    }

    public function uninstall()
    {
        return parent::uninstall() && WalleeBasemodule::uninstall($this);
    }

    public function installHooks()
    {
        return WalleeBasemodule::installHooks($this) && $this->registerHook('paymentOptions') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('actionValidateStepComplete') &&
            $this->registerHook('actionObjectAddressAddAfter');
    }

    public function upgrade($version)
    {
        return true;
    }

    public function getBackendControllers()
    {
        return array(
            'AdminWalleeMethodSettings' => array(
                'parentId' => Tab::getIdFromClassName('AdminParentPayment'),
                'name' => 'wallee ' . $this->l('Payment Methods')
            ),
            'AdminWalleeDocuments' => array(
                'parentId' => -1, // No Tab in navigation
                'name' => 'wallee ' . $this->l('Documents')
            ),
            'AdminWalleeOrder' => array(
                'parentId' => -1, // No Tab in navigation
                'name' => 'wallee ' . $this->l('Order Management')
            )
        );
    }

    public function installConfigurationValues()
    {
        return WalleeBasemodule::installConfigurationValues();
    }

    public function uninstallConfigurationValues()
    {
        return WalleeBasemodule::uninstallConfigurationValues();
    }

    public function getContent()
    {
        $output = WalleeBasemodule::handleSaveAll($this);
        $output .= WalleeBasemodule::handleSaveApplication($this);
        $output .= WalleeBasemodule::handleSaveEmail($this);
        $output .= WalleeBasemodule::handleSaveIntegration($this);
        $output .= WalleeBasemodule::handleSaveCartRecreation($this);
        $output .= WalleeBasemodule::handleSaveFeeItem($this);
        $output .= WalleeBasemodule::handleSaveDownload($this);
        $output .= WalleeBasemodule::handleSaveSpaceViewId($this);
        $output .= WalleeBasemodule::handleSaveOrderStatus($this);
        $output .= WalleeBasemodule::displayHelpButtons($this);
        return $output . WalleeBasemodule::displayForm($this);
    }

    public function getConfigurationForms()
    {
        return array(
            WalleeBasemodule::getEmailForm($this),
            WalleeBasemodule::getIntegrationForm($this),
            WalleeBasemodule::getCartRecreationForm($this),
            WalleeBasemodule::getFeeForm($this),
            WalleeBasemodule::getDocumentForm($this),
            WalleeBasemodule::getSpaceViewIdForm($this),
            WalleeBasemodule::getOrderStatusForm($this),
        );
    }

    public function getConfigurationValues()
    {
        return array_merge(
            WalleeBasemodule::getApplicationConfigValues($this),
            WalleeBasemodule::getEmailConfigValues($this),
            WalleeBasemodule::getIntegrationConfigValues($this),
            WalleeBasemodule::getCartRecreationConfigValues($this),
            WalleeBasemodule::getFeeItemConfigValues($this),
            WalleeBasemodule::getDownloadConfigValues($this),
            WalleeBasemodule::getSpaceViewIdConfigValues($this),
            WalleeBasemodule::getOrderStatusConfigValues($this)
        );
    }

    public function getConfigurationKeys()
    {
        return WalleeBasemodule::getConfigurationKeys();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        if (!isset($params['cart']) || !($params['cart'] instanceof Cart)) {
            return;
        }
        $cart = $params['cart'];
        try {
            $transactionService = WalleeServiceTransaction::instance();
            $transaction = $transactionService->getTransactionFromCart($cart);
            $possiblePaymentMethods = $transactionService->getPossiblePaymentMethods($cart, $transaction);
        } catch (WalleeExceptionInvalidtransactionamount $e) {
            PrestaShopLogger::addLog($e->getMessage() . " CartId: " . $cart->id, 2, null, 'Wallee');
            $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $paymentOption->setCallToActionText(
                $this->l('There is an issue with your cart, some payment methods are not available.')
            );
            $paymentOption->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:wallee/views/templates/front/hook/amount_error.tpl'
                )
            );
            $paymentOption->setForm(
                $this->context->smarty->fetch(
                    'module:wallee/views/templates/front/hook/amount_error_form.tpl'
                )
            );
            $paymentOption->setModuleName($this->name . "-error");
            return array(
                $paymentOption
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog($e->getMessage() . " CartId: " . $cart->id, 1, null, 'Wallee');
            return array();
        }
        $shopId = $cart->id_shop;
        $language = Context::getContext()->language->language_code;
        $methods = $this->filterShopMethodConfigurations($shopId, $possiblePaymentMethods);
        $result = array();

        $this->context->smarty->registerPlugin(
            'function',
            'wallee_clean_html',
            array(
                'WalleeSmartyfunctions',
                'cleanHtml'
            )
        );

        foreach (WalleeHelper::sortMethodConfiguration($methods) as $methodConfiguration) {
            $parameters = WalleeBasemodule::getParametersFromMethodConfiguration($this, $methodConfiguration, $cart, $shopId, $language);
            $parameters['priceDisplayTax'] = Group::getPriceDisplayMethod(Group::getCurrent()->id);
            $parameters['orderUrl'] = $this->context->link->getModuleLink(
                'wallee',
                'order',
                array(),
                true
            );
            $this->context->smarty->assign($parameters);
            $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
            $paymentOption->setCallToActionText($parameters['name']);
            $paymentOption->setLogo($parameters['image']);
            $paymentOption->setAction($parameters['link']);
            $paymentOption->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:wallee/views/templates/front/hook/payment_additional.tpl'
                )
            );
            $paymentOption->setForm(
                $this->context->smarty->fetch(
                    'module:wallee/views/templates/front/hook/payment_form.tpl'
                )
            );
            $paymentOption->setModuleName($this->name);
            $result[] = $paymentOption;
        }
        return $result;
    }

    /**
     * Filters configured method entities for the current shop and the available SDK payment methods.
     *
     * @param int $shopId
     * @param \Wallee\Sdk\Model\PaymentMethodConfiguration[] $possiblePaymentMethods
     * @return WalleeModelMethodconfiguration[]
     */
    protected function filterShopMethodConfigurations($shopId, array $possiblePaymentMethods)
    {
        $configured = WalleeModelMethodconfiguration::loadValidForShop($shopId);
        if (empty($configured) || empty($possiblePaymentMethods)) {
            return array();
        }

        $bySpaceAndConfiguration = array();
        foreach ($configured as $methodConfiguration) {
            $spaceId = $methodConfiguration->getSpaceId();
            if (! isset($bySpaceAndConfiguration[$spaceId])) {
                $bySpaceAndConfiguration[$spaceId] = array();
            }
            $bySpaceAndConfiguration[$spaceId][$methodConfiguration->getConfigurationId()] = $methodConfiguration;
        }

        $result = array();
        foreach ($possiblePaymentMethods as $possible) {
            $spaceId = $possible->getSpaceId();
            $configurationId = $possible->getId();
            if (isset($bySpaceAndConfiguration[$spaceId][$configurationId])) {
                $methodConfiguration = $bySpaceAndConfiguration[$spaceId][$configurationId];
                if ($methodConfiguration->isActive()) {
                    $result[] = $methodConfiguration;
                }
            }
        }

        return $result;
    }

    public function hookActionFrontControllerSetMedia()
    {
        $controller = $this->context->controller;

        if (!$controller) {
            return;
        }

        $phpSelf = $controller->php_self;
        if ($phpSelf === 'order' || $phpSelf === 'cart') {

            // Ensure device ID exists
            if (empty($this->context->cookie->wle_device_id)) {
                $this->context->cookie->wle_device_id = WalleeHelper::generateUUID();
            }

            $deviceId = $this->context->cookie->wle_device_id;

            $scriptUrl = WalleeHelper::getBaseGatewayUrl() .
                '/s/' . Configuration::get(WalleeBasemodule::CK_SPACE_ID) .
                '/payment/device.js?sessionIdentifier=' . $deviceId;

            $controller->registerJavascript(
                'wallee-device-identifier',
                $scriptUrl,
                [
                'server' => 'remote',
                'attributes' => 'async'
                ]
            );
        }

        /**
         * ORDER PAGE ONLY
         * Add checkout JS/CSS + iframe handler
         */
        if ($phpSelf === 'order') {

            // checkout styles
            $controller->registerStylesheet(
                'wallee-checkout-css',
                'modules/' . $this->name . '/views/css/frontend/checkout.css'
            );

            // checkout JS
            $controller->registerJavascript(
                'wallee-checkout-js',
                'modules/' . $this->name . '/views/js/frontend/checkout.js'
            );

            // define global JS variables
            Media::addJsDef([
                'walleeCheckoutUrl' => $this->context->link->getModuleLink(
                'wallee',
                'checkout',
                [],
                true
                ),
                'walleeMsgJsonError' => $this->l(
                'The server experienced an unexpected error, you may try again or try a different payment method.'
                )
            ]);

            // Iframe handler JS (only when integration = iframe)
            $cart = $this->context->cart;

            if ($cart && Validate::isLoadedObject($cart)) {
                try {
                    // Get integration type from configuration
                    // 0 = iframe
                    // 1 = payment page
                    $integrationType = (int) Configuration::get(WalleeBasemodule::CK_INTEGRATION);

                    // Only load JS when NOT payment page
                    if ($integrationType !== Configuration::get(WalleeBasemodule::CK_INTEGRATION_TYPE_PAYMENT_PAGE)) {

                        $jsUrl = WalleeServiceTransaction::instance()
                            ->getJavascriptUrl($cart);

                        $this->context->controller->registerJavascript(
                            'wallee-iframe-handler',
                            $jsUrl,
                            [
                            'server' => 'remote',
                            'priority' => 45,
                            'attributes' => 'id="wallee-iframe-handler"'
                            ]
                        );
                    }

                } catch (Exception $e) {
                    // same behavior: silently ignore
                }
            }
        }

        /**
         * ORDER-DETAIL PAGE
         */
        if ($phpSelf === 'order-detail') {
            $controller->registerJavascript(
                'wallee-orderdetail-js',
                'modules/' . $this->name . '/views/js/frontend/orderdetail.js'
            );
        }
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        $this->processAddressChange(isset($params['object']) ? $params['object'] : null);
    }

    public function hookActionValidateStepComplete($params)
    {
        if (isset($params['step_name']) && $params['step_name'] === 'addresses') {
            $this->processAddressChange(null);
        }
    }

    /**
     * Refreshes the pending transaction when the checkout address is created/selected.
     *
     * @param Address|null $address
     */
    private function processAddressChange($address = null)
    {
        $cart = $this->context->cart;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            return;
        }

        try {
            WalleeServiceTransaction::instance()->refreshTransactionFromCart($cart);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Wallee address refresh failed: ' . $e->getMessage(),
                2,
                null,
                $this->name
            );
        }
    }


    public function hookActionAdminControllerSetMedia($arr)
    {
        WalleeBasemodule::hookActionAdminControllerSetMedia($this, $arr);
        $this->context->controller->addCSS(__PS_BASE_URI__ . 'modules/' . $this->name . '/views/css/admin/general.css');
    }

    public function hasBackendControllerDeleteAccess(AdminController $backendController)
    {
        return $backendController->access('delete');
    }

    public function hasBackendControllerEditAccess(AdminController $backendController)
    {
        return $backendController->access('edit');
    }

    /**
     * Show the manual task in the admin bar.
     * The output is moved with javascript to the correct place as better hook is missing.
     *
     * @return string
     */
    public function hookDisplayAdminAfterHeader()
    {
        $result = WalleeBasemodule::hookDisplayAdminAfterHeader($this);
        return $result;
    }

    public function hookWalleeSettingsChanged($params)
    {
        return WalleeBasemodule::hookWalleeSettingsChanged($this, $params);
    }

    public function hookActionMailSend($data)
    {
        return WalleeBasemodule::hookActionMailSend($this, $data);
    }

    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null,
        $order_reference = null
    ) {
        WalleeBasemodule::validateOrder($this, $id_cart, $id_order_state, $amount_paid, $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop, $order_reference);
    }

    public function validateOrderParent(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null,
        $order_reference = null
    ) {
        parent::validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop, $order_reference);
    }

    public function hookDisplayOrderDetail($params)
    {
        return WalleeBasemodule::hookDisplayOrderDetail($this, $params);
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        WalleeBasemodule::hookDisplayBackOfficeHeader($this, $params);
    }

    public function hookDisplayAdminOrderLeft($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderLeft($this, $params);
    }

    public function hookDisplayAdminOrderTabOrder($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderTabOrder($this, $params);
    }

    public function hookDisplayAdminOrderMain($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderMain($this, $params);
    }

    public function hookActionOrderSlipAdd($params)
    {
        $refundParameters = Tools::getAllValues();

        $order = $params['order'];

        if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
            $idOrder = Tools::getValue('id_order');
            if (!$idOrder) {
                $order = $params['order'];
                $idOrder = (int)$order->id;
            }
            $order = new Order((int) $idOrder);
            if (! Validate::isLoadedObject($order) || $order->module != $module->name) {
                return;
            }
        }

        $strategy = WalleeBackendStrategyprovider::getStrategy();

        if ($strategy->isVoucherOnlyWallee($order, $refundParameters)) {
            return;
        }

        // need to manually set this here as it's expected downstream
        $refundParameters['partialRefund'] = true;

        $backendController = Context::getContext()->controller;
        $editAccess = 0;

        $access = Profile::getProfileAccess(
            Context::getContext()->employee->id_profile,
            (int) Tab::getIdFromClassName('AdminOrders')
        );
        $editAccess = isset($access['edit']) && $access['edit'] == 1;

        if ($editAccess) {
            try {
                $parsedData = $strategy->simplifiedRefund($refundParameters);
                WalleeServiceRefund::instance()->executeRefund($order, $parsedData);
            } catch (Exception $e) {
                $backendController->errors[] = WalleeHelper::cleanExceptionMessage($e->getMessage());
            }
        } else {
            $backendController->errors[] = Tools::displayError('You do not have permission to delete this.');
        }
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderTabLink($this, $params);
    }

    public function hookDisplayAdminOrderContentOrder($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderContentOrder($this, $params);
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrderTabContent($this, $params);
    }

    public function hookDisplayAdminOrder($params)
    {
        return WalleeBasemodule::hookDisplayAdminOrder($this, $params);
    }

    public function hookActionAdminOrdersControllerBefore($params)
    {
        return WalleeBasemodule::hookActionAdminOrdersControllerBefore($this, $params);
    }

    public function hookActionObjectOrderPaymentAddBefore($params)
    {
        WalleeBasemodule::hookActionObjectOrderPaymentAddBefore($this, $params);
    }

    public function hookActionOrderEdited($params)
    {
        WalleeBasemodule::hookActionOrderEdited($this, $params);
    }

    public function hookActionOrderGridDefinitionModifier($params)
    {
        WalleeBasemodule::hookActionOrderGridDefinitionModifier($this, $params);
    }

    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        WalleeBasemodule::hookActionOrderGridQueryBuilderModifier($this, $params);
    }

    public function hookActionProductCancel($params)
    {
        if ($params['action'] === CancellationActionType::PARTIAL_REFUND) {
            $idOrder = Tools::getValue('id_order');
            $refundParameters = Tools::getAllValues();

            $order = $params['order'];

            if (!Validate::isLoadedObject($order) || $order->module != $this->name) {
                return;
            }

            $strategy = WalleeBackendStrategyprovider::getStrategy();
            if ($strategy->isVoucherOnlyWallee($order, $refundParameters)) {
                return;
            }

            // need to manually set this here as it's expected downstream
            $refundParameters['partialRefund'] = true;

            $backendController = Context::getContext()->controller;
            $editAccess = 0;

            $access = Profile::getProfileAccess(
                Context::getContext()->employee->id_profile,
                (int) Tab::getIdFromClassName('AdminOrders')
            );
            $editAccess = isset($access['edit']) && $access['edit'] == 1;

            if ($editAccess) {
                try {
                    $parsedData = $strategy->simplifiedRefund($refundParameters);
                    WalleeServiceRefund::instance()->executeRefund($order, $parsedData);
                } catch (Exception $e) {
                    $backendController->errors[] = WalleeHelper::cleanExceptionMessage($e->getMessage());
                }
            } else {
                $backendController->errors[] = Tools::displayError('You do not have permission to delete this.');
            }
        }
    }
}
