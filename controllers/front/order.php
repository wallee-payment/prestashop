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

class WalleeOrderModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $methodId = Tools::getValue('methodId', null);
        $cartHash = Tools::getValue('cartHash', null);
        if ($methodId == null || $cartHash == null) {
            $this->context->cookie->wle_error = $this->module->l(
                'There was a technical issue, please try again.',
                'order'
            );
            $this->sendJsonResponse(
                array(
                    'result' => 'failure',
                    'redirect' => $this->context->link->getPageLink('order', true, null)
                )
            );
        }
        $cart = $this->context->cart;
        $redirect = $this->checkAvailablility($cart);
        if (! empty($redirect)) {
            $this->sendJsonResponse(array(
                'result' => 'failure',
                'redirect' => $redirect
            ));
        }

        $spaceId = Configuration::get(WalleeBasemodule::CK_SPACE_ID, null, null, $cart->id_shop);
        $methodConfiguration = new WalleeModelMethodconfiguration($methodId);
        if (! $methodConfiguration->isActive() || $methodConfiguration->getSpaceId() != $spaceId) {
            $this->context->cookie->wle_error = $this->module->l(
                'This payment method is no longer available, please try another one.',
                'order'
            );
            $this->sendJsonResponse(
                array(
                    'result' => 'failure',
                    'redirect' => $this->context->link->getPageLink('order', true, null)
                )
            );
        }
        // Ensure Fees are correct
        WalleeFeehelper::removeFeeSurchargeProductsFromCart($cart);
        WalleeFeehelper::addSurchargeProductToCart($cart);
        WalleeFeehelper::addFeeProductToCart($methodConfiguration, $cart);
        if ($cartHash != WalleeHelper::calculateCartHash($cart)) {
            $this->context->cookie->wle_error = $this->module->l('The cart was changed, please try again.', 'order');
            $this->sendJsonResponse(array(
                'result' => 'failure',
                'reload' => 'true'
            ));
        }
        $orderState = WalleeOrderstatus::getRedirectOrderStatus();
        try {
            $customer = new Customer((int) $cart->id_customer);
            $this->module->validateOrder(
                $cart->id,
                $orderState->id,
                $cart->getOrderTotal(true, Cart::BOTH, null, null, false),
                'wallee_' . $methodId,
                null,
                array(),
                null,
                false,
                $customer->secure_key
            );
            $noIframeParamater = Tools::getValue('wallee-iframe-possible-' . $methodId, null);
            $noIframe = $noIframeParamater == 'false';
            if ($noIframe) {
                $url = WalleeServiceTransaction::instance()->getPaymentPageUrl(
                    $GLOBALS['walleeTransactionIds']['spaceId'],
                    $GLOBALS['walleeTransactionIds']['transactionId']
                );
                $this->sendJsonResponse(array(
                    'result' => 'redirect',
                    'redirect' => $url
                ));
            }
            $this->sendJsonResponse(array(
                'result' => 'success'
            ));
        } catch (Exception $e) {
            $this->context->cookie->wle_error = WalleeHelper::cleanExceptionMessage($e->getMessage());
            $this->sendJsonResponse(
                array(
                    'result' => 'failure',
                    'redirect' => $this->context->link->getPageLink('order', true, null)
                )
            );
        }
    }

    /**
     * Sends the given data as JSON response and ends the request.
     *
     * Any output buffered before is discarded, so that the response body stays valid
     * JSON. The checkout javascript fetches this with dataType 'json', so anything
     * printed before the response (a PHP notice on a shop with display_errors enabled,
     * or output of another module's hook) makes the parsing fail. The javascript then
     * runs into its error handler and tells the customer that the payment failed,
     * although the order has already been created and the transaction is authorized.
     *
     * @param array $response
     */
    protected function sendJsonResponse(array $response)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (! headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($response);
        die();
    }

    /**
     * Checks if the module is still active and various checkout specfic values.
     * Returns a redirect URL where the customer has to be redirected, if there is an issue.
     *
     * @param Cart $cart
     * @return string|NULL
     */
    protected function checkAvailablility(Cart $cart)
    {
        if ($cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || ! $this->module->active ||
            ! Validate::isLoadedObject(new Customer($cart->id_customer))) {
            $this->context->cookie->wle_error = $this->module->l('Your session expired, please try again.', 'order');
            return $this->context->link->getPageLink('order', true, null, "step=1");
        }
        // Check that this payment option is still available in case the customer changed his address just before the
        // end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'wallee') {
                $authorized = true;
                break;
            }
        }
        if (! $authorized) {
            $this->context->cookie->wle_error = $this->module->l(
                'This payment method is no longer available, please try another one.',
                'order'
            );
            return $this->context->link->getPageLink('order', true, null);
        }

        if (! $this->module instanceof Wallee) {
            $this->context->cookie->wle_error = $this->module->l(
                'There was a technical issue, please try again.',
                'order'
            );
            return $this->context->link->getPageLink('order', true, null);
        }
        return null;
    }

    public function setMedia()
    {
        // We do not need styling here
    }

    protected function displayMaintenancePage()
    {
        // We want never to see here the maintenance page.
    }

    protected function displayRestrictedCountryPage()
    {
        // We do not want to restrict the content by any country.
    }

    protected function canonicalRedirection($canonical_url = '')
    {
        // We do not need any canonical redirect
    }
}
