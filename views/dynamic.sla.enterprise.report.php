<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/html.inc.php';

$persona = strtolower((string) ($data['persona'] ?? 'noc'));
if (!in_array($persona, ['noc', 'gestao', 'diretoria'], true)) {
	$persona = 'noc';
}

$auto_print = (int) ($data['auto_print'] ?? 0);
$report = (array) ($data['report'] ?? []);
$meta = (array) ($data['meta'] ?? []);
$summary = (array) ($report['summary'] ?? []);
$executive = (array) ($report['executive'] ?? []);
$filters = (array) ($report['filters'] ?? []);
$top_triggers = (array) ($report['top_triggers'] ?? []);
$hosts_impact = (array) ($report['hosts_impact'] ?? []);
$timeline = (array) ($report['timeline'] ?? []);
$generated_at = (string) ($data['generated_at'] ?? '');
$timezone = (string) ($meta['timezone'] ?? date_default_timezone_get());
$report_id = (string) ($meta['report_id'] ?? '');
$scope = (array) ($meta['scope'] ?? []);

$fmt_pct = static function($v): string {
	if (!is_numeric($v)) {
		return '-';
	}
	return number_format((float) $v, 2) . '%';
};

$fmt_filters = static function(array $vals): string {
	if (!$vals) {
		return 'All';
	}
	return implode(', ', array_map('strval', $vals));
};

$to_human = static function($seconds): string {
	$s = (int) $seconds;
	if ($s <= 0) {
		return '0m';
	}
	$d = (int) floor($s / 86400);
	$h = (int) floor(($s % 86400) / 3600);
	$m = (int) floor(($s % 3600) / 60);
	if ($d > 0) {
		return $d.'d '.$h.'h '.$m.'m';
	}
	if ($h > 0) {
		return $h.'h '.$m.'m';
	}
	return $m.'m';
};

$pill_for_severity = static function($label): string {
	$v = strtolower((string) $label);
	if ($v === 'disaster') {
		return 'dse-rpt-pill dse-rpt-pill-risk';
	}
	if ($v === 'high') {
		return 'dse-rpt-pill dse-rpt-pill-warn';
	}
	if ($v === 'average' || $v === 'warning') {
		return 'dse-rpt-pill dse-rpt-pill-amber';
	}
	return 'dse-rpt-pill dse-rpt-pill-ok';
};

$status_pill = static function($status): string {
	return strtoupper((string) $status) === 'PROBLEM'
		? 'dse-rpt-pill dse-rpt-pill-risk'
		: 'dse-rpt-pill dse-rpt-pill-ok';
};

$window_from = isset($summary['window_from']) ? (int) $summary['window_from'] : 0;
$window_to = isset($summary['window_to']) ? (int) $summary['window_to'] : 0;
$window_label = '-';
if ($window_from > 0 && $window_to > 0) {
	$window_label = date('d/m/Y H:i', $window_from).' - '.date('d/m/Y H:i', $window_to);
}
$exclude_maintenance = ((int) ($filters['exclude_maintenance'] ?? 0) === 1) ? 'Yes' : 'No';

$availability = is_numeric($summary['availability'] ?? null) ? (float) $summary['availability'] : 0.0;
$slo_target = is_numeric($summary['slo_target'] ?? null) ? (float) $summary['slo_target'] : 99.9;
$incidents = (int) ($summary['incidents'] ?? 0);
$total_impact = (int) ($summary['total_impact'] ?? ($executive['total_impact_window'] ?? 0));
$risk_level = strtolower((string) ($executive['current_incident_risk'] ?? 'low'));
$risk_badge_class = 'dse-rpt-pill-ok';
if ($risk_level === 'high') {
	$risk_badge_class = 'dse-rpt-pill-risk';
}
elseif ($risk_level === 'medium') {
	$risk_badge_class = 'dse-rpt-pill-warn';
}

