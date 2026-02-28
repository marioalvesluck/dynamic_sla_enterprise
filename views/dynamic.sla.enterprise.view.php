<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/html.inc.php';

$html_page = (new CHtmlPage())
	->setTitle(_('Dynamic SLA Enterprise'))
	->setDocUrl('')
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CButton('go_problems', _('Back to Problems')))
						->setAttribute('onclick', "location.href='zabbix.php?action=problem.view'")
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

$content = (new CDiv())->addClass('mnz-dse-wrapper');

$filters = (new CDiv([
	(new CDiv([
		(new CLabel(_('Host groups'), 'dse-groupids'))->addClass('mnz-dse-label'),
		(new CTag('select', true))
			->setId('dse-groupids')
			->setAttribute('multiple', 'multiple')
			->setAttribute('size', '6')
			->addClass('mnz-dse-input mnz-dse-multi')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Hosts'), 'dse-hostids'))->addClass('mnz-dse-label'),
		(new CTag('select', true))
			->setId('dse-hostids')
			->setAttribute('multiple', 'multiple')
			->setAttribute('size', '6')
			->addClass('mnz-dse-input mnz-dse-multi')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Triggers with incidents in period'), 'dse-triggerids'))->addClass('mnz-dse-label'),
		(new CTag('select', true))
			->setId('dse-triggerids')
			->setAttribute('multiple', 'multiple')
			->setAttribute('size', '6')
			->addClass('mnz-dse-input mnz-dse-multi')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Exclude trigger IDs'), 'dse-exclude-triggerids'))->addClass('mnz-dse-label'),
		(new CTextBox('exclude_triggerids', ''))
			->setId('dse-exclude-triggerids')
			->setAttribute('placeholder', _('Ex.: 17000,17010'))
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Impact range'), 'dse-impact-level'))->addClass('mnz-dse-label'),
		(new CSelect('impact_level'))
			->setId('dse-impact-level')
			->addOptions([
				new CSelectOption('all', _('All')),
				new CSelectOption('low', _('Low')),
				new CSelectOption('medium', _('Medium')),
				new CSelectOption('high', _('High')),
				new CSelectOption('critical', _('Critical'))
			])
			->setValue('all')
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Period'), 'dse-period'))->addClass('mnz-dse-label'),
		(new CSelect('period'))
			->setId('dse-period')
			->addOptions([
				new CSelectOption('1h', _('Last 1 hour')),
				new CSelectOption('6h', _('Last 6 hours')),
				new CSelectOption('24h', _('Last 24 hours')),
				new CSelectOption('7d', _('Last 7 days')),
				new CSelectOption('30d', _('Last 30 days')),
				new CSelectOption('90d', _('Last 90 days')),
				new CSelectOption('365d', _('Last 1 year')),
				new CSelectOption('custom', _('Custom range'))
			])
			->setValue('30d')
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('From'), 'dse-from'))->addClass('mnz-dse-label'),
		(new CTextBox('from', ''))
			->setId('dse-from')
			->setAttribute('type', 'datetime-local')
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('To'), 'dse-to'))->addClass('mnz-dse-label'),
		(new CTextBox('to', ''))
			->setId('dse-to')
			->setAttribute('type', 'datetime-local')
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Business mode'), 'dse-business-mode'))->addClass('mnz-dse-label'),
		(new CSelect('business_mode'))
			->setId('dse-business-mode')
			->addOptions([
				new CSelectOption('all_time', _('24x7 (all time)')),
				new CSelectOption('business_mf_8_18', _('Business Mon-Fri 08:00-18:00')),
				new CSelectOption('custom_daily', _('Custom daily window'))
			])
			->setValue('business_mf_8_18')
			->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('Business start/end'), null))->addClass('mnz-dse-label'),
		(new CDiv([
			(new CTextBox('business_start', '08:00'))->setId('dse-business-start')->addClass('mnz-dse-input mnz-dse-input-half'),
			(new CTextBox('business_end', '18:00'))->setId('dse-business-end')->addClass('mnz-dse-input mnz-dse-input-half')
		]))->addClass('mnz-dse-inline')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel(_('SLO target %'), 'dse-slo-target'))->addClass('mnz-dse-label'),
		(new CTextBox('slo_target', '99.9'))->setId('dse-slo-target')->addClass('mnz-dse-input')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CLabel([
			(new CCheckBox('exclude_maintenance', '1'))
				->setId('dse-exclude-maintenance')
				->setChecked(true),
			NBSP(),
			_('Exclude maintenance overlap')
		], 'dse-exclude-maintenance'))->addClass('mnz-dse-check-label')
	]))->addClass('mnz-dse-field'),
	(new CDiv([
		(new CButton('dse-refresh-options', _('Refresh options')))->setId('dse-refresh-options')->addClass(ZBX_STYLE_BTN_ALT),
		(new CButton('dse-run', _('Calculate SLA')))->setId('dse-run')->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('mnz-dse-field mnz-dse-actions')
]))->addClass('mnz-dse-filters');

$content->addItem((new CDiv([
	(new CDiv(_('SLA calculation window')))->addClass('mnz-dse-section-title'),
	$filters
]))->addClass('mnz-dse-panel'));

$content->addItem((new CDiv())->setId('dse-status')->addClass('mnz-dse-status'));
$content->addItem((new CDiv())->setId('dse-exec')->addClass('mnz-dse-exec'));
$content->addItem((new CDiv())->setId('dse-summary')->addClass('mnz-dse-summary'));
$content->addItem((new CDiv())->setId('dse-insights')->addClass('mnz-dse-insights'));
$content->addItem((new CDiv())->setId('dse-daily')->addClass('mnz-dse-daily'));
$content->addItem((new CDiv())->setId('dse-heatmap')->addClass('mnz-dse-heatmap'));
$content->addItem((new CDiv())->setId('dse-host-impact')->addClass('mnz-dse-host-impact'));
$content->addItem((new CDiv())->setId('dse-top-triggers')->addClass('mnz-dse-top-triggers'));
$content->addItem((new CDiv())->setId('dse-timeline')->addClass('mnz-dse-timeline'));

$chart_data = [
	'nowTs' => (int) ($data['now_ts'] ?? time()),
	'runLabel' => _('Calculate SLA'),
	'loadingLabel' => _('Calculating...'),
	'noDataLabel' => _('No data'),
	'failedLabel' => _('Failed to calculate SLA')
];

ob_start();
include dirname(__FILE__).'/js/dynamic.sla.enterprise.js.php';
$content->addItem(new CScriptTag(ob_get_clean()));

$html_page->addItem($content);
$html_page->show();
