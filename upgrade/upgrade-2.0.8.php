<?php

if (!defined('_PS_VERSION_')) {
	exit;
}

function upgrade_module_2_0_8($module)
{
	try {
		$moduleInstance = $module;
		if (!($moduleInstance instanceof Wallee) || !$moduleInstance->id) {
			$moduleInstance = \Module::getInstanceByName('wallee');
		}

		if ($moduleInstance && !$moduleInstance->id) {
			$moduleInstance->id = (int) \Module::getModuleIdByName('wallee');
		}

		if (!$moduleInstance || !$moduleInstance->id) {
			throw new \Exception('Unable to load module with a valid id for hook registration.');
		}

		if (!$moduleInstance->registerHook('displayPaymentByBinaries')) {
			throw new \Exception('Hook registration failed.');
		}

		return true;

	} catch (\Exception $e) {
		error_log("Upgrade 2.0.8 failed: " . $e->getMessage());
		return false;
	}
}