$services_impacted = (int) ($executive['services_impacted'] ?? 0);
$services_ok = max(0, count($hosts_impact) - $services_impacted);
$services_total = max(1, $services_ok + $services_impacted);
$ok_pct = ($services_ok / $services_total) * 100.0;
$risk_pct = ($services_impacted / $services_total) * 100.0;
$burn_rate = 0.0;
if ($window_from > 0 && $window_to > $window_from) {
	$window_days = max(1.0, ($window_to - $window_from) / 86400.0);
	$burn_rate = $incidents / $window_days;
}

$monthly_counts = [];
for ($i = 11; $i >= 0; $i--) {
	$ts = strtotime(date('Y-m-01 00:00:00').' -'.$i.' month');
	$key = date('Y-m', $ts);
	$monthly_counts[$key] = [
		'label' => date('M/y', $ts),
		'count' => 0
	];
}
foreach ($timeline as $row) {
	$start = isset($row['start']) ? (int) $row['start'] : 0;
	if ($start <= 0) {
		continue;
	}
	$key = date('Y-m', $start);
	if (array_key_exists($key, $monthly_counts)) {
		$monthly_counts[$key]['count']++;
	}
}
$monthly_max = 0;
foreach ($monthly_counts as $m) {
	$monthly_max = max($monthly_max, (int) $m['count']);
}
$monthly_max = max(1, $monthly_max);
$max_host_impact = 0;
foreach ($hosts_impact as $hrow) {
	$max_host_impact = max($max_host_impact, (int) ($hrow['impact'] ?? 0));
}
$max_host_impact = max(1, $max_host_impact);

$cover_meta_grid = (new CDiv([
	(new CDiv([new CTag('h4', true, _('SLA')), new CSpan($scope['groups'] ? $fmt_filters((array) $scope['groups']) : $fmt_filters((array) ($filters['groupids'] ?? [])))]))->addClass('dse-rpt-cover-card'),
	(new CDiv([new CTag('h4', true, _('Generated at')), new CSpan($generated_at)]))->addClass('dse-rpt-cover-card'),
	(new CDiv([new CTag('h4', true, _('Timezone')), new CSpan($timezone)]))->addClass('dse-rpt-cover-card'),
	(new CDiv([new CTag('h4', true, _('Author / Environment')), new CSpan((string) ($meta['author'] ?? 'NOC').' / '.(string) ($meta['environment'] ?? 'Production'))]))->addClass('dse-rpt-cover-card'),
	(new CDiv([new CTag('h4', true, _('Persona')), new CSpan(strtoupper($persona))]))->addClass('dse-rpt-cover-card'),
	(new CDiv([new CTag('h4', true, _('Report ID')), new CSpan($report_id)]))->addClass('dse-rpt-cover-card')
]))->addClass('dse-rpt-cover-grid');

$manager_view = (new CDiv([
	(new CDiv([
		(new CSpan(_('General status')))->addClass('dse-rpt-manager-status-label'),
		(new CSpan(ucfirst($risk_level)))->addClass('dse-rpt-pill '.$risk_badge_class)
	]))->addClass('dse-rpt-manager-status'),
	(new CTag('h3', true, _('Manager view')))->addClass('dse-rpt-manager-title'),
	(new CDiv([
		(new CSpan(_('SLO')))->addClass('dse-rpt-manager-label'),
		(new CDiv([
			(new CSpan())->addClass('dse-rpt-manager-fill dse-rpt-fill-green')->setAttribute('style', 'width:'.number_format(max(0.0, min(100.0, $slo_target)), 2, '.', '').'%'),
			(new CSpan(number_format($slo_target, 2).'%'))->addClass('dse-rpt-manager-value')
		]))->addClass('dse-rpt-manager-track')
	]))->addClass('dse-rpt-manager-row'),
	(new CDiv([
		(new CSpan(_('Global SLI')))->addClass('dse-rpt-manager-label'),
		(new CDiv([
			(new CSpan())->addClass('dse-rpt-manager-fill '.($availability >= $slo_target ? 'dse-rpt-fill-green' : 'dse-rpt-fill-red'))->setAttribute('style', 'width:'.number_format(max(0.0, min(100.0, $availability)), 2, '.', '').'%'),
			(new CSpan(number_format($availability, 2).'%'))->addClass('dse-rpt-manager-value')
		]))->addClass('dse-rpt-manager-track')
	]))->addClass('dse-rpt-manager-row'),
	(new CDiv([
		(new CSpan(_('Avg burn rate')))->addClass('dse-rpt-manager-label'),
		(new CDiv([
			(new CSpan())->addClass('dse-rpt-manager-fill '.($burn_rate > 2.0 ? 'dse-rpt-fill-red' : ($burn_rate > 1.0 ? 'dse-rpt-fill-amber' : 'dse-rpt-fill-green')))->setAttribute('style', 'width:'.number_format(max(0.0, min(100.0, $burn_rate * 20.0)), 2, '.', '').'%'),
			(new CSpan(number_format($burn_rate, 2).'/dia'))->addClass('dse-rpt-manager-value')
		]))->addClass('dse-rpt-manager-track')
	]))->addClass('dse-rpt-manager-row')
]))->addClass('dse-rpt-manager-strip');

