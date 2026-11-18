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

class AdminWalleeOrderController extends ModuleAdminController
{
    public function postProcess()
    {
        parent::postProcess();
        WalleeHelper::endAdminRequest();
    }

    public function initProcess()
    {
        parent::initProcess();
        $access = Profile::getProfileAccess(
            $this->context->employee->id_profile,
            (int) Tab::getIdFromClassName('AdminOrders')
        );
        if ($access['edit'] === '1' && ($action = Tools::getValue('action'))) {
            $this->action = $action;
        } else {
            echo json_encode(
                array(
                    'success' => 'false',
                    'message' => $this->module->l(
                        'You do not have permission to edit the order.',
                        'adminwalleeordercontroller'
                    )
                )
            );
            WalleeHelper::endAdminRequest();
        }
    }

    public function ajaxProcessUpdateOrder()
    {
        if (Tools::isSubmit('id_order')) {
            try {
                $order = new Order(Tools::getValue('id_order'));
                WalleeServiceTransactioncompletion::instance()->updateForOrder($order);
                WalleeServiceTransactioncompletion::instance()->updateForOrder($order);
                echo json_encode(array(
                    'success' => 'true'
                ));
                WalleeHelper::endAdminRequest();
            } catch (Exception $e) {
                echo json_encode(array(
                    'success' => 'false',
                    'message' => $e->getMessage()
                ));
                WalleeHelper::endAdminRequest();
            }
        } else {
            echo json_encode(
                array(
                    'success' => 'false',
                    'message' => $this->module->l('Incomplete Request.', 'adminwalleeordercontroller')
                )
            );
            WalleeHelper::endAdminRequest();
        }
    }

    public function ajaxProcessVoidOrder()
    {
        if (Tools::isSubmit('id_order')) {
            try {
                $order = new Order(Tools::getValue('id_order'));
                WalleeServiceTransactionvoid::instance()->executeVoid($order);
                echo json_encode(
                    array(
                        'success' => 'true',
                        'message' => $this->module->l(
                            'The order is updated automatically once the void is processed.',
                            'adminwalleeordercontroller'
                        )
                    )
                );
                WalleeHelper::endAdminRequest();
            } catch (Exception $e) {
                echo json_encode(
                    array(
                        'success' => 'false',
                        'message' => WalleeHelper::cleanExceptionMessage($e->getMessage())
                    )
                );
                WalleeHelper::endAdminRequest();
            }
        } else {
            echo json_encode(
                array(
                    'success' => 'false',
                    'message' => $this->module->l('Incomplete Request.', 'adminwalleeordercontroller')
                )
            );
            WalleeHelper::endAdminRequest();
        }
    }

    public function ajaxProcessCompleteOrder()
    {
        if (Tools::isSubmit('id_order')) {
            try {
                $order = new Order(Tools::getValue('id_order'));
                WalleeServiceTransactioncompletion::instance()->executeCompletion($order);
                echo json_encode(
                    array(
                        'success' => 'true',
                        'message' => $this->module->l(
                            'The order is updated automatically once the completion is processed.',
                            'adminwalleeordercontroller'
                        )
                    )
                );
                WalleeHelper::endAdminRequest();
            } catch (Exception $e) {
                echo json_encode(
                    array(
                        'success' => 'false',
                        'message' => WalleeHelper::cleanExceptionMessage($e->getMessage())
                    )
                );
                WalleeHelper::endAdminRequest();
            }
        } else {
            echo json_encode(
                array(
                    'success' => 'false',
                    'message' => $this->module->l('Incomplete Request.', 'adminwalleeordercontroller')
                )
            );
            WalleeHelper::endAdminRequest();
        }
    }
}
