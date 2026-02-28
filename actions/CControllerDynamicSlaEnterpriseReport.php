<?php declare(strict_types = 0);

namespace Modules\DynamicSlaEnterprise\Actions;

use CController;
use CControllerResponseData;
use CRoleHelper;

class CControllerDynamicSlaEnterpriseReport extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'persona' => 'in noc,gestao,diretoria',
			'auto_print' => 'in 0,1',
			'payload' => 'string'
		]);
	}

	protected function checkPermissions(): bool {
		if (class_exists('\\CRoleHelper') && defined('CRoleHelper::UI_MONITORING_PROBLEMS')) {
			return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
		}

		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$persona = strtolower((string) $this->getInput('persona', 'noc'));
		if (!in_array($persona, ['noc', 'gestao', 'diretoria'], true)) {
			$persona = 'noc';
		}
		$auto_print = (int) $this->getInput('auto_print', 0);
		$payload_raw = (string) $this->getInput('payload', '');

		$report_data = [];
		if ($payload_raw !== '') {
			$decoded = base64_decode($payload_raw, true);
			if ($decoded !== false) {
				$json = json_decode($decoded, true);
				if (is_array($json)) {
					$report_data = $json;
				}
			}
		}

		$response = new CControllerResponseData([
			'persona' => $persona,
			'auto_print' => $auto_print,
			'report' => $report_data,
			'generated_at' => date('Y-m-d H:i:s')
		]);
		$response->setTitle(_('Dynamic SLA Enterprise Report'));
		$this->setResponse($response);
	}
}