$trend_grid = (new CDiv())->addClass('dse-rpt-cover-trend-grid');
foreach ($monthly_counts as $m) {
	$count = (int) $m['count'];
	$height = max(6.0, ($count / $monthly_max) * 100.0);
	$bar_cls = $count > 0 ? ($count >= ceil($monthly_max * 0.75) ? 'dse-rpt-cover-trend-bar-risk' : 'dse-rpt-cover-trend-bar-ok') : 'dse-rpt-cover-trend-bar-neutral';
	$trend_grid->addItem(
		(new CDiv([
			(new CTag('small', true, (string) $count))->addClass('dse-rpt-cover-trend-top'),
			(new CDiv([
				(new CSpan())->addClass('dse-rpt-cover-trend-bar '.$bar_cls)->setAttribute('style', 'height:'.number_format($height, 2, '.', '').'%')
			]))->addClass('dse-rpt-cover-trend-track'),
			(new CTag('small', true, (string) $m['label']))->addClass('dse-rpt-cover-trend-label')
		]))->addClass('dse-rpt-cover-trend-col')
	);
}
$monthly_trend = (new CDiv([
	(new CTag('h3', true, _('Monthly trend')))->addClass('dse-rpt-cover-trend-title'),
	$trend_grid
]))->addClass('dse-rpt-cover-trend');

$cover_page = (new CDiv([
	(new CDiv([
		(new CTag('img', false, ''))
			->setAttribute('src', 'modules/dynamic_sla_enterprise/logo/logo-basa-2023.png')
			->setAttribute('alt', 'Company logo')
			->addClass('dse-rpt-logo-img'),
		(new CTag('h1', true, _('SLA Dashboard Report')))->addClass('dse-rpt-title')
	]))->addClass('dse-rpt-cover-head'),
	$cover_meta_grid,
	$manager_view,
	$monthly_trend
]))->addClass('dse-rpt-page dse-rpt-cover');

$kpi_grid = (new CDiv([
	(new CDiv([new CTag('small', true, _('Availability')), (new CTag('div', true, $fmt_pct($summary['availability'] ?? null)))->addClass('dse-rpt-kpi')]))->addClass('dse-rpt-kpi-card'),
	(new CDiv([new CTag('small', true, _('SLO target')), (new CTag('div', true, $fmt_pct($summary['slo_target'] ?? null)))->addClass('dse-rpt-kpi')]))->addClass('dse-rpt-kpi-card'),
	(new CDiv([new CTag('small', true, _('Downtime')), (new CTag('div', true, (string) ($summary['downtime'] ?? '-')))->addClass('dse-rpt-kpi')]))->addClass('dse-rpt-kpi-card'),
	(new CDiv([new CTag('small', true, _('Incidents')), (new CTag('div', true, (string) $incidents))->addClass('dse-rpt-kpi')]))->addClass('dse-rpt-kpi-card'),
	(new CDiv([new CTag('small', true, _('Total impact')), (new CTag('div', true, (string) $total_impact))->addClass('dse-rpt-kpi')]))->addClass('dse-rpt-kpi-card')
]))->addClass('dse-rpt-kpi-grid');

