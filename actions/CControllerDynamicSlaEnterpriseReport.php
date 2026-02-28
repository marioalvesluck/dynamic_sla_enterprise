<?php declare(strict_types = 0);

namespace Modules\DynamicSlaEnterprise\Actions;

use API;
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

		$scope = $this->resolveScopeNames((array) ($report_data['filters'] ?? []));

		$generated_at_ts = time();
		$seed = json_encode([
			'persona' => $persona,
			'payload' => $report_data,
			'generated_at' => $generated_at_ts
		]);
		$report_id = strtoupper(substr(hash('sha256', (string) $seed), 0, 12));

		$response = new CControllerResponseData([
			'persona' => $persona,
			'auto_print' => $auto_print,
			'report' => $report_data,
			'generated_at' => date('Y-m-d H:i:s', $generated_at_ts),
			'meta' => [
				'report_id' => $report_id,
				'timezone' => date_default_timezone_get(),
				'author' => 'NOC',
				'environment' => 'Production',
				'scope' => $scope,
				'source' => 'frontend_snapshot'
			]
		]);
		$response->setTitle(_('Inteligence Enterprise Report'));
		$this->setResponse($response);
	}

	private function resolveScopeNames(array $filters): array {
		$groupids = array_values(array_filter(array_map('intval', (array) ($filters['groupids'] ?? []))));
		$hostids = array_values(array_filter(array_map('intval', (array) ($filters['hostids'] ?? []))));
		$triggerids = array_values(array_filter(array_map('intval', (array) ($filters['triggerids'] ?? []))));

		$groups = [];
		$hosts = [];
		$triggers = [];

		if ($groupids) {
			try {
				$rows = API::HostGroup()->get([
					'output' => ['groupid', 'name'],
					'groupids' => $groupids,
					'preservekeys' => false
				]);
				foreach ($rows as $r) {
					$groups[] = (string) ($r['name'] ?? '');
				}
			}
			catch (\Throwable $e) {
			}
		}

		if ($hostids) {
			try {
				$rows = API::Host()->get([
					'output' => ['hostid', 'name'],
					'hostids' => $hostids,
					'preservekeys' => false
				]);
				foreach ($rows as $r) {
					$hosts[] = (string) ($r['name'] ?? '');
				}
			}
			catch (\Throwable $e) {
			}
		}

		if ($triggerids) {
			try {
				$rows = API::Trigger()->get([
					'output' => ['triggerid', 'description'],
					'triggerids' => $triggerids,
					'expandDescription' => true,
					'preservekeys' => false,
					'limit' => 200
				]);
				foreach ($rows as $r) {
					$triggers[] = (string) ($r['description'] ?? '');
				}
			}
			catch (\Throwable $e) {
			}
		}

		return [
			'groups' => $groups,
			'hosts' => $hosts,
			'triggers' => $triggers
		];
	}
}
