<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/html.inc.php';

$persona = strtolower((string) ($data['persona'] ?? 'noc'));
if (!in_array($persona, ['noc', 'gestao', 'diretoria'], true)) {
	$persona = 'noc';
}
$auto_print = (int) ($data['auto_print'] ?? 0);
$report = (array) ($data['report'] ?? []);
$summary = (array) ($report['summary'] ?? []);
$executive = (array) ($report['executive'] ?? []);
$filters = (array) ($report['filters'] ?? []);
$top_triggers = (array) ($report['top_triggers'] ?? []);
$hosts_impact = (array) ($report['hosts_impact'] ?? []);
$timeline = (array) ($report['timeline'] ?? []);
$generated_at = (string) ($data['generated_at'] ?? '');

$html_page = (new CHtmlPage())
	->setTitle(_('Dynamic SLA Enterprise Report'))
	->setDocUrl('');

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

$header = (new CDiv([
	(new CTag('h1', true, _('Dynamic SLA Enterprise Report')))->addClass('dse-rpt-title'),
	(new CDiv([
		(new CSpan(_('Persona') . ': ' . strtoupper($persona)))->addClass('dse-rpt-chip'),
		(new CSpan(_('Generated') . ': ' . $generated_at))->addClass('dse-rpt-chip')
	]))->addClass('dse-rpt-chips')
]))->addClass('dse-rpt-header');

$meta = (new CDiv([
	(new CTag('h3', true, _('Filter context'))),
	(new CList())
		->addItem(_('Groups') . ': ' . $fmt_filters((array) ($filters['groupids'] ?? [])))
		->addItem(_('Hosts') . ': ' . $fmt_filters((array) ($filters['hostids'] ?? [])))
		->addItem(_('Triggers') . ': ' . $fmt_filters((array) ($filters['triggerids'] ?? [])))
		->addItem(_('Business mode') . ': ' . (string) ($filters['business_mode'] ?? 'all_time'))
]))->addClass('dse-rpt-block');

$make_kpi_card = static function(string $label, string $value): CDiv {
	$value_node = (new CTag('div', true, $value))->addClass('dse-rpt-kpi');
	return (new CDiv([
		new CTag('small', true, $label),
		$value_node
	]))->addClass('dse-rpt-kpi-card');
};

$kpis = (new CDiv([
	$make_kpi_card(_('Availability'), $fmt_pct($summary['availability'] ?? null)),
	$make_kpi_card(_('SLO target'), $fmt_pct($summary['slo_target'] ?? null)),
	$make_kpi_card(_('Downtime'), (string) ($summary['downtime'] ?? '-')),
	$make_kpi_card(_('Incidents'), (string) (int) ($summary['incidents'] ?? 0)),
	$make_kpi_card(_('Total impact'), (string) (int) ($summary['total_impact'] ?? 0))
]))->addClass('dse-rpt-kpi-grid');

$top_triggers_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Trigger ID'), _('Host'), _('Name'), _('Severity'), _('Impact'), _('Downtime')]);
foreach (array_slice($top_triggers, 0, 20) as $row) {
	$top_triggers_table->addRow([
		(string) ($row['triggerid'] ?? '-'),
		(string) ($row['host'] ?? '-'),
		(string) ($row['name'] ?? '-'),
		(string) ($row['severity_label'] ?? '-'),
		(string) (int) ($row['impact'] ?? 0),
		(string) ($row['downtime'] ?? '-')
	]);
}

$hosts_impact_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Host'), _('Impact'), _('Triggers'), _('Downtime')]);
foreach (array_slice($hosts_impact, 0, 20) as $row) {
	$hosts_impact_table->addRow([
		(string) ($row['host'] ?? '-'),
		(string) (int) ($row['impact'] ?? 0),
		(string) (int) ($row['triggers'] ?? 0),
		(string) ($row['downtime_seconds'] ?? 0)
	]);
}

$timeline_table = (new CTableInfo())
	->addClass('dse-rpt-table')
	->setHeader([_('Start time'), _('Recovery time'), _('Status'), _('Severity'), _('Host'), _('Trigger'), _('Duration')]);
foreach (array_slice($timeline, 0, 80) as $row) {
	$timeline_table->addRow([
		isset($row['start']) ? date('d/m/Y, H:i:s', (int) $row['start']) : '-',
		isset($row['end']) ? date('d/m/Y, H:i:s', (int) $row['end']) : '-',
		(string) ($row['status'] ?? '-'),
		(string) ($row['severity_label'] ?? '-'),
		(string) ($row['host'] ?? '-'),
		(string) ($row['trigger_name'] ?? '-'),
		(string) ($row['duration'] ?? '-')
	]);
}

$sections = [];
if ($persona === 'noc') {
	$sections[] = (new CDiv([
		(new CTag('h2', true, _('NOC - Operational deep dive'))),
		$kpis,
		(new CTag('h3', true, _('Top triggers by downtime'))),
		$top_triggers_table,
		(new CTag('h3', true, _('Timeline'))),
		$timeline_table
	]))->addClass('dse-rpt-block');
}
elseif ($persona === 'gestao') {
	$sections[] = (new CDiv([
		(new CTag('h2', true, _('Gestao - Service performance'))),
		$kpis,
		(new CTag('h3', true, _('Top hosts by impact'))),
		$hosts_impact_table,
		(new CTag('h3', true, _('Top triggers by downtime'))),
		$top_triggers_table
	]))->addClass('dse-rpt-block');
}
else {
	$sections[] = (new CDiv([
		(new CTag('h2', true, _('Diretoria - Executive summary'))),
		$kpis,
		(new CList())
			->addItem(_('Risk') . ': ' . (string) ($executive['current_incident_risk'] ?? '-'))
			->addItem(_('SLA achieved') . ': ' . $fmt_pct($executive['sla_achieved'] ?? null))
			->addItem(_('Incidents in window') . ': ' . (string) (int) ($executive['incidents_this_window'] ?? 0))
			->addItem(_('Triggers impacted') . ': ' . (string) (int) ($executive['services_impacted'] ?? 0))
			->addItem(_('Total impact') . ': ' . (string) (int) ($executive['total_impact_window'] ?? 0))
	]))->addClass('dse-rpt-block');
}

$wrapper = (new CDiv([
	(new CDiv([
		(new CButton('dse-rpt-print', _('Print now')))
			->setId('dse-rpt-print')
			->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('dse-rpt-actions'),
	$header,
	$meta,
	new CDiv($sections)
]))->setId('dse-rpt-wrapper');

$js = 'document.addEventListener("DOMContentLoaded",function(){'
	. 'var b=document.getElementById("dse-rpt-print");'
	. 'if(b){b.addEventListener("click",function(e){e.preventDefault();window.print();});}'
	. '});';
if ($auto_print === 1) {
	$js .= 'window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});';
}

$html_page->addItem($wrapper);
$html_page->addItem(new CScriptTag($js));
$html_page->show();
