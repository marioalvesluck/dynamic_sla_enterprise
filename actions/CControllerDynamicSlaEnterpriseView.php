<?php declare(strict_types = 0);

namespace Modules\DynamicSlaEnterprise\Actions;

use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;

class CControllerDynamicSlaEnterpriseView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		if (class_exists('\CRoleHelper') && defined('CRoleHelper::UI_MONITORING_PROBLEMS')) {
			return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
		}

		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$data = [
			'user' => ['debug_mode' => CWebUser::$data['debug_mode'] ?? 0],
			'now_ts' => time()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Dynamic SLA Enterprise'));
		$this->setResponse($response);
	}
}