$context_list = (new CList())
	->addItem(_('Period') . ': ' . $window_label)
	->addItem(_('Business mode') . ': ' . (string) ($filters['business_mode'] ?? 'all_time'))
	->addItem(_('Exclude maintenance') . ': ' . $exclude_maintenance)
	->addItem(_('Groups') . ': ' . ($scope['groups'] ? $fmt_filters((array) $scope['groups']) : $fmt_filters((array) ($filters['groupids'] ?? []))))
	->addItem(_('Hosts') . ': ' . ($scope['hosts'] ? $fmt_filters((array) $scope['hosts']) : $fmt_filters((array) ($filters['hostids'] ?? []))));

$hosts_impact_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Host'), _('Impact'), _('Triggers'), _('Downtime')]);
foreach (array_slice($hosts_impact, 0, 20) as $row) {
	$hosts_impact_table->addRow([
		(string) ($row['host'] ?? '-'),
		(new CSpan((string) (int) ($row['impact'] ?? 0)))->addClass('dse-rpt-num'),
		(new CSpan((string) (int) ($row['triggers'] ?? 0)))->addClass('dse-rpt-num'),
		(new CSpan($to_human($row['downtime_seconds'] ?? 0)))->addClass('dse-rpt-num')
	]);
}

$top_triggers_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Trigger ID'), _('Host'), _('Name'), _('Start time'), _('Severity'), _('Impact'), _('Downtime')]);
foreach (array_slice($top_triggers, 0, 25) as $row) {
	$severity_label = (string) ($row['severity_label'] ?? '-');
	$top_triggers_table->addRow([
		(new CSpan((string) ($row['triggerid'] ?? '-')))->addClass('dse-rpt-num'),
		(string) ($row['host'] ?? '-'),
		(string) ($row['name'] ?? '-'),
		isset($row['start']) ? date('d/m/Y, H:i:s', (int) $row['start']) : '-',
		(new CSpan($severity_label))->addClass($pill_for_severity($severity_label)),
		(new CSpan((string) (int) ($row['impact'] ?? 0)))->addClass('dse-rpt-num'),
		(new CSpan((string) ($row['downtime'] ?? '-')))->addClass('dse-rpt-num')
	]);
}

$host_impact_chart = (new CDiv())->addClass('dse-rpt-host-impact-chart');
foreach (array_slice($hosts_impact, 0, 10) as $row) {
	$impact = (int) ($row['impact'] ?? 0);
	$bar_pct = ($impact / $max_host_impact) * 100.0;
	$host_impact_chart->addItem(
		(new CDiv([
			(new CSpan((string) ($row['host'] ?? '-')))->addClass('dse-rpt-host-impact-name'),
			(new CDiv([
				(new CSpan())
					->addClass('dse-rpt-host-impact-fill')
					->setAttribute('style', 'width:'.number_format(max(0.0, min(100.0, $bar_pct)), 2, '.', '').'%')
			]))->addClass('dse-rpt-host-impact-track'),
			(new CSpan((string) $impact))->addClass('dse-rpt-host-impact-value')
		]))->addClass('dse-rpt-host-impact-row')
	);
}

$severity_counts = [];
$open_count = 0;
$resolved_count = 0;
foreach ($timeline as $row) {
	$sev = (string) ($row['severity_label'] ?? 'Unknown');
	$severity_counts[$sev] = (int) ($severity_counts[$sev] ?? 0) + 1;
	if (strtoupper((string) ($row['status'] ?? '')) === 'PROBLEM') {
		$open_count++;
	}
	else {
		$resolved_count++;
	}
}
$severity_summary = [];
foreach ($severity_counts as $k => $v) {
	$severity_summary[] = $k.': '.$v;
}
$severity_line = $severity_summary ? implode(' | ', $severity_summary) : '-';

