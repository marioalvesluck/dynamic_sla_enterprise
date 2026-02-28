<?php
$chart_data = $chart_data ?? [];
?>
window.mnzDseData = <?= json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

jQuery(function() {
	'use strict';

	var d = window.mnzDseData || {};
	delete window.mnzDseData;

	function fmtDateInput(ts) {
		var dt = new Date(ts * 1000);
		var y = dt.getFullYear();
		var mo = String(dt.getMonth() + 1).padStart(2, '0');
		var day = String(dt.getDate()).padStart(2, '0');
		var h = String(dt.getHours()).padStart(2, '0');
		var m = String(dt.getMinutes()).padStart(2, '0');
		return y + '-' + mo + '-' + day + 'T' + h + ':' + m;
	}

	function fmtPct(v) {
		var n = Number(v);
		if (!isFinite(n)) {
			return '-';
		}
		return n.toFixed(3) + '%';
	}

	function fmtDownShort(seconds) {
		var s = Number(seconds || 0);
		if (!isFinite(s) || s <= 0) {
			return '0m';
		}
		var d = Math.floor(s / 86400);
		var h = Math.floor((s % 86400) / 3600);
		var m = Math.floor((s % 3600) / 60);
		if (d > 0) {
			return d + 'd ' + h + 'h';
		}
		if (h > 0) {
			return h + 'h ' + m + 'm';
		}
		return m + 'm';
	}

	function setStatus(message, type) {
		jQuery('#dse-status')
			.removeClass('mnz-dse-status-error mnz-dse-status-ok')
			.addClass(type === 'error' ? 'mnz-dse-status-error' : 'mnz-dse-status-ok')
			.text(message || '');
	}

	function hasSelectedGroups() {
		var vals = jQuery('#dse-groupids').val() || [];
		if (!Array.isArray(vals)) {
			vals = [vals];
		}
		return vals.filter(Boolean).length > 0;
	}

	function setResultsCollapsed(collapsed) {
		var ids = ['#dse-exec', '#dse-summary', '#dse-daily', '#dse-heatmap', '#dse-top-triggers', '#dse-timeline'];
		ids.forEach(function(id) {
			if (collapsed) {
				jQuery(id).hide().empty();
			}
			else {
				jQuery(id).show();
			}
		});
		jQuery('#dse-run').prop('disabled', collapsed);
	}

	function selectedCsv(id) {
		var vals = jQuery(id).val() || [];
		if (!Array.isArray(vals)) {
			vals = [vals];
		}
		return vals.filter(Boolean).join(',');
	}

	function severityPayload() {
		var vals = jQuery('#dse-severity').val() || [];
		if (!Array.isArray(vals)) {
			vals = [vals];
		}
		vals = vals.map(String);
		var hasAll = vals.indexOf('__all__') >= 0;
		var cleaned = vals.filter(function(v) { return v !== '__all__'; });
		var uniq = {};
		var normalized = [];
		cleaned.forEach(function(v) {
			if (/^[0-5]$/.test(v) && !uniq[v]) {
				uniq[v] = true;
				normalized.push(v);
			}
		});
		if (hasAll || normalized.length === 0 || normalized.length === 6) {
			return { severity: '', severity_explicit: '0' };
		}
		return { severity: normalized.join(','), severity_explicit: '1' };
	}

	function severityBadgeClass(value) {
		var sev = Number(value);
		if (!isFinite(sev)) {
			return 'mnz-dse-sev-0';
		}
		sev = Math.max(0, Math.min(5, Math.floor(sev)));
		return 'mnz-dse-sev-' + sev;
	}

	function escapeHtml(v) {
		return String(v == null ? '' : v)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function updateMsToggle(select) {
		var $select = jQuery(select);
		var $root = $select.data('mnz-ms-root');
		if (!$root || !$root.length) {
			return;
		}
		var selected = $select.find('option:selected');
		var txt;
		var isSeverity = ($select.attr('id') === 'dse-severity');
		var hasAll = isSeverity && $select.find('option[value="__all__"]').is(':selected');
		if (!selected.length || hasAll) {
			txt = 'All';
		}
		else if (selected.length === 1) {
			txt = selected.first().text();
		}
		else if (isSeverity && selected.length >= 6) {
			txt = 'All';
		}
		else {
			txt = selected.length + ' selected';
		}
		$root.find('.mnz-dse-ms-value').text(txt);
	}

	function rebuildMsOptions(select) {
		var $select = jQuery(select);
		var $root = $select.data('mnz-ms-root');
		if (!$root || !$root.length) {
			return;
		}

		var html = '';
		$select.find('option').each(function() {
			var $opt = jQuery(this);
			var value = String($opt.attr('value') || '');
			var label = String($opt.text() || '');
			var checked = $opt.prop('selected') ? ' checked' : '';
			html += '<label class="mnz-dse-ms-row" data-label="' + escapeHtml(label.toLowerCase()) + '">' +
				'<input type="checkbox" value="' + escapeHtml(value) + '"' + checked + '> ' +
				'<span>' + escapeHtml(label) + '</span>' +
			'</label>';
		});

		$root.find('.mnz-dse-ms-options').html(html);
		updateMsToggle(select);
	}

	function initMs(select, placeholder) {
		var $select = jQuery(select);
		if (!$select.length || $select.data('mnz-ms-init')) {
			return;
		}

		$select.addClass('mnz-dse-native-multi');
		var root = jQuery(
			'<div class="mnz-dse-ms">' +
				'<button type="button" class="mnz-dse-ms-toggle">' +
					'<span class="mnz-dse-ms-value">' + escapeHtml(placeholder || 'All') + '</span>' +
					'<span class="mnz-dse-ms-caret">v</span>' +
				'</button>' +
				'<div class="mnz-dse-ms-panel">' +
					'<div class="mnz-dse-ms-toolbar">' +
						'<input type="text" class="mnz-dse-ms-search" placeholder="Search...">' +
						'<div class="mnz-dse-ms-actions">' +
							'<button type="button" class="mnz-dse-ms-all">All</button>' +
							'<button type="button" class="mnz-dse-ms-clear">Clear</button>' +
						'</div>' +
					'</div>' +
					'<div class="mnz-dse-ms-options"></div>' +
				'</div>' +
			'</div>'
		);

		$select.after(root);
		$select.data('mnz-ms-init', true);
		$select.data('mnz-ms-root', root);

		rebuildMsOptions(select);

		root.on('click', '.mnz-dse-ms-toggle', function(e) {
			e.preventDefault();
			e.stopPropagation();
			jQuery('.mnz-dse-ms').not(root).removeClass('is-open');
			root.toggleClass('is-open');
		});

		root.on('click', '.mnz-dse-ms-panel', function(e) {
			e.stopPropagation();
		});

		root.on('input', '.mnz-dse-ms-search', function() {
			var q = String(jQuery(this).val() || '').toLowerCase().trim();
			root.find('.mnz-dse-ms-row').each(function() {
				var ok = !q || String(jQuery(this).attr('data-label') || '').indexOf(q) >= 0;
				jQuery(this).toggle(ok);
			});
		});

		root.on('change', '.mnz-dse-ms-options input[type="checkbox"]', function() {
			var val = String(jQuery(this).val() || '');
			var checked = jQuery(this).is(':checked');
			var isSeverity = ($select.attr('id') === 'dse-severity');

			if (isSeverity) {
				if (val === '__all__' && checked) {
					root.find('.mnz-dse-ms-options input[type="checkbox"]').prop('checked', false);
					jQuery(this).prop('checked', true);
					$select.find('option').prop('selected', false);
					$select.find('option[value="__all__"]').prop('selected', true);
					updateMsToggle(select);
					$select.trigger('change');
					return;
				}
				if (val !== '__all__' && checked) {
					root.find('.mnz-dse-ms-options input[type="checkbox"][value="__all__"]').prop('checked', false);
					$select.find('option[value="__all__"]').prop('selected', false);
				}
			}

			$select.find('option').each(function() {
				if (String(jQuery(this).attr('value') || '') === val) {
					jQuery(this).prop('selected', checked);
				}
			});
			updateMsToggle(select);
			$select.trigger('change');
		});

		root.on('click', '.mnz-dse-ms-all', function(e) {
			e.preventDefault();
			var isSeverity = ($select.attr('id') === 'dse-severity');
			if (isSeverity) {
				root.find('.mnz-dse-ms-options input[type="checkbox"]').prop('checked', false);
				root.find('.mnz-dse-ms-options input[type="checkbox"][value="__all__"]').prop('checked', true);
				$select.find('option').prop('selected', false);
				$select.find('option[value="__all__"]').prop('selected', true);
			}
			else {
				root.find('.mnz-dse-ms-options input[type="checkbox"]').prop('checked', true);
				$select.find('option').prop('selected', true);
			}
			updateMsToggle(select);
			$select.trigger('change');
		});

		root.on('click', '.mnz-dse-ms-clear', function(e) {
			e.preventDefault();
			root.find('.mnz-dse-ms-options input[type="checkbox"]').prop('checked', false);
			$select.find('option').prop('selected', false);
			updateMsToggle(select);
			$select.trigger('change');
		});
	}

	function ensureSeverityOptions() {
		var $sev = jQuery('#dse-severity');
		if (!$sev.length) {
			return;
		}
		if ($sev.find('option').length > 0) {
			return;
		}
		$sev.html(
			'<option value="__all__">All</option>' +
			'<option value="0">Not classified</option>' +
			'<option value="1">Information</option>' +
			'<option value="2">Warning</option>' +
			'<option value="3">Average</option>' +
			'<option value="4">High</option>' +
			'<option value="5">Disaster</option>'
		);
	}

	function setupMultiSelects() {
		ensureSeverityOptions();
		initMs('#dse-groupids', 'All groups');
		initMs('#dse-hostids', 'All hosts');
		initMs('#dse-triggerids', 'All triggers');
		initMs('#dse-severity', 'All severities');
		var $sev = jQuery('#dse-severity');
		if ($sev.length && !$sev.data('mnz-sev-default-cleared')) {
			$sev.find('option').prop('selected', false);
			$sev.find('option[value="__all__"]').prop('selected', true);
			$sev.data('mnz-sev-default-cleared', true);
			rebuildMsOptions('#dse-severity');
		}
	}

	function applyPeriodPreset() {
		var period = jQuery('#dse-period').val();
		var nowTs = parseInt(d.nowTs || Math.floor(Date.now() / 1000), 10);
		var map = {
			'1h': 3600,
			'6h': 21600,
			'24h': 86400,
			'7d': 604800,
			'30d': 2592000,
			'90d': 7776000,
			'365d': 31536000
		};
		if (period === 'custom') {
			return;
		}
		if (!map[period]) {
			period = '30d';
		}
		jQuery('#dse-from').val(fmtDateInput(nowTs - map[period]));
		jQuery('#dse-to').val(fmtDateInput(nowTs));
	}

	function buildBasePayload() {
		var severity = severityPayload();
		return {
			period: jQuery('#dse-period').val(),
			from: jQuery('#dse-from').val(),
			to: jQuery('#dse-to').val(),
			groupids: selectedCsv('#dse-groupids'),
			hostids: selectedCsv('#dse-hostids'),
			triggerids: selectedCsv('#dse-triggerids'),
			severity: severity.severity,
			severity_explicit: severity.severity_explicit,
			exclude_triggerids: jQuery('#dse-exclude-triggerids').val(),
			business_mode: jQuery('#dse-business-mode').val(),
			business_start: jQuery('#dse-business-start').val(),
			business_end: jQuery('#dse-business-end').val(),
			exclude_maintenance: jQuery('#dse-exclude-maintenance').is(':checked') ? '1' : '0',
			slo_target: jQuery('#dse-slo-target').val()
		};
	}

	function populateSelect(id, options, selected) {
		var selectedSet = {};
		(selected || []).forEach(function(v) {
			selectedSet[String(v)] = true;
		});
		var html = '';
		(options || []).forEach(function(opt) {
			var val = String(opt.id);
			var text = String(opt.name || opt.label || opt.id);
			if (opt.hosts && opt.hosts.length) {
				text += ' [' + opt.hosts.join(', ') + ']';
			}
			if (opt.has_problem_in_window) {
				text += ' *';
			}
			html += '<option value="' + val.replace(/"/g, '&quot;') + '"' + (selectedSet[val] ? ' selected' : '') + '>' +
				text.replace(/</g, '&lt;') + '</option>';
		});
		jQuery(id).html(html);
		if (jQuery(id).data('mnz-ms-init')) {
			rebuildMsOptions(id);
		}
	}

	function clearDependentSelectors() {
		populateSelect('#dse-hostids', [], []);
		populateSelect('#dse-triggerids', [], []);
	}

	function loadOptions(triggeredBy) {
		if (!hasSelectedGroups() && triggeredBy !== 'init' && triggeredBy !== 'groups') {
			setResultsCollapsed(true);
			clearDependentSelectors();
			setStatus('Select at least one Host group to enable calculation.', 'ok');
			return jQuery.Deferred().resolve().promise();
		}

		var payload = buildBasePayload();
		payload.mode = 'options';

		if (triggeredBy === 'groups') {
			payload.hostids = '';
			payload.triggerids = '';
		}
		else if (triggeredBy === 'hosts' || triggeredBy === 'severity' || triggeredBy === 'period') {
			payload.triggerids = '';
		}

		var selectedGroups = jQuery('#dse-groupids').val() || [];
		var selectedHosts = jQuery('#dse-hostids').val() || [];
		var selectedTriggers = jQuery('#dse-triggerids').val() || [];

		jQuery('#dse-refresh-options').prop('disabled', true);
		return jQuery.ajax({
			url: 'zabbix.php?action=dynamic.sla.enterprise.data',
			method: 'POST',
			dataType: 'json',
			data: payload
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				return;
			}
			var data = res.data;
			populateSelect('#dse-groupids', data.groups, selectedGroups);

			if (triggeredBy === 'groups') {
				selectedHosts = [];
				selectedTriggers = [];
			}
			if (triggeredBy === 'hosts') {
				selectedTriggers = [];
			}

			populateSelect('#dse-hostids', data.hosts, selectedHosts);
			populateSelect('#dse-triggerids', data.triggers, selectedTriggers);
			setStatus(
				'Options loaded: groups ' + (data.groups ? data.groups.length : 0) +
				' | hosts ' + (data.hosts ? data.hosts.length : 0) +
				' | triggers ' + (data.triggers ? data.triggers.length : 0),
				'ok'
			);

			if (!hasSelectedGroups()) {
				setResultsCollapsed(true);
				clearDependentSelectors();
				setStatus('Select at least one Host group to enable calculation.', 'ok');
			}
			else {
				setResultsCollapsed(false);
			}
		}).always(function() {
			jQuery('#dse-refresh-options').prop('disabled', false);
		});
	}

	function renderExecutive(data) {
		var ex = data.executive || {};
		var risk = String(ex.current_incident_risk || 'Low');
		var riskCls = risk.toLowerCase() === 'high' ? 'mnz-dse-risk-high' : (risk.toLowerCase() === 'medium' ? 'mnz-dse-risk-medium' : 'mnz-dse-risk-low');
		var html = '' +
			'<div class="mnz-dse-panel">' +
				'<div class="mnz-dse-section-title">Executive Summary</div>' +
				'<div class="mnz-dse-cards mnz-dse-cards-exec">' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Current Incident Risk</div><div class="mnz-dse-badge ' + riskCls + '">' + risk + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">SLA Achieved (Window)</div><div class="mnz-dse-v">' + fmtPct(ex.sla_achieved) + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Downtime (Window)</div><div class="mnz-dse-v">' + (ex.downtime_window || '-') + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Incidents in Window</div><div class="mnz-dse-v">' + Number(ex.incidents_this_window || 0) + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Triggers Impacted</div><div class="mnz-dse-v">' + Number(ex.services_impacted || 0) + '</div></div>' +
				'</div>' +
			'</div>';
		jQuery('#dse-exec').html(html);
	}

	function renderSummary(data) {
		var s = data.summary || {};
		var gap = parseFloat(s.slo_gap || 0);
		var gapClass = gap >= 0 ? 'mnz-dse-gap-good' : 'mnz-dse-gap-bad';
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Dynamic SLA Intelligence</div><div class="mnz-dse-cards">' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Availability</div><div class="mnz-dse-v">' + fmtPct(s.availability) + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">SLO Target</div><div class="mnz-dse-v">' + fmtPct(s.slo_target) + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Gap</div><div class="mnz-dse-v ' + gapClass + '">' + (gap >= 0 ? '+' : '') + (isNaN(gap) ? '-' : gap.toFixed(3) + '%') + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Downtime</div><div class="mnz-dse-v">' + (s.downtime || '-') + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Uptime</div><div class="mnz-dse-v">' + (s.uptime || '-') + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">MTTR</div><div class="mnz-dse-v">' + (s.mttr || '-') + '</div></div>' +
			'</div></div>';
		jQuery('#dse-summary').html(html);
	}

	function renderDaily(data) {
		var arr = Array.isArray(data.daily) ? data.daily : [];
		if (!arr.length) {
			jQuery('#dse-daily').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}
		var maxDown = 1;
		arr.forEach(function(row) {
			if ((row.downtime_seconds || 0) > maxDown) {
				maxDown = row.downtime_seconds || 0;
			}
		});

		var bars = '<div class="mnz-dse-daily-bars">';
		arr.forEach(function(row) {
			var h = Math.max(4, ((row.downtime_seconds || 0) / maxDown) * 88);
			bars += '<div class="mnz-dse-bar-item" title="' + row.date + ' | Avail: ' + fmtPct(row.availability) + ' | Down: ' + (row.downtime_seconds || 0) + 's">' +
				'<span class="mnz-dse-bar-val">' + fmtDownShort(row.downtime_seconds || 0) + '</span>' +
				'<div class="mnz-dse-bar" style="height:' + h + 'px"></div>' +
				'<span class="mnz-dse-bar-lbl">' + String(row.date || '').slice(5) + '</span>' +
			'</div>';
		});
		bars += '</div>';
		jQuery('#dse-daily').html('<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Daily distribution</div>' + bars + '</div>');
	}

	function renderHeatmap(data) {
		var hm = data.heatmap || {};
		var matrix = hm.matrix || [];
		if (!matrix.length) {
			jQuery('#dse-heatmap').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}

		var max = 0;
		for (var w = 0; w < matrix.length; w++) {
			for (var h = 0; h < matrix[w].length; h++) {
				if (matrix[w][h] > max) {
					max = matrix[w][h];
				}
			}
		}
		if (max < 1) {
			max = 1;
		}

		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Incident heatmap (day x hour)</div><div class="mnz-dse-heatmap-grid">';
		html += '<div class="mnz-dse-heatmap-row mnz-dse-heatmap-head"><div class="mnz-dse-heatmap-corner"></div>';
		(hm.hour_labels || []).forEach(function(lbl) {
			html += '<div class="mnz-dse-heatmap-h">' + String(lbl).replace(/</g, '&lt;') + '</div>';
		});
		html += '</div>';

		(matrix || []).forEach(function(row, widx) {
			html += '<div class="mnz-dse-heatmap-row"><div class="mnz-dse-heatmap-w">' + (hm.week_labels && hm.week_labels[widx] ? hm.week_labels[widx] : widx) + '</div>';
			row.forEach(function(v) {
				var ratio = v <= 0 ? 0 : (v / max);
				var alpha = ratio === 0 ? 0.08 : (0.2 + ratio * 0.8);
				html += '<div class="mnz-dse-heatmap-cell" style="background:rgba(30,136,229,' + alpha.toFixed(3) + ')" title="' + v + '">' + v + '</div>';
			});
			html += '</div>';
		});
		html += '</div></div>';
		jQuery('#dse-heatmap').html(html);
	}

	function appendExcludedTrigger(id) {
		var current = String(jQuery('#dse-exclude-triggerids').val() || '').trim();
		var arr = current ? current.split(/[\s,;]+/) : [];
		if (arr.indexOf(String(id)) < 0) {
			arr.push(String(id));
		}
		jQuery('#dse-exclude-triggerids').val(arr.filter(Boolean).join(','));
	}

	function renderTopTriggers(data) {
		var rows = Array.isArray(data.top_triggers) ? data.top_triggers : [];
		if (!rows.length) {
			jQuery('#dse-top-triggers').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Top triggers by downtime</div><table class="list-table"><thead><tr><th>Trigger ID</th><th>Host</th><th>Name</th><th>Severity</th><th>Downtime</th><th>Action</th></tr></thead><tbody>';
		rows.forEach(function(row) {
			html += '<tr>' +
				'<td>' + row.triggerid + '</td>' +
				'<td>' + String(row.host || '-').replace(/</g, '&lt;') + '</td>' +
				'<td>' + String(row.name || '').replace(/</g, '&lt;') + '</td>' +
				'<td><span class="mnz-dse-sev ' + severityBadgeClass(row.severity) + '">' + String(row.severity_label || '-').replace(/</g, '&lt;') + '</span></td>' +
				'<td>' + String(row.downtime || '-') + '</td>' +
				'<td><button type="button" class="btn-alt mnz-dse-exclude-btn" data-triggerid="' + row.triggerid + '">Exclude</button></td>' +
			'</tr>';
		});
		html += '</tbody></table></div>';
		jQuery('#dse-top-triggers').html(html);
	}

	function renderTimeline(data) {
		var rows = Array.isArray(data.timeline) ? data.timeline : [];
		var total = Number(data.timeline_total || rows.length || 0);
		if (!isFinite(total) || total < 0) {
			total = rows.length;
		}
		if (!rows.length) {
			jQuery('#dse-timeline').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}

		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Timeline</div><table class="list-table"><thead><tr><th>Time</th><th>Recovery time</th><th>Status</th><th>Severity</th><th>Host</th><th>Trigger</th><th>Duration</th></tr></thead><tbody>';
		rows.forEach(function(row) {
			var start = row.start ? new Date(row.start * 1000).toLocaleString() : '-';
			var end = row.end ? new Date(row.end * 1000).toLocaleString() : '-';
			var statusCls = row.status === 'PROBLEM' ? 'mnz-dse-status-bad' : 'mnz-dse-status-good';
			html += '<tr>' +
				'<td>' + start + '</td>' +
				'<td>' + end + '</td>' +
				'<td><span class="' + statusCls + '">' + String(row.status || '-') + '</span></td>' +
				'<td><span class="mnz-dse-sev ' + severityBadgeClass(row.severity) + '">' + String(row.severity_label || '-').replace(/</g, '&lt;') + '</span></td>' +
				'<td>' + String(row.host || '-').replace(/</g, '&lt;') + '</td>' +
				'<td>' + String(row.trigger_name || '-').replace(/</g, '&lt;') + '</td>' +
				'<td>' + String(row.duration || '-') + '</td>' +
			'</tr>';
		});
		html += '</tbody></table>' +
			'<div class="mnz-dse-table-meta">Displaying ' + rows.length + ' of ' + total + ' found</div>' +
			'</div>';
		jQuery('#dse-timeline').html(html);
	}

	function parseError(jqXHR, fallback) {
		var msg = fallback;
		if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.error && jqXHR.responseJSON.error.message) {
			msg = jqXHR.responseJSON.error.message;
		}
		else if (jqXHR && jqXHR.responseText) {
			var t = String(jqXHR.responseText).trim();
			if (t) {
				msg = t.slice(0, 300);
			}
		}
		return msg;
	}

	function run() {
		if (!hasSelectedGroups()) {
			setResultsCollapsed(true);
			setStatus('Select at least one Host group to calculate SLA.', 'error');
			return;
		}
		setResultsCollapsed(false);

		var btn = jQuery('#dse-run');
		btn.prop('disabled', true).text(d.loadingLabel || 'Calculating...');
		setStatus('');

		var payload = buildBasePayload();
		payload.mode = 'calculate';

		jQuery.ajax({
			url: 'zabbix.php?action=dynamic.sla.enterprise.data',
			method: 'POST',
			dataType: 'json',
			data: payload
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				setStatus((res && res.error && res.error.message) ? res.error.message : (d.failedLabel || 'Failed to calculate SLA'), 'error');
				return;
			}
			renderExecutive(res.data);
			renderSummary(res.data);
			renderDaily(res.data);
			renderHeatmap(res.data);
			renderTopTriggers(res.data);
			renderTimeline(res.data);
			setStatus((res.data && res.data.note) ? res.data.note : 'SLA calculation finished', (res.data && res.data.note) ? 'error' : 'ok');
		}).fail(function(jqXHR) {
			setStatus(parseError(jqXHR, d.failedLabel || 'Failed to calculate SLA'), 'error');
		}).always(function() {
			btn.prop('disabled', false).text(d.runLabel || 'Calculate SLA');
		});
	}

	jQuery('#dse-period').on('change', function() {
		applyPeriodPreset();
		loadOptions('period');
	});
	jQuery('#dse-from,#dse-to').on('change', function() {
		loadOptions('period');
	});
	jQuery('#dse-groupids').on('change', function() {
		if (!hasSelectedGroups()) {
			setResultsCollapsed(true);
			clearDependentSelectors();
			setStatus('Select at least one Host group to enable calculation.', 'ok');
		}
		else {
			setResultsCollapsed(false);
		}
		loadOptions('groups');
	});
	jQuery('#dse-hostids').on('change', function() {
		loadOptions('hosts');
	});
	jQuery('#dse-severity').on('change', function() {
		loadOptions('severity');
	});
	jQuery('#dse-refresh-options').on('click', function(e) {
		e.preventDefault();
		loadOptions('manual');
	});
	jQuery('#dse-run').on('click', function(e) {
		e.preventDefault();
		run();
	});
	jQuery(document).on('click', '.mnz-dse-exclude-btn', function() {
		appendExcludedTrigger(jQuery(this).data('triggerid'));
		run();
	});
	jQuery(document).on('click', function() {
		jQuery('.mnz-dse-ms').removeClass('is-open');
	});
	applyPeriodPreset();
	setupMultiSelects();
	setResultsCollapsed(true);
	setStatus('Select at least one Host group to enable calculation.', 'ok');
	loadOptions('init').always(function() {
		if (hasSelectedGroups()) {
			setResultsCollapsed(false);
		}
	});
});
