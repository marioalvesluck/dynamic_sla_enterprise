<?php declare(strict_types = 0);

namespace Modules\DynamicSlaEnterprise\Actions;

use API;
use CController;
use CRoleHelper;
use Throwable;

class CControllerDynamicSlaEnterpriseData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		if (class_exists('\\CRoleHelper') && defined('CRoleHelper::UI_MONITORING_PROBLEMS')) {
			return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
		}

		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function checkInput(): bool {
		$fields = [
			'mode' => 'string',
			'groupids' => 'string',
			'hostids' => 'string',
			'triggerids' => 'string',
			'severity' => 'string',
			'severity_explicit' => 'string',
			'exclude_triggerids' => 'string',
			'period' => 'string',
			'from' => 'string',
			'to' => 'string',
			'business_mode' => 'string',
			'business_start' => 'string',
			'business_end' => 'string',
			'exclude_maintenance' => 'string',
			'slo_target' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function doAction(): void {
		header('Content-Type: application/json');

		try {
			$mode = (string) $this->getInput('mode', 'calculate');
			if ($mode === 'options') {
				echo $this->encodeJson(['success' => true, 'data' => $this->buildOptionsPayload()]);
				exit;
			}

			echo $this->encodeJson(['success' => true, 'data' => $this->buildCalculationPayload()]);
			exit;
		}
		catch (Throwable $e) {
			echo $this->encodeJson([
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			]);
			exit;
		}
	}

	private function buildOptionsPayload(): array {
		$groupids = $this->parseIds((string) $this->getInput('groupids', ''));
		$hostids = $this->parseIds((string) $this->getInput('hostids', ''));
		$triggerids = $this->parseIds((string) $this->getInput('triggerids', ''));
		// Severity filter temporarily disabled by product decision.
		$severity_explicit = false;
		$severities = [];
		$debug = [
			'input_groupids' => $groupids,
			'input_hostids' => $hostids,
			'input_severity' => $severities,
			'severity_explicit' => $severity_explicit
		];

		[$from_ts, $to_ts] = $this->resolveWindow(
			(string) $this->getInput('period', '30d'),
			(string) $this->getInput('from', ''),
			(string) $this->getInput('to', '')
		);

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'preservekeys' => false,
			'sortfield' => 'name',
			'sortorder' => 'ASC'
		]);

		$hosts_params = [
			'output' => ['hostid', 'name'],
			'monitored_hosts' => true,
			'preservekeys' => false,
			'sortfield' => 'name',
			'sortorder' => 'ASC'
		];
		if ($groupids) {
			$hosts_params['groupids'] = $groupids;
		}
		$hosts = API::Host()->get($hosts_params);

		if (!$groups) {
			$all_hosts_for_groups = API::Host()->get([
				'output' => ['hostid'],
				'monitored_hosts' => true,
				'selectHostGroups' => ['groupid', 'name'],
				'preservekeys' => false
			]);
			$group_map = [];
			foreach ($all_hosts_for_groups as $host) {
				foreach (($host['hostgroups'] ?? []) as $group) {
					$gid = (int) ($group['groupid'] ?? 0);
					if ($gid <= 0) {
						continue;
					}
					$group_map[$gid] = [
						'groupid' => $gid,
						'name' => (string) ($group['name'] ?? _s('Group %1$s', (string) $gid))
					];
				}
			}
			$groups = array_values($group_map);
			usort($groups, static function(array $a, array $b): int {
				return strcasecmp((string) $a['name'], (string) $b['name']);
			});
		}

		if ($hostids) {
			$valid_hostids = array_flip(array_map('intval', array_column($hosts, 'hostid')));
			$hostids = array_values(array_filter($hostids, static function(int $hostid) use ($valid_hostids): bool {
				return isset($valid_hostids[$hostid]);
			}));
		}

		$window_trace = [];
		$window_triggerids = $this->collectTriggerIdsWithIncidentsInWindowTrace($from_ts, $to_ts, $groupids, $hostids, $severities, $window_trace);
		$debug['window_triggerids_count'] = count($window_triggerids);
		$debug['window_trace'] = $window_trace;
		if (!$window_triggerids && ($groupids || $hostids || $severities)) {
			$problem_screen_trace = [];
			$window_triggerids = $this->fallbackTriggerIdsFromProblemsScreenStyleTrace($groupids, $hostids, $severities, $problem_screen_trace);
			$debug['problem_screen_triggerids_count'] = count($window_triggerids);
			$debug['problem_screen_trace'] = $problem_screen_trace;
		}

		$triggers = [];
		if ($window_triggerids) {
			$triggers = API::Trigger()->get([
				'output' => ['triggerid', 'description', 'priority', 'value'],
				'selectHosts' => ['hostid', 'name'],
				'expandDescription' => true,
				'preservekeys' => false,
				'sortfield' => 'description',
				'sortorder' => 'ASC',
				'triggerids' => $window_triggerids,
				'limit' => 5000
			]);
		}
		$debug['trigger_get_count'] = is_array($triggers) ? count($triggers) : 0;
		if (!$triggers && ($groupids || $hostids || $severities)) {
			$fallback_problem_ids = $this->fallbackTriggerIdsFromProblems($groupids, $hostids, $severities);
			$debug['problem_fallback_ids_count'] = count($fallback_problem_ids);
			if ($fallback_problem_ids) {
				$triggers = API::Trigger()->get([
					'output' => ['triggerid', 'description', 'priority', 'value'],
					'selectHosts' => ['hostid', 'name'],
					'expandDescription' => true,
					'preservekeys' => false,
					'sortfield' => 'description',
					'sortorder' => 'ASC',
					'triggerids' => $fallback_problem_ids,
					'limit' => 5000
				]);
				$debug['trigger_get_after_problem_fallback_count'] = is_array($triggers) ? count($triggers) : 0;
			}
		}
		if (!$triggers && ($groupids || $hostids || $severities)) {
			$fallback_ids = $this->fallbackTriggerIdsFromEvents($from_ts, $to_ts, $groupids, $hostids, $severities);
			$debug['event_fallback_ids_count'] = count($fallback_ids);
			if ($fallback_ids) {
				$triggers = API::Trigger()->get([
					'output' => ['triggerid', 'description', 'priority', 'value'],
					'selectHosts' => ['hostid', 'name'],
					'expandDescription' => true,
					'preservekeys' => false,
					'sortfield' => 'description',
					'sortorder' => 'ASC',
					'triggerids' => $fallback_ids,
					'limit' => 5000
				]);
				$debug['trigger_get_after_event_fallback_count'] = is_array($triggers) ? count($triggers) : 0;
			}
		}
		$window_problem_ids = $this->findTriggersWithProblemsInWindow(array_map('intval', array_column($triggers, 'triggerid')), $from_ts, $to_ts);
		$debug['window_problem_ids_count'] = count($window_problem_ids);

		$groups_out = [];
		foreach ($groups as $group) {
			$groups_out[] = ['id' => (int) $group['groupid'], 'name' => (string) $group['name']];
		}

		$hosts_out = [];
		foreach ($hosts as $host) {
			$hosts_out[] = ['id' => (int) $host['hostid'], 'name' => (string) $host['name']];
		}

		$triggers_out = [];
		foreach ($triggers as $trigger) {
			$tid = (int) $trigger['triggerid'];
			$host_names = [];
			foreach (($trigger['hosts'] ?? []) as $host) {
				$host_names[] = (string) ($host['name'] ?? '');
			}

			$triggers_out[] = [
				'id' => $tid,
				'name' => (string) ($trigger['description'] ?? _s('Trigger %1$s', (string) $tid)),
				'priority' => (int) ($trigger['priority'] ?? 0),
				'hosts' => $host_names,
				'in_problem' => ((int) ($trigger['value'] ?? 0) === 1),
				'has_problem_in_window' => isset($window_problem_ids[$tid])
			];
		}

		return [
			'groups' => $groups_out,
			'hosts' => $hosts_out,
			'triggers' => $triggers_out,
			'severity' => $severities,
			'debug' => $debug,
			'window' => [
				'from' => $from_ts,
				'to' => $to_ts
			]
		];
	}

	private function buildCalculationPayload(): array {
		$period = (string) $this->getInput('period', '30d');
		$from_input = (string) $this->getInput('from', '');
		$to_input = (string) $this->getInput('to', '');
		$business_mode = (string) $this->getInput('business_mode', 'business_mf_8_18');
		$business_start = (string) $this->getInput('business_start', '08:00');
		$business_end = (string) $this->getInput('business_end', '18:00');
		$exclude_maintenance = ((int) $this->getInput('exclude_maintenance', '1') === 1);
		$slo_target = max(0.0, min(100.0, (float) $this->getInput('slo_target', '99.9')));

		[$from_ts, $to_ts] = $this->resolveWindow($period, $from_input, $to_input);
		$window = ['s' => $from_ts, 'e' => $to_ts];

		$groupids = $this->parseIds((string) $this->getInput('groupids', ''));
		$hostids = $this->parseIds((string) $this->getInput('hostids', ''));
		$requested_triggerids = $this->parseIds((string) $this->getInput('triggerids', ''));
		// Severity filter temporarily disabled by product decision.
		$severity_explicit = false;
		$severities = [];
		$exclude_triggerids = array_flip($this->parseIds((string) $this->getInput('exclude_triggerids', '')));

		$window_triggerids = $this->collectTriggerIdsWithIncidentsInWindow($from_ts, $to_ts, $groupids, $hostids, $severities);
		$triggerids = $window_triggerids;
		if (!$triggerids && ($groupids || $hostids || $severities || $requested_triggerids)) {
			$triggerids = $this->fallbackTriggerIdsFromProblemsScreenStyle($groupids, $hostids, $severities);
		}
		if ($requested_triggerids) {
			$requested_set = array_flip($requested_triggerids);
			$triggerids = array_values(array_filter($triggerids, static function(int $tid) use ($requested_set): bool {
				return isset($requested_set[$tid]);
			}));
		}

		if (!$triggerids && ($groupids || $hostids || $severities || $requested_triggerids)) {
			$triggerids = $this->fallbackTriggerIdsFromProblems($groupids, $hostids, $severities);
		}
		if (!$triggerids && ($groupids || $hostids || $severities || $requested_triggerids)) {
			$triggerids = $this->fallbackTriggerIdsFromEvents($from_ts, $to_ts, $groupids, $hostids, $severities);
		}

		$resolved = $this->resolveEntities($groupids, $hostids, $triggerids, $severities);
		$triggerids = $resolved['triggerids'];
		$hostids = $resolved['hostids'];
		$trigger_map = $resolved['trigger_map'];

		if ($requested_triggerids) {
			$requested_set = array_flip($requested_triggerids);
			$triggerids = array_values(array_filter($triggerids, static function(int $tid) use ($requested_set): bool {
				return isset($requested_set[$tid]);
			}));
			$trigger_map = array_filter($trigger_map, static function(string $tid) use ($requested_set): bool {
				return isset($requested_set[(int) $tid]);
			}, ARRAY_FILTER_USE_KEY);
		}

		if (!$triggerids) {
			return $this->buildEmptyPayload($from_ts, $to_ts, $slo_target, $groupids, $hostids, $severities, $business_mode, $business_start, $business_end, $exclude_maintenance, $exclude_triggerids);
		}

		$incidents = $this->buildIncidentIntervals($triggerids, $from_ts, $to_ts, $exclude_triggerids);

		$business_intervals = $this->buildBusinessIntervals($from_ts, $to_ts, $business_mode, $business_start, $business_end);
		$denominator_intervals = $this->intersectLists([$window], $business_intervals);

		if ($exclude_maintenance && $hostids) {
			$maintenance_intervals = $this->getMaintenanceIntervals($hostids, $from_ts, $to_ts);
			$denominator_intervals = $this->subtractList($denominator_intervals, $maintenance_intervals);
		}

		$downtime_intervals = [];
		$per_trigger_downtime = [];
		$per_trigger_last_start = [];
		$timeline_rows = [];
		foreach ($incidents as $incident) {
			$overlaps = $this->intersectLists([['s' => $incident['s'], 'e' => $incident['e']]], $denominator_intervals);
			if (!$overlaps) {
				continue;
			}
			$effective = 0;
			$effective_start = null;
			$effective_end = null;
			foreach ($overlaps as $ov) {
				$downtime_intervals[] = $ov;
				$effective += ($ov['e'] - $ov['s']);
				$effective_start = $effective_start === null ? (int) $ov['s'] : min($effective_start, (int) $ov['s']);
				$effective_end = $effective_end === null ? (int) $ov['e'] : max($effective_end, (int) $ov['e']);
			}

			$tid = (int) $incident['triggerid'];
			$per_trigger_downtime[$tid] = ($per_trigger_downtime[$tid] ?? 0) + $effective;
			$start_ts = (int) ($effective_start ?? $incident['s']);
			if (!isset($per_trigger_last_start[$tid]) || $start_ts > $per_trigger_last_start[$tid]) {
				$per_trigger_last_start[$tid] = $start_ts;
			}

			$timeline_rows[] = [
				'triggerid' => $tid,
				'trigger_name' => $trigger_map[$tid]['name'] ?? _s('Trigger %1$s', (string) $tid),
				'host' => $trigger_map[$tid]['host'] ?? '-',
				'severity' => (int) ($trigger_map[$tid]['severity'] ?? $incident['severity'] ?? 0),
				'severity_label' => $this->severityName((int) ($trigger_map[$tid]['severity'] ?? $incident['severity'] ?? 0)),
				'start' => (int) $incident['s'],
				'end' => (int) $incident['e'],
				'effective_start' => (int) ($effective_start ?? $incident['s']),
				'effective_end' => (int) ($effective_end ?? $incident['e']),
				'status' => $incident['e'] >= $to_ts ? 'PROBLEM' : 'RESOLVED',
				'duration_seconds' => (int) max(0, $effective),
				'duration' => $this->fmtDuration((int) $effective)
			];
		}

		$downtime_intervals = $this->mergeIntervals($downtime_intervals);
		$total_window = max(1, $this->sumIntervals($denominator_intervals));
		$total_downtime = $this->sumIntervals($downtime_intervals);
		// Sum of incident downtime (can exceed window when incidents overlap).
		$total_downtime_sum = (int) array_sum($per_trigger_downtime);
		$total_uptime = max(0, $total_window - $total_downtime);
		$availability = ($total_uptime / $total_window) * 100;
		$gap = $availability - $slo_target;

		$mttr_samples = array_column($timeline_rows, 'duration_seconds');
		$mttr_seconds = $mttr_samples ? (int) round(array_sum($mttr_samples) / max(1, count($mttr_samples))) : 0;

		arsort($per_trigger_downtime);
		$impact_map = $this->buildDependencyImpactMap(array_map('intval', array_keys($per_trigger_downtime)));
		$host_impact_map = [];
		$top_triggers = [];
		foreach (array_slice($per_trigger_downtime, 0, 12, true) as $tid => $seconds) {
			$impact = (int) ($impact_map[$tid]['risk_score'] ?? 0);
			$host_name = (string) ($trigger_map[$tid]['host'] ?? '-');
			if ($host_name !== '' && $host_name !== '-') {
				if (!isset($host_impact_map[$host_name])) {
					$host_impact_map[$host_name] = [
						'host' => $host_name,
						'impact' => 0,
						'triggers' => 0,
						'downtime_seconds' => 0
					];
				}
				$host_impact_map[$host_name]['impact'] += $impact;
				$host_impact_map[$host_name]['triggers']++;
				$host_impact_map[$host_name]['downtime_seconds'] += (int) $seconds;
			}
			$top_triggers[] = [
				'triggerid' => (int) $tid,
				'name' => $trigger_map[$tid]['name'] ?? _s('Trigger %1$s', (string) $tid),
				'host' => $trigger_map[$tid]['host'] ?? '-',
				'start' => (int) ($per_trigger_last_start[$tid] ?? 0),
				'severity' => (int) ($trigger_map[$tid]['severity'] ?? 0),
				'severity_label' => $this->severityName((int) ($trigger_map[$tid]['severity'] ?? 0)),
				'impact' => $impact,
				'impact_formula' => (string) ($impact_map[$tid]['risk_formula'] ?? ''),
				'downtime_seconds' => (int) $seconds,
				'downtime' => $this->fmtDuration((int) $seconds)
			];
		}
		$hosts_impact = array_values($host_impact_map);
		usort($hosts_impact, static function(array $a, array $b): int {
			if ((int) $a['impact'] === (int) $b['impact']) {
				if ((int) $a['triggers'] === (int) $b['triggers']) {
					return ((int) $b['downtime_seconds']) <=> ((int) $a['downtime_seconds']);
				}
				return ((int) $b['triggers']) <=> ((int) $a['triggers']);
			}
			return ((int) $b['impact']) <=> ((int) $a['impact']);
		});
		$hosts_impact = array_slice($hosts_impact, 0, 8);

		usort($timeline_rows, static function(array $a, array $b): int {
			return $b['start'] <=> $a['start'];
		});

		$daily = $this->buildDailySeries($from_ts, $to_ts, $denominator_intervals, $downtime_intervals);
		$heatmap = $this->buildHeatmap($timeline_rows);
		$timeline_limited = array_slice($timeline_rows, 0, 200);

		$risk = 'Low';
		if ($availability < ($slo_target - 1.0) || count($timeline_rows) >= 10) {
			$risk = 'High';
		}
		elseif ($availability < $slo_target || count($timeline_rows) >= 4) {
			$risk = 'Medium';
		}

		return [
			'summary' => [
				'window_from' => $from_ts,
				'window_to' => $to_ts,
				'window_label' => $this->fmtDuration($total_window),
				'incidents' => count($timeline_rows),
				'availability' => round($availability, 4),
				'slo_target' => $slo_target,
				'slo_gap' => round($gap, 4),
				'downtime_seconds' => (int) $total_downtime_sum,
				'downtime' => $this->fmtDuration((int) $total_downtime_sum),
				'downtime_union_seconds' => (int) $total_downtime,
				'downtime_union' => $this->fmtDuration((int) $total_downtime),
				'uptime' => $this->fmtDuration((int) $total_uptime),
				'mttr' => $this->fmtDuration($mttr_seconds),
				'risk' => $risk
			],
			'filters' => [
				'groupids' => $groupids,
				'hostids' => $hostids,
				'triggerids' => $triggerids,
				'severity' => $severities,
				'exclude_triggerids' => array_map('intval', array_keys($exclude_triggerids)),
				'business_mode' => $business_mode,
				'business_start' => $business_start,
				'business_end' => $business_end,
				'exclude_maintenance' => $exclude_maintenance
			],
			'executive' => [
				'current_incident_risk' => $risk,
				'sla_achieved' => round($availability, 3),
				'downtime_window' => $this->fmtDuration((int) $total_downtime_sum),
				'incidents_this_window' => count($timeline_rows),
				'services_impacted' => count($top_triggers)
			],
			'top_triggers' => $top_triggers,
			'hosts_impact' => $hosts_impact,
			'daily' => $daily,
			'heatmap' => $heatmap,
			'timeline' => $timeline_limited,
			'timeline_total' => count($timeline_rows)
		];
	}

	private function parseIds(string $raw): array {
		if ($raw === '') {
			return [];
		}
		$ids = [];
		foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
			$n = (int) trim($part);
			if ($n > 0) {
				$ids[$n] = true;
			}
		}
		return array_map('intval', array_keys($ids));
	}

	private function parseSeverities(string $raw): array {
		if ($raw === '') {
			return [];
		}
		$values = [];
		foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
			$part = trim($part);
			if ($part === '' || !is_numeric($part)) {
				continue;
			}
			$values[] = (int) $part;
		}
		$out = [];
		foreach ($values as $value) {
			if ($value >= 0 && $value <= 5) {
				$out[$value] = $value;
			}
		}
		return array_values($out);
	}

	private function normalizeSeverityFilter(bool $severity_explicit, array $severities): array {
		if (!$severity_explicit) {
			return [false, []];
		}

		$severities = array_values(array_unique(array_filter(array_map('intval', $severities), static function(int $v): bool {
			return $v >= 0 && $v <= 5;
		})));

		if (!$severities || count($severities) >= 6) {
			return [false, []];
		}

		sort($severities);
		return [true, $severities];
	}

	private function resolveWindow(string $period, string $from_input, string $to_input): array {
		$now = time();
		$from = strtotime($from_input);
		$to = strtotime($to_input);

		if ($from !== false && $to !== false && $to > $from) {
			return [(int) $from, (int) $to];
		}

		$presets = [
			'1h' => 3600,
			'6h' => 21600,
			'24h' => 86400,
			'7d' => 604800,
			'30d' => 2592000,
			'90d' => 7776000,
			'365d' => 31536000
		];
		$dur = $presets[$period] ?? $presets['30d'];
		return [$now - $dur, $now];
	}

	private function resolveEntities(array $groupids, array $hostids, array $triggerids, array $severities): array {
		$resolved_hostids = $hostids;
		if (!$resolved_hostids && $groupids) {
			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids,
				'monitored_hosts' => true
			]);
			$resolved_hostids = array_map('intval', array_column($hosts, 'hostid'));
		}

		$trigger_map = [];
		$resolved_triggerids = $triggerids;
		$trigger_params = [
			'output' => ['triggerid', 'description', 'priority'],
			'expandDescription' => true,
			'selectHosts' => ['hostid', 'name'],
			'preservekeys' => false,
			'limit' => 5000
		];
		if ($severities) {
			$trigger_params['filter'] = ['priority' => $severities];
		}

		if (!$resolved_triggerids) {
			if ($resolved_hostids) {
				$trigger_params['hostids'] = $resolved_hostids;
			}
			elseif ($groupids) {
				$trigger_params['groupids'] = $groupids;
			}
		}
		else {
			$trigger_params['triggerids'] = $resolved_triggerids;
		}

		$triggers = API::Trigger()->get($trigger_params);
		foreach ($triggers as $trigger) {
			$tid = (int) $trigger['triggerid'];
			$resolved_triggerids[$tid] = $tid;
			if (!empty($trigger['hosts'])) {
				foreach ($trigger['hosts'] as $host) {
					$hid = (int) ($host['hostid'] ?? 0);
					if ($hid > 0) {
						$resolved_hostids[$hid] = $hid;
					}
				}
			}
			$trigger_map[$tid] = [
				'name' => (string) ($trigger['description'] ?? _s('Trigger %1$s', (string) $tid)),
				'host' => !empty($trigger['hosts'][0]['name']) ? (string) $trigger['hosts'][0]['name'] : '-',
				'severity' => (int) ($trigger['priority'] ?? 0)
			];
		}

		return [
			'hostids' => array_values(array_unique(array_map('intval', $resolved_hostids))),
			'triggerids' => array_values(array_unique(array_map('intval', $resolved_triggerids))),
			'trigger_map' => $trigger_map
		];
	}

	private function findTriggersWithProblemsInWindow(array $triggerids, int $from_ts, int $to_ts): array {
		if (!$triggerids) {
			return [];
		}
		$out = [];
		$chunks = array_chunk(array_values(array_unique(array_map('intval', $triggerids))), 800);
		foreach ($chunks as $chunk) {
			$events = API::Event()->get([
				'output' => ['objectid', 'value', 'clock'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => $chunk,
				'time_from' => $from_ts,
				'time_till' => $to_ts,
				'sortfield' => ['clock'],
				'sortorder' => 'DESC',
				'limit' => 50000,
				'preservekeys' => false
			]);

			foreach ($events as $event) {
				$tid = (int) ($event['objectid'] ?? 0);
				if ($tid > 0) {
					$out[$tid] = true;
				}
			}

			// Incidents crossing the selected window: detect state at window start.
			$before = API::Event()->get([
				'output' => ['objectid', 'value', 'clock', 'eventid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'objectids' => $chunk,
				'time_till' => $from_ts,
				'sortfield' => ['clock', 'eventid'],
				'sortorder' => 'DESC',
				'limit' => 50000,
				'preservekeys' => false
			]);

			$seen = [];
			foreach ($before as $event) {
				$tid = (int) ($event['objectid'] ?? 0);
				if ($tid <= 0 || isset($seen[$tid])) {
					continue;
				}
				$seen[$tid] = true;
				if ((int) ($event['value'] ?? 0) === 1) {
					$out[$tid] = true;
				}
			}
		}

		return $out;
	}

	private function fallbackTriggerIdsFromEvents(int $from_ts, int $to_ts, array $groupids, array $hostids, array $severities): array {
		$events = API::Event()->get([
			'output' => ['objectid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'time_from' => $from_ts,
			'time_till' => $to_ts,
			'sortfield' => ['clock'],
			'sortorder' => 'DESC',
			'limit' => 100000,
			'preservekeys' => false
		]);
		if (!$events) {
			return [];
		}

		$event_triggerids = array_values(array_unique(array_filter(array_map(static function(array $event): int {
			return (int) ($event['objectid'] ?? 0);
		}, $events))));
		if (!$event_triggerids) {
			return [];
		}

		$params = [
			'output' => ['triggerid'],
			'triggerids' => $event_triggerids,
			'preservekeys' => false
		];
		if ($hostids) {
			$params['hostids'] = $hostids;
		}
		if ($groupids) {
			$params['groupids'] = $groupids;
		}
		if ($severities) {
			$params['filter'] = ['priority' => $severities];
		}

		$triggers = API::Trigger()->get($params);
		return array_values(array_unique(array_map('intval', array_column($triggers, 'triggerid'))));
	}

	private function collectTriggerIdsWithIncidentsInWindow(int $from_ts, int $to_ts, array $groupids, array $hostids, array $severities): array {
		$trace = [];
		return $this->collectTriggerIdsWithIncidentsInWindowTrace($from_ts, $to_ts, $groupids, $hostids, $severities, $trace);
	}

	private function collectTriggerIdsWithIncidentsInWindowTrace(int $from_ts, int $to_ts, array $groupids, array $hostids, array $severities, array &$trace): array {
		// 1) Build candidate trigger set from current filters.
		$candidate_params = [
			'output' => ['triggerid'],
			'preservekeys' => false,
			'limit' => 20000
		];
		if ($groupids) {
			$candidate_params['groupids'] = $groupids;
		}
		if ($hostids) {
			$candidate_params['hostids'] = $hostids;
		}
		if ($severities) {
			$candidate_params['filter'] = ['priority' => $severities];
		}
		$trace['candidate_params'] = $candidate_params;

		$candidates = API::Trigger()->get($candidate_params);
		$trace['candidate_count'] = is_array($candidates) ? count($candidates) : 0;
		if (!$candidates) {
			$trace['stage'] = 'no_candidates';
			return [];
		}
		$candidate_triggerids = array_values(array_unique(array_map('intval', array_column($candidates, 'triggerid'))));
		$trace['candidate_triggerids_count'] = count($candidate_triggerids);
		if (!$candidate_triggerids) {
			$trace['stage'] = 'no_candidate_ids';
			return [];
		}

		// 2) Keep only triggers that have incident activity in the selected window
		// (including incidents that started before window and remained active).
		$in_window = $this->findTriggersWithProblemsInWindow($candidate_triggerids, $from_ts, $to_ts);
		$trace['in_window_count'] = count($in_window);
		if (!$in_window) {
			$trace['stage'] = 'no_incidents_in_window';
			return [];
		}

		$valid = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => array_keys($in_window),
			'preservekeys' => false
		]);
		$out = array_values(array_unique(array_map('intval', array_column($valid, 'triggerid'))));
		$trace['valid_count'] = count($out);
		$trace['sample_triggerids'] = array_slice($out, 0, 20);
		$trace['stage'] = 'ok';
		return $out;
	}

	private function fallbackTriggerIdsFromProblemsScreenStyle(array $groupids, array $hostids, array $severities): array {
		$trace = [];
		return $this->fallbackTriggerIdsFromProblemsScreenStyleTrace($groupids, $hostids, $severities, $trace);
	}

	private function fallbackTriggerIdsFromProblemsScreenStyleTrace(array $groupids, array $hostids, array $severities, array &$trace): array {
		$params = [
			'output' => ['eventid', 'objectid', 'clock', 'severity', 'cause_eventid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'recent' => true,
			'limit' => 1001,
			'preservekeys' => false
		];

		if ($groupids) {
			$params['groupids'] = $groupids;
		}
		if ($hostids) {
			$params['hostids'] = $hostids;
		}
		$trace['params'] = $params;

		$problems = API::Problem()->get($params);
		$trace['problems_count'] = is_array($problems) ? count($problems) : 0;
		if (!$problems) {
			$trace['stage'] = 'no_problems';
			return [];
		}

		$triggerids = [];
		foreach ($problems as $problem) {
			$sev = (int) ($problem['severity'] ?? -1);
			if ($severities && !in_array($sev, $severities, true)) {
				continue;
			}
			$tid = (int) ($problem['objectid'] ?? 0);
			if ($tid > 0) {
				$triggerids[$tid] = $tid;
			}
		}

		$out = array_values($triggerids);
		$trace['out_count'] = count($out);
		$trace['sample_triggerids'] = array_slice($out, 0, 20);
		$trace['stage'] = 'ok';
		return $out;
	}

	private function fallbackTriggerIdsFromProblems(array $groupids, array $hostids, array $severities): array {
		$params = [
			'output' => ['objectid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'recent' => true,
			'limit' => 100000,
			'preservekeys' => false
		];

		if ($groupids) {
			$params['groupids'] = $groupids;
		}
		if ($hostids) {
			$params['hostids'] = $hostids;
		}
		if ($severities) {
			$params['severities'] = $severities;
		}

		$problems = API::Problem()->get($params);
		if (!$problems) {
			return [];
		}

		$triggerids = array_values(array_unique(array_filter(array_map(static function(array $problem): int {
			return (int) ($problem['objectid'] ?? 0);
		}, $problems))));

		if (!$triggerids) {
			return [];
		}

		$valid = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => $triggerids,
			'preservekeys' => false
		]);

		return array_values(array_unique(array_map('intval', array_column($valid, 'triggerid'))));
	}


	private function buildEmptyPayload(int $from_ts, int $to_ts, float $slo_target, array $groupids, array $hostids, array $severities, string $business_mode, string $business_start, string $business_end, bool $exclude_maintenance, array $exclude_triggerids): array {
		$den = max(1, $to_ts - $from_ts);
		return [
			'note' => _('No triggers found for selected filters.'),
			'summary' => [
				'window_from' => $from_ts,
				'window_to' => $to_ts,
				'window_label' => $this->fmtDuration($den),
				'incidents' => 0,
				'availability' => 100.0,
				'slo_target' => $slo_target,
				'slo_gap' => round(100.0 - $slo_target, 4),
				'downtime_seconds' => 0,
				'downtime' => $this->fmtDuration(0),
				'downtime_union_seconds' => 0,
				'downtime_union' => $this->fmtDuration(0),
				'uptime' => $this->fmtDuration($den),
				'mttr' => $this->fmtDuration(0),
				'risk' => 'Low'
			],
			'filters' => [
				'groupids' => $groupids,
				'hostids' => $hostids,
				'triggerids' => [],
				'severity' => $severities,
				'exclude_triggerids' => array_map('intval', array_keys($exclude_triggerids)),
				'business_mode' => $business_mode,
				'business_start' => $business_start,
				'business_end' => $business_end,
				'exclude_maintenance' => $exclude_maintenance
			],
			'executive' => [
				'current_incident_risk' => 'Low',
				'sla_achieved' => 100.0,
				'downtime_window' => $this->fmtDuration(0),
				'incidents_this_window' => 0,
				'services_impacted' => 0
			],
			'top_triggers' => [],
			'hosts_impact' => [],
			'daily' => [],
			'heatmap' => [
				'week_labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
				'hour_labels' => ['0h', '1h', '2h', '3h', '4h', '5h', '6h', '7h', '8h', '9h', '10h', '11h', '12h', '13h', '14h', '15h', '16h', '17h', '18h', '19h', '20h', '21h', '22h', '23h'],
				'matrix' => array_fill(0, 7, array_fill(0, 24, 0))
			],
			'timeline' => []
		];
	}

	private function buildDependencyImpactMap(array $triggerids): array {
		$triggerids = array_values(array_unique(array_map('intval', $triggerids)));
		if (!$triggerids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'priority', 'value'],
			'selectDependencies' => ['triggerid'],
			'preservekeys' => true,
			'triggerids' => $triggerids
		]);
		if (!$triggers) {
			return [];
		}

		$children_map = [];
		foreach ($triggers as $id => $trigger) {
			$children_map[(int) $id] = [];
		}

		foreach ($triggers as $id => $trigger) {
			$child_id = (int) $id;
			foreach (($trigger['dependencies'] ?? []) as $dep) {
				$parent_id = (int) ($dep['triggerid'] ?? 0);
				if ($parent_id > 0 && isset($children_map[$parent_id])) {
					$children_map[$parent_id][] = $child_id;
				}
			}
		}

		$parents_in_scope = [];
		$children_count = [];
		foreach (array_keys($triggers) as $id) {
			$tid = (int) $id;
			$parents_in_scope[$tid] = 0;
			$children_count[$tid] = count(array_unique($children_map[$tid] ?? []));
		}

		foreach ($triggers as $id => $trigger) {
			foreach (($trigger['dependencies'] ?? []) as $dep) {
				$parent_id = (int) ($dep['triggerid'] ?? 0);
				$child_id = (int) $id;
				if ($parent_id > 0 && isset($triggers[(string) $parent_id], $parents_in_scope[$child_id])) {
					$parents_in_scope[$child_id]++;
				}
			}
		}

		$weights = [1, 2, 3, 5, 8, 13];
		$out = [];
		foreach ($triggers as $id => $trigger) {
			$tid = (int) $id;
			$severity = (int) ($trigger['priority'] ?? 0);
			$is_problem = ((int) ($trigger['value'] ?? 0) === TRIGGER_VALUE_TRUE);
			$is_root = (($parents_in_scope[$tid] ?? 0) === 0);
			$fanout = (int) ($children_count[$tid] ?? 0);

			$severity_part = (int) ($weights[$severity] ?? 1);
			$problem_part = $is_problem ? 8 : 0;
			$fanout_part = min(20, $fanout * 2);
			$root_bonus = ($is_root && $severity >= 4) ? 4 : 0;
			$risk = $severity_part + $problem_part + $fanout_part + $root_bonus;

			$out[$tid] = [
				'risk_score' => $risk,
				'risk_formula' => sprintf(
					'severity(%d) + problem(%d) + fanout(%d) + root_bonus(%d) = %d',
					$severity_part,
					$problem_part,
					$fanout_part,
					$root_bonus,
					$risk
				)
			];
		}

		return $out;
	}


	private function buildIncidentIntervals(array $triggerids, int $from_ts, int $to_ts, array $exclude_triggerids): array {
		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'value', 'severity', 'name'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => $triggerids,
			'time_from' => max(0, $from_ts - (90 * 86400)),
			'time_till' => $to_ts,
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'ASC',
			'limit' => 200000,
			'preservekeys' => false
		]);

		$pending = [];
		$incidents = [];
		foreach ($events as $event) {
			$tid = (int) ($event['objectid'] ?? 0);
			if (isset($exclude_triggerids[$tid])) {
				continue;
			}
			$clock = (int) ($event['clock'] ?? 0);
			$value = (int) ($event['value'] ?? 0);

			if ($value === 1) {
				// Keep only one open problem interval per trigger to avoid
				// duplicated starts inflating downtime when repeated PROBLEM
				// events appear without an intermediate recovery.
				if (!isset($pending[$tid])) {
					$pending[$tid] = [
						'clock' => $clock,
						'severity' => (int) ($event['severity'] ?? 0),
						'name' => (string) ($event['name'] ?? '')
					];
				}
				continue;
			}

			if (isset($pending[$tid])) {
				$start = $pending[$tid];
				$incidents[] = [
					'triggerid' => $tid,
					's' => (int) $start['clock'],
					'e' => $clock,
					'severity' => (int) $start['severity'],
					'name' => (string) $start['name']
				];
				unset($pending[$tid]);
			}
		}

		foreach ($pending as $tid => $start) {
			if (isset($exclude_triggerids[(int) $tid])) {
				continue;
			}
			$incidents[] = [
				'triggerid' => (int) $tid,
				's' => (int) $start['clock'],
				'e' => $to_ts,
				'severity' => (int) ($start['severity'] ?? 0),
				'name' => (string) ($start['name'] ?? '')
			];
		}

		$clipped = [];
		foreach ($incidents as $incident) {
			$s = max((int) $incident['s'], $from_ts);
			$e = min((int) $incident['e'], $to_ts);
			if ($e > $s) {
				$incident['s'] = $s;
				$incident['e'] = $e;
				$clipped[] = $incident;
			}
		}

		return $clipped;
	}

	private function buildBusinessIntervals(int $from_ts, int $to_ts, string $mode, string $start_hhmm, string $end_hhmm): array {
		if ($mode === 'all_time') {
			return [['s' => $from_ts, 'e' => $to_ts]];
		}

		[$start_h, $start_m] = $this->parseHHMM($start_hhmm, [8, 0]);
		[$end_h, $end_m] = $this->parseHHMM($end_hhmm, [18, 0]);
		$cursor = strtotime(date('Y-m-d 00:00:00', $from_ts));
		$out = [];

		while ($cursor < $to_ts) {
			$weekday = (int) date('w', $cursor);
			$is_business_day = ($mode === 'custom_daily') ? true : ($weekday >= 1 && $weekday <= 5);
			if ($is_business_day) {
				$open = $cursor + ($start_h * 3600) + ($start_m * 60);
				$close = $cursor + ($end_h * 3600) + ($end_m * 60);
				if ($close > $open) {
					$s = max($open, $from_ts);
					$e = min($close, $to_ts);
					if ($e > $s) {
						$out[] = ['s' => $s, 'e' => $e];
					}
				}
			}
			$cursor += 86400;
		}

		return $this->mergeIntervals($out);
	}

	private function parseHHMM(string $value, array $fallback): array {
		if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
			$hour = (int) $matches[1];
			$minute = (int) $matches[2];
			if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
				return [$hour, $minute];
			}
		}
		return $fallback;
	}

	private function getMaintenanceIntervals(array $hostids, int $from_ts, int $to_ts): array {
		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'hostids' => $hostids,
			'selectHostGroups' => ['groupid']
		]);

		$groupids = [];
		foreach ($hosts as $host) {
			foreach (($host['hostgroups'] ?? []) as $group) {
				$groupids[(int) $group['groupid']] = true;
			}
		}

		$base = [
			'output' => ['maintenanceid', 'active_since', 'active_till'],
			'selectTimeperiods' => ['timeperiod_type', 'period', 'start_date', 'start_time', 'every', 'day', 'dayofweek', 'month'],
			'preservekeys' => true
		];

		$all = [];
		$by_host = API::Maintenance()->get(array_merge($base, ['hostids' => $hostids]));
		$all = is_array($by_host) ? $by_host : [];

		if ($groupids) {
			$by_group = API::Maintenance()->get(array_merge($base, ['groupids' => array_keys($groupids)]));
			if (is_array($by_group)) {
				$all = $all + $by_group;
			}
		}

		$intervals = [];
		foreach ($all as $maintenance) {
			$since = (int) ($maintenance['active_since'] ?? 0);
			$till = (int) ($maintenance['active_till'] ?? 0);
			if ($till < $from_ts || $since > $to_ts) {
				continue;
			}

			$segments = $this->expandMaintenanceTimeperiods(
				$maintenance,
				$since,
				$till,
				max($since, $from_ts),
				min($till, $to_ts)
			);

			foreach ($segments as $segment) {
				if ($segment[1] > $segment[0]) {
					$intervals[] = ['s' => (int) $segment[0], 'e' => (int) $segment[1]];
				}
			}
		}

		return $this->mergeIntervals($intervals);
	}

	private function expandMaintenanceTimeperiods(array $maintenance, int $m_since, int $m_till, int $clip_since, int $clip_till): array {
		$timeperiods = $maintenance['timeperiods'] ?? [];
		if (empty($timeperiods)) {
			return [[$clip_since, $clip_till]];
		}

		$type_onetime = defined('TIMEPERIOD_TYPE_ONETIME') ? (int) constant('TIMEPERIOD_TYPE_ONETIME') : 0;
		$type_daily = defined('TIMEPERIOD_TYPE_DAILY') ? (int) constant('TIMEPERIOD_TYPE_DAILY') : 2;
		$type_weekly = defined('TIMEPERIOD_TYPE_WEEKLY') ? (int) constant('TIMEPERIOD_TYPE_WEEKLY') : 3;
		$type_monthly = defined('TIMEPERIOD_TYPE_MONTHLY') ? (int) constant('TIMEPERIOD_TYPE_MONTHLY') : 4;

		$segments = [];
		foreach ($timeperiods as $timeperiod) {
			$type = (int) ($timeperiod['timeperiod_type'] ?? 0);
			$period = max(60, (int) ($timeperiod['period'] ?? 3600));

			switch ($type) {
				case $type_onetime:
					$start = (int) ($timeperiod['start_date'] ?? $m_since);
					$end = $start + $period;
					if ($end >= $clip_since && $start <= $clip_till) {
						$segments[] = [max($start, $clip_since), min($end, $clip_till)];
					}
					break;
				case $type_daily:
					$start_time = (int) ($timeperiod['start_time'] ?? 0);
					$every = max(1, (int) ($timeperiod['every'] ?? 1));
					$day0 = (int) floor($m_since / 86400) * 86400;
					for ($t = $day0; $t <= $clip_till; $t += (86400 * $every)) {
						$occ_start = $t + $start_time;
						$occ_end = $occ_start + $period;
						if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
							$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
						}
					}
					break;
				case $type_weekly:
					$start_time = (int) ($timeperiod['start_time'] ?? 0);
					$dayofweek = (int) ($timeperiod['dayofweek'] ?? 1);
					$max_days = (int) ceil(($clip_till - $clip_since) / 86400) + 7;
					for ($d = 0; $d < $max_days; $d++) {
						$t = $clip_since + ($d * 86400);
						$week_day = (int) date('w', $t);
						if (!($dayofweek & (1 << $week_day))) {
							continue;
						}
						$day_start = (int) floor($t / 86400) * 86400;
						$occ_start = $day_start + $start_time;
						$occ_end = $occ_start + $period;
						if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
							$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
						}
					}
					break;
				case $type_monthly:
					$start_time = (int) ($timeperiod['start_time'] ?? 0);
					$day = (int) ($timeperiod['day'] ?? 1);
					$month_mask = (int) ($timeperiod['month'] ?? 4095);
					$year = (int) date('Y', $clip_since);
					$month = (int) date('n', $clip_since);
					$end_year = (int) date('Y', $clip_till);
					$end_month = (int) date('n', $clip_till);
					while ($year < $end_year || ($year === $end_year && $month <= $end_month)) {
						if ($month_mask & (1 << ($month - 1))) {
							$days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
							$d = $day > 0 ? min($day, $days_in_month) : 1;
							$occ_start = mktime(0, 0, 0, $month, $d, $year) + $start_time;
							$occ_end = $occ_start + $period;
							if ($occ_end >= $clip_since && $occ_start <= $clip_till) {
								$segments[] = [max($occ_start, $clip_since), min($occ_end, $clip_till)];
							}
						}
						$month++;
						if ($month > 12) {
							$month = 1;
							$year++;
						}
					}
					break;
				default:
					$segments[] = [$clip_since, $clip_till];
			}
		}

		if (!$segments) {
			$segments[] = [$clip_since, $clip_till];
		}

		return $segments;
	}

	private function buildDailySeries(int $from_ts, int $to_ts, array $denominator, array $downtime): array {
		$out = [];
		$cursor = strtotime(date('Y-m-d 00:00:00', $from_ts));
		while ($cursor < $to_ts) {
			$day_start = max($cursor, $from_ts);
			$day_end = min($cursor + 86400, $to_ts);
			if ($day_end <= $day_start) {
				$cursor += 86400;
				continue;
			}

			$day_window = [['s' => $day_start, 'e' => $day_end]];
			$day_den = $this->sumIntervals($this->intersectLists($denominator, $day_window));
			$day_down = $this->sumIntervals($this->intersectLists($downtime, $day_window));
			$day_avail = $day_den > 0 ? max(0.0, min(100.0, (($day_den - $day_down) / $day_den) * 100.0)) : 100.0;

			$out[] = [
				'date' => date('Y-m-d', $day_start),
				'availability' => round($day_avail, 4),
				'downtime_seconds' => (int) $day_down
			];
			$cursor += 86400;
		}

		return $out;
	}

	private function buildHeatmap(array $timeline_rows): array {
		$matrix = [];
		for ($w = 0; $w < 7; $w++) {
			$matrix[$w] = array_fill(0, 24, 0);
		}

		foreach ($timeline_rows as $row) {
			$start = (int) ($row['start'] ?? 0);
			$w = (int) date('w', $start);
			$h = (int) date('G', $start);
			$matrix[$w][$h]++;
		}

		return [
			'week_labels' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
			'hour_labels' => ['0h', '1h', '2h', '3h', '4h', '5h', '6h', '7h', '8h', '9h', '10h', '11h', '12h', '13h', '14h', '15h', '16h', '17h', '18h', '19h', '20h', '21h', '22h', '23h'],
			'matrix' => $matrix
		];
	}

	private function mergeIntervals(array $intervals): array {
		if (!$intervals) {
			return [];
		}

		usort($intervals, static function(array $a, array $b): int {
			return ((int) $a['s']) <=> ((int) $b['s']);
		});

		$out = [];
		foreach ($intervals as $interval) {
			$cur = ['s' => (int) $interval['s'], 'e' => (int) $interval['e']];
			if ($cur['e'] <= $cur['s']) {
				continue;
			}

			if (!$out) {
				$out[] = $cur;
				continue;
			}

			$last_idx = count($out) - 1;
			if ($cur['s'] <= $out[$last_idx]['e']) {
				$out[$last_idx]['e'] = max($out[$last_idx]['e'], $cur['e']);
			}
			else {
				$out[] = $cur;
			}
		}

		return $out;
	}

	private function intersectLists(array $a, array $b): array {
		$out = [];
		foreach ($a as $ia) {
			foreach ($b as $ib) {
				$s = max((int) $ia['s'], (int) $ib['s']);
				$e = min((int) $ia['e'], (int) $ib['e']);
				if ($e > $s) {
					$out[] = ['s' => $s, 'e' => $e];
				}
			}
		}
		return $this->mergeIntervals($out);
	}

	private function subtractList(array $base, array $minus): array {
		$base = $this->mergeIntervals($base);
		$minus = $this->mergeIntervals($minus);
		if (!$base || !$minus) {
			return $base;
		}

		$out = [];
		foreach ($base as $segment) {
			$parts = [$segment];
			foreach ($minus as $remove) {
				$next = [];
				foreach ($parts as $part) {
					if ($remove['e'] <= $part['s'] || $remove['s'] >= $part['e']) {
						$next[] = $part;
						continue;
					}
					if ($remove['s'] > $part['s']) {
						$next[] = ['s' => $part['s'], 'e' => $remove['s']];
					}
					if ($remove['e'] < $part['e']) {
						$next[] = ['s' => $remove['e'], 'e' => $part['e']];
					}
				}
				$parts = $next;
				if (!$parts) {
					break;
				}
			}
			$out = array_merge($out, $parts);
		}

		return $this->mergeIntervals($out);
	}

	private function sumIntervals(array $intervals): int {
		$sum = 0;
		foreach ($intervals as $interval) {
			$sum += max(0, ((int) $interval['e']) - ((int) $interval['s']));
		}
		return $sum;
	}

	private function fmtDuration(int $seconds): string {
		$seconds = max(0, $seconds);
		$days = (int) floor($seconds / 86400);
		$hours = (int) floor(($seconds % 86400) / 3600);
		$minutes = (int) floor(($seconds % 3600) / 60);
		$secs = $seconds % 60;

		$parts = [];
		if ($days > 0) {
			$parts[] = $days.'d';
		}
		if ($hours > 0) {
			$parts[] = $hours.'h';
		}
		if ($minutes > 0) {
			$parts[] = $minutes.'m';
		}
		if (!$parts) {
			$parts[] = $secs.'s';
		}

		return implode(' ', array_slice($parts, 0, 2));
	}

	private function severityName(int $severity): string {
		$names = [
			0 => 'Not classified',
			1 => 'Information',
			2 => 'Warning',
			3 => 'Average',
			4 => 'High',
			5 => 'Disaster'
		];
		return $names[$severity] ?? 'Unknown';
	}

	private function encodeJson(array $payload): string {
		$json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		return $json !== false ? $json : '{"success":false,"error":{"title":"Error","message":"JSON encode failed"}}';
	}
}