$timeline_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Start time'), _('Recovery time'), _('Status'), _('Severity'), _('Host'), _('Trigger'), _('Duration')]);
foreach (array_slice($timeline, 0, 120) as $row) {
	$severity_label = (string) ($row['severity_label'] ?? '-');
	$status_label = (string) ($row['status'] ?? '-');
	$timeline_table->addRow([
		isset($row['start']) ? date('d/m/Y, H:i:s', (int) $row['start']) : '-',
		isset($row['end']) ? date('d/m/Y, H:i:s', (int) $row['end']) : '-',
		(new CSpan($status_label))->addClass($status_pill($status_label)),
		(new CSpan($severity_label))->addClass($pill_for_severity($severity_label)),
		(string) ($row['host'] ?? '-'),
		(string) ($row['trigger_name'] ?? '-'),
		(new CSpan((string) ($row['duration'] ?? '-')))->addClass('dse-rpt-num')
	]);
}

$persona_section = null;
if ($persona === 'noc') {
	$noc_details_card = (new CDiv([
		(new CTag('h3', true, _('Operational details'))),
		(new CList())
			->addItem(_('Problem rows') . ': ' . count($timeline))
			->addItem(_('Open incidents') . ': ' . $open_count)
			->addItem(_('Resolved incidents') . ': ' . $resolved_count)
			->addItem(_('Severity mix') . ': ' . $severity_line)
	]))->addClass('dse-rpt-info-card');

	$noc_service_card = (new CDiv([
		(new CTag('h3', true, _('Service posture'))),
		(new CList())
			->addItem(_('Services OK') . ': ' . $services_ok . '/' . $services_total . ' (' . number_format($ok_pct, 2) . '%)')
			->addItem(_('Services at risk') . ': ' . $services_impacted . '/' . $services_total . ' (' . number_format($risk_pct, 2) . '%)')
	]))->addClass('dse-rpt-info-card');

	$noc_filter_card = (new CDiv([
		(new CTag('h3', true, _('Filter context'))),
		$context_list
	]))->addClass('dse-rpt-info-card');

	$persona_section = (new CDiv([
		(new CTag('h2', true, _('NOC - Operational'))),
		$kpi_grid,
		(new CDiv([
			$noc_details_card,
			$noc_service_card,
			$noc_filter_card
		]))->addClass('dse-rpt-info-grid'),
		(new CDiv([
			(new CTag('h3', true, _('Top hosts by impact'))),
			$host_impact_chart
		]))->addClass('dse-rpt-chart-card'),
		(new CTag('h3', true, _('Top triggers by downtime'))),
		$top_triggers_table,
		(new CTag('h3', true, _('Problem'))),
		$timeline_table
	]))->addClass('dse-rpt-page dse-rpt-block');
}
elseif ($persona === 'gestao') {
	$gestao_filter_card = (new CDiv([
		(new CTag('h3', true, _('Filter context')))->addClass('dse-rpt-card-title dse-rpt-card-title-info'),
		$context_list
	]))->addClass('dse-rpt-info-card');

	$gestao_service_card = (new CDiv([
		(new CTag('h3', true, _('Service posture')))->addClass('dse-rpt-card-title dse-rpt-card-title-risk'),
		(new CList())
			->addItem(_('Services OK') . ': ' . $services_ok . '/' . $services_total . ' (' . number_format($ok_pct, 2) . '%)')
			->addItem(_('Services at risk') . ': ' . $services_impacted . '/' . $services_total . ' (' . number_format($risk_pct, 2) . '%)')
			->addItem(_('Current risk') . ': ' . ucfirst($risk_level))
	]))->addClass('dse-rpt-info-card');

	$gestao_kpi_card = (new CDiv([
		(new CTag('h3', true, _('Performance highlights')))->addClass('dse-rpt-card-title dse-rpt-card-title-kpi'),
		(new CList())
			->addItem(_('Availability') . ': ' . $fmt_pct($summary['availability'] ?? null))
			->addItem(_('Downtime') . ': ' . (string) ($summary['downtime'] ?? '-'))
			->addItem(_('Incidents') . ': ' . (string) $incidents)
			->addItem(_('Total impact') . ': ' . (string) $total_impact)
	]))->addClass('dse-rpt-info-card');

	$persona_section = (new CDiv([
		(new CTag('h2', true, _('Gestao - Service performance'))),
		$kpi_grid,
		(new CDiv([
			$gestao_filter_card,
			$gestao_service_card,
			$gestao_kpi_card
		]))->addClass('dse-rpt-info-grid'),
		(new CTag('h3', true, _('Top hosts by impact'))),
		$hosts_impact_table
	]))->addClass('dse-rpt-page dse-rpt-block');
}
else {
	$diretoria_summary_card = (new CDiv([
		(new CTag('h3', true, _('Resumo executivo')))->addClass('dse-rpt-card-title dse-rpt-card-title-kpi'),
		(new CList())
			->addItem(_('Current risk') . ': ' . (string) ($executive['current_incident_risk'] ?? '-'))
			->addItem(_('SLA achieved') . ': ' . $fmt_pct($executive['sla_achieved'] ?? null))
			->addItem(_('Incidents') . ': ' . (string) (int) ($executive['incidents_this_window'] ?? 0))
			->addItem(_('Triggers impacted') . ': ' . (string) (int) ($executive['services_impacted'] ?? 0))
			->addItem(_('Total impact') . ': ' . (string) $total_impact)
	]))->addClass('dse-rpt-info-card');

	$diretoria_scope_card = (new CDiv([
		(new CTag('h3', true, _('Scope')))->addClass('dse-rpt-card-title dse-rpt-card-title-info'),
		(new CList())
			->addItem(_('Period') . ': ' . $window_label)
			->addItem(_('Business mode') . ': ' . (string) ($filters['business_mode'] ?? 'all_time'))
			->addItem(_('Groups') . ': ' . ($scope['groups'] ? $fmt_filters((array) $scope['groups']) : $fmt_filters((array) ($filters['groupids'] ?? []))))
			->addItem(_('Hosts') . ': ' . ($scope['hosts'] ? $fmt_filters((array) $scope['hosts']) : $fmt_filters((array) ($filters['hostids'] ?? []))))
	]))->addClass('dse-rpt-info-card');

	$diretoria_service_card = (new CDiv([
		(new CTag('h3', true, _('Service posture')))->addClass('dse-rpt-card-title dse-rpt-card-title-risk'),
		(new CList())
			->addItem(_('Services OK') . ': ' . $services_ok . '/' . $services_total . ' (' . number_format($ok_pct, 2) . '%)')
			->addItem(_('Services at risk') . ': ' . $services_impacted . '/' . $services_total . ' (' . number_format($risk_pct, 2) . '%)')
	]))->addClass('dse-rpt-info-card');

	$persona_section = (new CDiv([
		(new CTag('h2', true, _('Diretoria - Executive summary'))),
		$kpi_grid,
		(new CDiv([
			$diretoria_summary_card,
			$diretoria_scope_card,
			$diretoria_service_card
		]))->addClass('dse-rpt-info-grid'),
		(new CTag('h3', true, _('Top hosts by impact'))),
		$hosts_impact_table
	]))->addClass('dse-rpt-page dse-rpt-block');
}

$wrapper = (new CDiv([
	(new CDiv([
		(new CButton('dse-rpt-print', _('Print now')))
			->setId('dse-rpt-print')
			->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('dse-rpt-actions'),
	$cover_page,
	$persona_section,
	(new CDiv('Report ID: '.$report_id.' | Generated: '.$generated_at.' | Persona: '.strtoupper($persona)))
		->addClass('dse-rpt-print-footer')
]))->setId('dse-rpt-wrapper');

$html_page = (new CHtmlPage())
	->setTitle(_('Inteligence Enterprise Report'))
	->setDocUrl('');

$js = 'document.addEventListener("DOMContentLoaded",function(){'
	. 'document.body.classList.add("dse-rpt-print-report");'
	. 'var b=document.getElementById("dse-rpt-print");'
	. 'if(b){b.addEventListener("click",function(e){e.preventDefault();window.print();});}'
	. '});';
if ($auto_print === 1) {
	$js .= 'window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});';
}

$html_page->addItem($wrapper);
$html_page->addItem(new CScriptTag($js));
$html_page->show();
