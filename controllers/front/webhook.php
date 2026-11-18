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

class WalleeWebhookModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;

    public $ssl = true;

    public function handleWebhookErrors($errno, $errstr, $errfile, $errline)
    {
        $fatal = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
        if ($errno & $fatal) {
            throw new ErrorException($errstr, $errno, E_ERROR, $errfile, $errline);
        }
        return false;
    }

    public function postProcess()
    {
        // We set the status to 500, so if we encounter a state where prestashop just dies instead of throwing an error,
        // the webhook is marked as failed.
        header('HTTP/1.1 500 Internal Server Error');
        $webhookService = WalleeServiceWebhook::instance();
        set_error_handler(array(
            $this,
            'handleWebhookErrors'
        ));
        try {
            $rawRequestBody = Tools::file_get_contents('php://input');

            $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

            if (empty($signature)) {
                throw new WalleeExceptionInvalidwebhooksignature('Missing webhook signature.');
            }

            $encryptionService = new \Wallee\Sdk\Service\WebhookEncryptionService(
                WalleeHelper::getApiClient()
            );
            // Checking if webhook has correct signature. A malformed signature header is treated the same as an
            // invalid one, since isContentValid() throws InvalidArgumentException instead of returning false for it.
            try {
                $isContentValid = $encryptionService->isContentValid($signature, $rawRequestBody);
            } catch (InvalidArgumentException $e) {
                throw new WalleeExceptionInvalidwebhooksignature($e->getMessage(), 0, $e);
            }
            if (!$isContentValid) {
                throw new WalleeExceptionInvalidwebhooksignature('Invalid webhook signature.');
            }

            $parsed = json_decode(trim($rawRequestBody));
            if ($parsed == false) {
                throw new Exception('Could not parse request body.');
            }
            $request = new WalleeWebhookRequest($parsed);
            $webhookEntity = $webhookService->getWebhookEntityForId($request->getListenerEntityId());
            if ($webhookEntity === null) {
                throw new Exception(
                    sprintf(
                        'Could not retrieve webhook model for listener entity id: %s',
                        $request->getListenerEntityId()
                    )
                );
            }
            $webhookHandlerClassName = $webhookEntity->getHandlerClassName();
            $webhookHandler = $webhookHandlerClassName::instance();
            $webhookHandler->process($request);
            header('HTTP/1.1 200 OK');
            die();
        } catch (WalleeExceptionInvalidwebhooksignature $e) {
            // We log the error but return 200 so the sender does not retry a request that will never carry a
            // valid signature, since it is not a genuine webhook.
            PrestaShopLogger::addLog($e->getMessage(), 3, null, 'WalleeWebhookEntity');
            header('HTTP/1.1 200 OK');
            echo $e->getMessage();
            die();
        } catch (Exception $e) {
            PrestaShopLogger::addLog($e->getMessage(), 3, null, 'WalleeWebhookEntity');
            echo $e->getMessage();
            die();
        }
    }

    public function setMedia()
    {
        // We do not need styling here
    }

    protected function displayMaintenancePage()
    {
        // We never display the maintenance page.
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
