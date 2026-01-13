<?php


if (!defined('_PS_VERSION_')) {
	exit;
}

//TODO merge with release, change to 17 or 1.1?
function upgrade_module_1_0_16($module)
{
	require_once _PS_MODULE_DIR_ . 'wallee/inc/Webhooksignature.php';

	$module->registerHook('actionValidateStepComplete');
	$module->registerHook('actionObjectAddressAddAfter');

	Webhooksignature::run();

	return true;
}
