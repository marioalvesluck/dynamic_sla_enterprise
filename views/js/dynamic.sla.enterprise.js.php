<?php
$chart_data = $chart_data ?? [];
?>
window.mnzDseData = <?= json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

jQuery(function() {
	'use strict';

	var d = window.mnzDseData || {};
	delete window.mnzDseData;
	var incidentMode = 'starts';
	var lastCalculatedData = null;
	var tablePagerState = {};
	var CACHE_NS = 'mnz_dse_v1';
	var CACHE_TTL_OPTIONS = 60 * 1000;
	var CACHE_TTL_CALC = 120 * 1000;
	var CACHE_KEYS = {
		options: CACHE_NS + '_options',
		calc: CACHE_NS + '_calc',
		savedViews: CACHE_NS + '_saved_views',
		audit: CACHE_NS + '_audit'
	};
	var activeSavedViewId = null;
	var pendingOverwriteViewId = null;
	var problemPagerState = { page: 1, limit: 25 };
	var tagSuggestions = { keys: [], values_by_key: {} };

	function normTagOp(op) {
		var v = String(op || '').toLowerCase();
		if (v === 'contains' || v === 'exists') {
			return v;
		}
		return 'equals';
	}

	function normTagJoin(join) {
		return String(join || '').toLowerCase() === 'or' ? 'or' : 'and';
	}

	function normalizeTagRules(rules) {
		var out = [];
		(Array.isArray(rules) ? rules : []).forEach(function(rule, idx) {
			if (!rule || typeof rule !== 'object') {
				return;
			}
			var key = String(rule.key || '').trim();
			var operator = normTagOp(rule.operator);
			var value = String(rule.value || '').trim();
			var join = normTagJoin(rule.join);
			if (!key) {
				return;
			}
			if (operator !== 'exists' && !value) {
				return;
			}
			out.push({
				join: idx === 0 ? 'and' : join,
				key: key,
				operator: operator,
				value: value
			});
		});
		return out;
	}

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
		return n.toFixed(2) + '%';
	}

	function fmtHms(seconds) {
		var s = Number(seconds || 0);
		if (!isFinite(s) || s < 0) {
			s = 0;
		}
		s = Math.floor(s);
		var hh = Math.floor(s / 3600);
		var mm = Math.floor((s % 3600) / 60);
		var ss = s % 60;
		return String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
	}

	function parseDurationTextToSeconds(text) {
		var t = String(text || '').trim().toLowerCase();
		if (!t) {
			return 0;
		}
		var total = 0;
		var re = /(\d+)\s*([dhms])/g;
		var m;
		while ((m = re.exec(t)) !== null) {
			var n = Number(m[1] || 0);
			var u = String(m[2] || '');
			if (!isFinite(n) || n < 0) {
				continue;
			}
			if (u === 'd') {
				total += n * 86400;
			}
			else if (u === 'h') {
				total += n * 3600;
			}
			else if (u === 'm') {
				total += n * 60;
			}
			else if (u === 's') {
				total += n;
			}
		}
		return total;
	}

	function durationToHms(secondsOrText, fallbackText) {
		var s = Number(secondsOrText || 0);
		if (!isFinite(s) || s < 0) {
			s = 0;
		}
		if (s <= 0 && fallbackText != null) {
			s = parseDurationTextToSeconds(fallbackText);
		}
		return fmtHms(s);
	}

	function getWindowHms(summary) {
		var s = summary && typeof summary === 'object' ? summary : {};
		var fromTs = Number(s.window_from || 0);
		var toTs = Number(s.window_to || 0);
		if (isFinite(fromTs) && isFinite(toTs) && toTs > fromTs) {
			return fmtHms(toTs - fromTs);
		}
		return durationToHms(0, s.window_label || '');
	}

	function setStatus(message, type) {
		jQuery('#dse-status')
			.removeClass('mnz-dse-status-error mnz-dse-status-ok')
			.addClass(type === 'error' ? 'mnz-dse-status-error' : 'mnz-dse-status-ok')
			.text(message || '');
	}

	function readStorageJson(key, fallback) {
		try {
			var raw = window.localStorage.getItem(key);
			if (!raw) {
				return fallback;
			}
			var parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : fallback;
		}
		catch (e) {
			return fallback;
		}
	}

	function writeStorageJson(key, value) {
		try {
			window.localStorage.setItem(key, JSON.stringify(value || {}));
		}
		catch (e) {}
	}

	function removeStorageKeys() {
		try { window.localStorage.removeItem(CACHE_KEYS.options); } catch (e) {}
		try { window.localStorage.removeItem(CACHE_KEYS.calc); } catch (e) {}
	}

	function payloadHash(payload) {
		var obj = {};
		Object.keys(payload || {}).sort().forEach(function(k) {
			obj[k] = payload[k];
		});
		return JSON.stringify(obj);
	}

	function getCachedResponse(storeKey, payload, ttlMs) {
		var cache = readStorageJson(storeKey, {});
		var key = payloadHash(payload);
		var row = cache[key];
		if (!row || typeof row !== 'object') {
			return null;
		}
		var ts = Number(row.ts || 0);
		if (!isFinite(ts) || (Date.now() - ts) > ttlMs) {
			delete cache[key];
			writeStorageJson(storeKey, cache);
			return null;
		}
		return row.data || null;
	}

	function setCachedResponse(storeKey, payload, data) {
		var cache = readStorageJson(storeKey, {});
		var key = payloadHash(payload);
		cache[key] = {
			ts: Date.now(),
			data: data || {}
		};
		writeStorageJson(storeKey, cache);
	}

	function getSavedViews() {
		var views = readStorageJson(CACHE_KEYS.savedViews, []);
		return Array.isArray(views) ? views : [];
	}

	function setSavedViews(views) {
		writeStorageJson(CACHE_KEYS.savedViews, Array.isArray(views) ? views : []);
	}

	function getAudit() {
		var audit = readStorageJson(CACHE_KEYS.audit, {});
		if (!audit || typeof audit !== 'object') {
			return {};
		}
		if (!Array.isArray(audit.trail)) {
			audit.trail = [];
		}
		return audit;
	}

	function setAudit(audit) {
		var clean = audit && typeof audit === 'object' ? audit : {};
		if (!Array.isArray(clean.trail)) {
			clean.trail = [];
		}
		writeStorageJson(CACHE_KEYS.audit, clean);
	}

	function fmtTs(ts) {
		var n = Number(ts || 0);
		if (!isFinite(n) || n <= 0) {
			return '-';
		}
		return new Date(n).toLocaleString();
	}

	function pushAudit(kind, details) {
		var audit = getAudit();
		var now = Date.now();
		if (kind === 'calc') {
			audit.last_calculation = { ts: now, details: details || {} };
		}
		else if (kind === 'clear_cache') {
			audit.last_clear_cache = { ts: now, details: details || {} };
		}
		else if (kind === 'overwrite') {
			audit.last_overwrite = { ts: now, details: details || {} };
		}
		else if (kind === 'import') {
			audit.last_import = { ts: now, details: details || {} };
		}
		else if (kind === 'export') {
			audit.last_export = { ts: now, details: details || {} };
		}

		audit.trail = audit.trail || [];
		audit.trail.unshift({
			ts: now,
			kind: String(kind || 'event'),
			details: details || {}
		});
		audit.trail = audit.trail.slice(0, 20);
		setAudit(audit);
		renderAudit();
	}

	function renderAudit() {
		var audit = getAudit();
		var calc = audit.last_calculation || {};
		var clear = audit.last_clear_cache || {};
		var overwrite = audit.last_overwrite || {};
		var calcInfo = '-';
		if (calc.details && calc.details.window) {
			calcInfo = String(calc.details.window) + (calc.details.source ? (' (' + calc.details.source + ')') : '');
		}
		var overwriteInfo = overwrite.details && overwrite.details.name ? String(overwrite.details.name) : '-';

		var html = '<div class="mnz-dse-panel">' +
			'<div class="mnz-dse-section-title">Audit</div>' +
			'<div class="mnz-dse-audit-grid">' +
				'<div class="mnz-dse-audit-item"><b>Last calculation:</b> ' + escapeHtml(fmtTs(calc.ts)) + ' <span class="mnz-dse-audit-muted">' + escapeHtml(calcInfo) + '</span></div>' +
				'<div class="mnz-dse-audit-item"><b>Last clear cache:</b> ' + escapeHtml(fmtTs(clear.ts)) + '</div>' +
				'<div class="mnz-dse-audit-item"><b>Last overwrite view:</b> ' + escapeHtml(fmtTs(overwrite.ts)) + ' <span class="mnz-dse-audit-muted">' + escapeHtml(overwriteInfo) + '</span></div>' +
			'</div>' +
		'</div>';
		jQuery('#dse-audit').html(html);
	}

	function exportSavedViews() {
		var views = getSavedViews();
		var payload = {
			module: 'inteligence_enterprise',
			exported_at: new Date().toISOString(),
			views: views
		};
		var fileTs = new Date().toISOString().replace(/[:T]/g, '-').replace(/\..+$/, '');
		var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = 'inteligence-enterprise-saved-views-' + fileTs + '.json';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
		pushAudit('export', { count: views.length });
		setStatus('Saved views exported: ' + views.length, 'ok');
	}

	function normalizeImportedViews(raw) {
		var inViews = [];
		if (Array.isArray(raw)) {
			inViews = raw;
		}
		else if (raw && typeof raw === 'object' && Array.isArray(raw.views)) {
			inViews = raw.views;
		}

		var out = [];
		inViews.forEach(function(v) {
			if (!v || typeof v !== 'object') {
				return;
			}
			var name = String(v.name || '').trim();
			var state = v.state && typeof v.state === 'object' ? v.state : null;
			if (!name || !state) {
				return;
			}
			out.push({
				id: String(v.id || (String(Date.now()) + '_' + String(Math.floor(Math.random() * 100000)))),
				name: name,
				state: state,
				created_at: Number(v.created_at || Date.now()),
				updated_at: Number(v.updated_at || 0)
			});
		});
		return out;
	}

	function mergeImportedViews(importedViews) {
		var current = getSavedViews();
		var byName = {};
		current.forEach(function(v) {
			byName[String(v.name || '').toLowerCase()] = v;
		});
		var imported = 0;
		var overwritten = 0;
		importedViews.forEach(function(v) {
			var key = String(v.name || '').toLowerCase();
			if (!key) {
				return;
			}
			if (byName[key]) {
				byName[key].state = v.state;
				byName[key].updated_at = Date.now();
				overwritten++;
			}
			else {
				current.push(v);
				byName[key] = v;
				imported++;
			}
		});
		setSavedViews(current);
		renderSavedViews();
		pushAudit('import', { imported: imported, overwritten: overwritten, total: importedViews.length });
		setStatus('Views imported: ' + imported + ', overwritten: ' + overwritten, 'ok');
	}

	function toStringArray(values) {
		if (values == null) {
			return [];
		}
		if (typeof values === 'string') {
			return values
				.split(/[,\n;\s]+/)
				.map(function(v) { return String(v).trim(); })
				.filter(function(v) { return v !== ''; });
		}
		if (typeof values === 'number') {
			return [String(values)];
		}
		if (!Array.isArray(values)) {
			return [];
		}
		return values.map(function(v) { return String(v); }).filter(function(v) { return v !== ''; });
	}

	function setSelectValues(id, values) {
		var vals = toStringArray(values);
		jQuery(id).val(vals);
		if (jQuery(id).data('mnz-ms-init')) {
			rebuildMsOptions(id);
			updateMsToggle(id);
		}
	}

	function getTagRulesFromDom() {
		var rules = [];
		jQuery('#dse-tag-rules .mnz-dse-tag-row').each(function(idx) {
			var $row = jQuery(this);
			var key = String($row.find('.mnz-dse-tag-key').val() || '').trim();
			var operator = normTagOp($row.find('.mnz-dse-tag-operator').val());
			var value = String($row.find('.mnz-dse-tag-value').val() || '').trim();
			var join = normTagJoin($row.find('.mnz-dse-tag-join').val());
			if (!value && key.indexOf(':') > 0) {
				var p = key.split(':');
				key = String(p.shift() || '').trim();
				value = String(p.join(':') || '').trim();
			}
			if (!value && /\s+/.test(key)) {
				var m = key.match(/^(\S+)\s+(.+)$/);
				if (m) {
					key = String(m[1] || '').trim();
					value = String(m[2] || '').trim();
				}
			}
			if (!key) {
				return;
			}
			if (operator !== 'exists' && !value) {
				return;
			}
			rules.push({
				join: idx === 0 ? 'and' : join,
				key: key,
				operator: operator,
				value: value
			});
		});
		return rules;
	}

	function getTagRulesUiState() {
		var rules = [];
		jQuery('#dse-tag-rules .mnz-dse-tag-row').each(function(idx) {
			var $row = jQuery(this);
			rules.push({
				join: idx === 0 ? 'and' : normTagJoin($row.find('.mnz-dse-tag-join').val()),
				key: String($row.find('.mnz-dse-tag-key').val() || '').trim(),
				operator: normTagOp($row.find('.mnz-dse-tag-operator').val()),
				value: String($row.find('.mnz-dse-tag-value').val() || '').trim()
			});
		});
		return rules;
	}

	function renderTagValuesDatalistForKey(key) {
		var map = tagSuggestions && tagSuggestions.values_by_key ? tagSuggestions.values_by_key : {};
		var vals = Array.isArray(map[String(key || '')]) ? map[String(key || '')] : [];
		var html = '';
		vals.slice(0, 200).forEach(function(v) {
			html += '<option value="' + escapeHtml(String(v)) + '"></option>';
		});
		jQuery('#dse-tag-values-list').html(html);
	}

	function renderTagKeyDatalist(keys) {
		var html = '';
		(Array.isArray(keys) ? keys : []).slice(0, 300).forEach(function(k) {
			html += '<option value="' + escapeHtml(String(k)) + '"></option>';
		});
		jQuery('#dse-tag-keys-list').html(html);
	}

	function renderTagRules(rules) {
		var rows = [];
		(Array.isArray(rules) ? rules : []).forEach(function(rule, idx) {
			if (!rule || typeof rule !== 'object') {
				return;
			}
			rows.push({
				join: idx === 0 ? 'and' : normTagJoin(rule.join),
				key: String(rule.key || '').trim(),
				operator: normTagOp(rule.operator),
				value: String(rule.value || '').trim()
			});
		});
		if (!rows.length) {
			rows = [{ join: 'and', key: '', operator: 'equals', value: '' }];
		}
		var html = '';
		rows.forEach(function(rule, idx) {
			html += '<div class="mnz-dse-tag-row">' +
				'<select class="mnz-dse-tag-join mnz-dse-input"' + (idx === 0 ? ' disabled' : '') + '>' +
					'<option value="and"' + (normTagJoin(rule.join) === 'and' ? ' selected' : '') + '>AND</option>' +
					'<option value="or"' + (normTagJoin(rule.join) === 'or' ? ' selected' : '') + '>OR</option>' +
				'</select>' +
				'<input type="text" class="mnz-dse-input mnz-dse-tag-key" placeholder="tag key (ex: db)" list="dse-tag-keys-list" value="' + escapeHtml(String(rule.key || '')) + '">' +
				'<select class="mnz-dse-tag-operator mnz-dse-input">' +
					'<option value="equals"' + (normTagOp(rule.operator) === 'equals' ? ' selected' : '') + '>equals</option>' +
					'<option value="contains"' + (normTagOp(rule.operator) === 'contains' ? ' selected' : '') + '>contains</option>' +
					'<option value="exists"' + (normTagOp(rule.operator) === 'exists' ? ' selected' : '') + '>exists</option>' +
				'</select>' +
				'<input type="text" class="mnz-dse-input mnz-dse-tag-value" placeholder="tag value (ex: postgresql)" list="dse-tag-values-list" value="' + escapeHtml(String(rule.value || '')) + '"' + (normTagOp(rule.operator) === 'exists' ? ' disabled' : '') + '>' +
				'<button type="button" class="btn-alt mnz-dse-tag-remove"' + (rows.length === 1 ? ' disabled' : '') + '>x</button>' +
			'</div>';
		});
		jQuery('#dse-tag-rules').html(html);
	}

	function captureCurrentViewState() {
		return {
			groupids: toStringArray(jQuery('#dse-groupids').val() || []),
			hostids: toStringArray(jQuery('#dse-hostids').val() || []),
			triggerids: toStringArray(jQuery('#dse-triggerids').val() || []),
			tag_rules: getTagRulesFromDom(),
			exclude_triggerids: String(jQuery('#dse-exclude-triggerids').val() || ''),
			exclude_incidentids: String(jQuery('#dse-exclude-incidentids').val() || ''),
			impact_level: String(jQuery('#dse-impact-level').val() || 'all'),
			period: String(jQuery('#dse-period').val() || '30d'),
			from: String(jQuery('#dse-from').val() || ''),
			to: String(jQuery('#dse-to').val() || ''),
			business_mode: String(jQuery('#dse-business-mode').val() || 'business_mf_8_18'),
			business_start: String(jQuery('#dse-business-start').val() || '08:00'),
			business_end: String(jQuery('#dse-business-end').val() || '18:00'),
			exclude_maintenance: jQuery('#dse-exclude-maintenance').is(':checked') ? 1 : 0,
			slo_target: String(jQuery('#dse-slo-target').val() || '99.9'),
			persona: String(jQuery('#dse-report-persona').val() || 'noc')
		};
	}

	function renderSavedViews() {
		var views = getSavedViews();
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-panel-head"><div class="mnz-dse-section-title">Saved views</div></div>';
		if (!views.length) {
			html += '<div class="mnz-dse-empty">No saved views yet.</div></div>';
			jQuery('#dse-saved-views').html(html);
			return;
		}

		html += '<div class="mnz-dse-saved-tabs">';
		views.forEach(function(view) {
			var id = String(view.id || '');
			var name = String(view.name || 'View');
			var active = (activeSavedViewId && activeSavedViewId === id) ? ' is-active' : '';
			html += '<div class="mnz-dse-saved-tab-wrap">' +
				'<button type="button" class="btn-alt mnz-dse-saved-tab' + active + '" data-view-id="' + escapeHtml(id) + '">' + escapeHtml(name) + '</button>' +
				'<button type="button" class="btn-alt mnz-dse-saved-del" title="Delete view" data-view-del="' + escapeHtml(id) + '">x</button>' +
			'</div>';
		});
		html += '</div></div>';
		jQuery('#dse-saved-views').html(html);
	}

	function saveCurrentView(nameInput) {
		var name = String(nameInput != null ? nameInput : (jQuery('#dse-save-view-name').val() || '')).trim();
		if (!name) {
			setStatus('Type a view name before Save as.', 'error');
			return;
		}

		var views = getSavedViews();
		var state = captureCurrentViewState();
		var lowered = name.toLowerCase();
		var found = -1;
		for (var i = 0; i < views.length; i++) {
			if (String(views[i].name || '').toLowerCase() === lowered) {
				found = i;
				break;
			}
		}

		if (found >= 0) {
			var overwriteId = String(views[found].id || '');
			if (pendingOverwriteViewId !== overwriteId) {
				pendingOverwriteViewId = overwriteId;
				jQuery('#dse-save-view-warning')
					.addClass('is-open')
					.text('A view named "' + name + '" already exists. Click Overwrite to replace it.');
				jQuery('#dse-save-view-confirm').text('Overwrite');
				setStatus('Confirm overwrite for duplicated view name.', 'error');
				return;
			}
			views[found].state = state;
			views[found].updated_at = Date.now();
			activeSavedViewId = String(views[found].id || '');
			pushAudit('overwrite', { name: name });
		}
		else {
			var newId = String(Date.now()) + '_' + String(Math.floor(Math.random() * 100000));
			views.push({
				id: newId,
				name: name,
				state: state,
				created_at: Date.now()
			});
			activeSavedViewId = newId;
		}

		setSavedViews(views);
		renderSavedViews();
		closeSaveAsDialog();
		setStatus('View saved: ' + name, 'ok');
	}

	function resetSaveDialogState() {
		pendingOverwriteViewId = null;
		jQuery('#dse-save-view-warning').removeClass('is-open').text('');
		jQuery('#dse-save-view-confirm').text('Save');
	}

	function openSaveAsDialog() {
		resetSaveDialogState();
		jQuery('#dse-save-view-name').val('').trigger('focus');
		jQuery('#dse-save-overlay').addClass('is-open');
	}

	function closeSaveAsDialog() {
		resetSaveDialogState();
		jQuery('#dse-save-overlay').removeClass('is-open');
	}

	function applySavedView(viewId) {
		var views = getSavedViews();
		var view = null;
		for (var i = 0; i < views.length; i++) {
			if (String(views[i].id || '') === String(viewId)) {
				view = views[i];
				break;
			}
		}
		if (!view || !view.state) {
			setStatus('Saved view not found.', 'error');
			return;
		}

		var state = view.state;
		activeSavedViewId = String(view.id || '');
		jQuery('#dse-save-view-name').val(String(view.name || ''));
		jQuery('#dse-report-persona').val(String(state.persona || 'noc'));
		jQuery('#dse-exclude-triggerids').val(String(state.exclude_triggerids || ''));
		jQuery('#dse-exclude-incidentids').val(String(state.exclude_incidentids || ''));
		jQuery('#dse-impact-level').val(String(state.impact_level || 'all'));
		jQuery('#dse-period').val(String(state.period || '30d'));
		jQuery('#dse-from').val(String(state.from || ''));
		jQuery('#dse-to').val(String(state.to || ''));
		jQuery('#dse-business-mode').val(String(state.business_mode || 'business_mf_8_18'));
		jQuery('#dse-business-start').val(String(state.business_start || '08:00'));
		jQuery('#dse-business-end').val(String(state.business_end || '18:00'));
		jQuery('#dse-exclude-maintenance').prop('checked', Number(state.exclude_maintenance || 0) === 1);
		jQuery('#dse-slo-target').val(String(state.slo_target || '99.9'));
		renderTagRules(state.tag_rules || []);
		applyPeriodPreset();

		setSelectValues('#dse-groupids', state.groupids || state.groupids_csv || state.groupid || []);
		renderSavedViews();

		removeStorageKeys();
		loadOptions('groups', true).always(function() {
			setSelectValues('#dse-hostids', state.hostids || state.hostids_csv || state.hostid || []);
			loadOptions('hosts', true).always(function() {
				setSelectValues('#dse-triggerids', state.triggerids || state.triggerids_csv || state.triggerid || []);
				run(true);
			});
		});
	}

	function isUsableCalcCache(data) {
		if (!data || typeof data !== 'object') {
			return false;
		}
		if (!data.summary || typeof data.summary !== 'object') {
			return false;
		}
		if (!Array.isArray(data.top_triggers) || !Array.isArray(data.hosts_impact)) {
			return false;
		}
		if (!Array.isArray(data.timeline)) {
			return false;
		}
		return true;
	}

	function hasSelectedGroups() {
		var vals = jQuery('#dse-groupids').val() || [];
		if (!Array.isArray(vals)) {
			vals = [vals];
		}
		return vals.filter(Boolean).length > 0;
	}

	function setResultsCollapsed(collapsed) {
		var ids = ['#dse-exec', '#dse-summary', '#dse-insights', '#dse-daily', '#dse-heatmap', '#dse-host-impact', '#dse-top-triggers', '#dse-timeline'];
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

	function impactBand(score) {
		var n = Number(score || 0);
		if (!isFinite(n)) {
			n = 0;
		}
		if (n >= 31) {
			return 'critical';
		}
		if (n >= 21) {
			return 'high';
		}
		if (n >= 11) {
			return 'medium';
		}
		return 'low';
	}

	function incidentTooltipLines(label, incidents) {
		if (!incidents || !incidents.length) {
			return label + '\nIncidents: 0';
		}

		var lines = [label + '\nIncidents: ' + incidents.length];
		var maxLines = 8;
		for (var i = 0; i < incidents.length && i < maxLines; i++) {
			var it = incidents[i] || {};
			var when = it.start ? new Date(it.start * 1000).toLocaleString() : '-';
			lines.push(
				'- ' + String(it.host || '-') +
				' | ' + String(it.trigger_name || '-') +
				' | ' + String(it.severity_label || '-') +
				' | ' + when
			);
		}
		if (incidents.length > maxLines) {
			lines.push('+ ' + (incidents.length - maxLines) + ' more...');
		}
		return lines.join('\n');
	}

	function forEachOverlappedHour(startTs, endTs, windowStart, windowEnd, cb) {
		var s = Number(startTs || 0);
		var e = Number(endTs || 0);
		var ws = Number(windowStart || 0);
		var we = Number(windowEnd || 0);
		if (!isFinite(s) || !isFinite(e) || !isFinite(ws) || !isFinite(we)) {
			return;
		}
		s = Math.max(s, ws);
		e = Math.min(e, we);
		if (e <= s) {
			return;
		}

		var bucket = Math.floor(s / 3600) * 3600;
		while (bucket < e) {
			var bucketEnd = bucket + 3600;
			if (bucketEnd > s && bucket < e) {
				cb(bucket);
			}
			bucket = bucketEnd;
		}
	}

	function forEachBucketByMode(startTs, endTs, windowStart, windowEnd, mode, cb) {
		var s = Number(startTs || 0);
		var e = Number(endTs || 0);
		var ws = Number(windowStart || 0);
		var we = Number(windowEnd || 0);
		if (!isFinite(s) || !isFinite(ws) || !isFinite(we)) {
			return;
		}

		if (mode === 'starts') {
			if (s < ws || s > we) {
				return;
			}
			cb(Math.floor(s / 3600) * 3600);
			return;
		}

		if (!isFinite(e) || e <= 0) {
			e = we;
		}
		forEachOverlappedHour(s, e, ws, we, cb);
	}

	function renderModeSwitch() {
		var startsActive = incidentMode === 'starts' ? ' is-active' : '';
		var durationActive = incidentMode === 'active' ? ' is-active' : '';
		return '<div class="mnz-dse-mode-switch">' +
			'<button type="button" class="btn-alt mnz-dse-mode-btn' + startsActive + '" data-mode="starts">Starts only</button>' +
			'<button type="button" class="btn-alt mnz-dse-mode-btn' + durationActive + '" data-mode="active">Active duration</button>' +
		'</div>';
	}

	function escapeHtml(v) {
		return String(v == null ? '' : v)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function getTablePagerState(tableId, defaultLimit) {
		if (!tablePagerState[tableId]) {
			tablePagerState[tableId] = {
				page: 1,
				limit: Number(defaultLimit || 25)
			};
		}
		return tablePagerState[tableId];
	}

	function applyTableView(tableId) {
		var $table = jQuery('#' + tableId);
		if (!$table.length) {
			return;
		}
		var state = getTablePagerState(tableId, 50);
		var $tbody = $table.find('tbody');
		var rows = $tbody.find('tr').get();
		var $filter = jQuery('.mnz-dse-table-filter[data-filter-target="#' + tableId + '"]');
		var query = String(($filter.val && $filter.val()) || '').toLowerCase().trim();

		var filtered = rows.filter(function(row) {
			if (!query) {
				return true;
			}
			var txt = String(jQuery(row).text() || '').toLowerCase();
			return txt.indexOf(query) >= 0;
		});

		var total = filtered.length;
		var limit = Math.max(1, Number(state.limit || 50));
		var totalPages = Math.max(1, Math.ceil(total / limit));
		if (state.page > totalPages) {
			state.page = totalPages;
		}
		if (state.page < 1) {
			state.page = 1;
		}

		var start = (state.page - 1) * limit;
		var end = start + limit;
		var visibleRows = filtered.slice(start, end);
		var visibleSet = new Set(visibleRows);

		$tbody.find('tr').each(function() {
			var show = visibleSet.has(this);
			jQuery(this).toggle(show);
		});

		var shown = Math.max(0, Math.min(limit, total - start));
		var metaText = 'Total: ' + total + ' | Page ' + state.page + '/' + totalPages + ' | Showing: ' + shown;
		jQuery('.mnz-dse-table-meta[data-table="' + tableId + '"]').text(metaText);
		jQuery('.mnz-dse-table-prev[data-table="' + tableId + '"]').prop('disabled', state.page <= 1);
		jQuery('.mnz-dse-table-next[data-table="' + tableId + '"]').prop('disabled', state.page >= totalPages);
		jQuery('.mnz-dse-table-limit[data-table="' + tableId + '"]').val(String(limit));
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
			exclude_incidentids: jQuery('#dse-exclude-incidentids').val(),
			tag_rules: JSON.stringify(getTagRulesFromDom()),
			impact_level: jQuery('#dse-impact-level').val(),
			business_mode: jQuery('#dse-business-mode').val(),
			business_start: jQuery('#dse-business-start').val(),
			business_end: jQuery('#dse-business-end').val(),
			exclude_maintenance: jQuery('#dse-exclude-maintenance').is(':checked') ? '1' : '0',
			slo_target: jQuery('#dse-slo-target').val(),
			timeline_page: String(problemPagerState.page || 1),
			timeline_limit: String(problemPagerState.limit || 25)
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

	function applyOptionsData(data, selectedGroups, selectedHosts, selectedTriggers, triggeredBy, sourceLabel) {
		tagSuggestions = {
			keys: Array.isArray(data.tag_keys) ? data.tag_keys : [],
			values_by_key: data.tag_values_by_key && typeof data.tag_values_by_key === 'object' ? data.tag_values_by_key : {}
		};
		renderTagKeyDatalist(tagSuggestions.keys);
		var firstKey = jQuery('#dse-tag-rules .mnz-dse-tag-key').first().val() || '';
		renderTagValuesDatalistForKey(firstKey);

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

		var msg = 'Options loaded: groups ' + (data.groups ? data.groups.length : 0) +
			' | hosts ' + (data.hosts ? data.hosts.length : 0) +
			' | triggers ' + (data.triggers ? data.triggers.length : 0);
		if (sourceLabel) {
			msg += ' | ' + sourceLabel;
		}
		setStatus(msg, 'ok');

		if (!hasSelectedGroups()) {
			setResultsCollapsed(true);
			clearDependentSelectors();
			setStatus('Select at least one Host group to enable calculation.', 'ok');
		}
		else {
			setResultsCollapsed(false);
		}
	}

	function loadOptions(triggeredBy, forceFresh) {
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
		else if (triggeredBy === 'hosts' || triggeredBy === 'severity' || triggeredBy === 'period' || triggeredBy === 'impact' || triggeredBy === 'tags') {
			payload.triggerids = '';
		}

		var selectedGroups = jQuery('#dse-groupids').val() || [];
		var selectedHosts = jQuery('#dse-hostids').val() || [];
		var selectedTriggers = jQuery('#dse-triggerids').val() || [];

		var cached = !forceFresh ? getCachedResponse(CACHE_KEYS.options, payload, CACHE_TTL_OPTIONS) : null;
		if (cached && cached.groups && cached.hosts && cached.triggers) {
			applyOptionsData(cached, selectedGroups, selectedHosts, selectedTriggers, triggeredBy, 'cache:hit');
			return jQuery.Deferred().resolve().promise();
		}

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
			setCachedResponse(CACHE_KEYS.options, payload, data);
			var sourceLabel = (data.cache && data.cache.hit) ? 'cache:server' : 'cache:miss';
			applyOptionsData(data, selectedGroups, selectedHosts, selectedTriggers, triggeredBy, sourceLabel);
		}).always(function() {
			jQuery('#dse-refresh-options').prop('disabled', false);
		});
	}

	function renderExecutive(data) {
		var ex = data.executive || {};
		var summary = data.summary || {};
		var risk = String(ex.current_incident_risk || 'Low');
		var riskCls = risk.toLowerCase() === 'high' ? 'mnz-dse-risk-high' : (risk.toLowerCase() === 'medium' ? 'mnz-dse-risk-medium' : 'mnz-dse-risk-low');
		var downtimeSeconds = Number(ex.downtime_window_seconds || summary.downtime_seconds || 0);
		var html = '' +
			'<div class="mnz-dse-panel">' +
				'<div class="mnz-dse-section-title">Executive Summary</div>' +
				'<div class="mnz-dse-cards mnz-dse-cards-exec">' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Current Incident Risk</div><div class="mnz-dse-badge ' + riskCls + '">' + risk + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">SLA Achieved</div><div class="mnz-dse-v">' + fmtPct(ex.sla_achieved) + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Downtime</div><div class="mnz-dse-v">' + durationToHms(downtimeSeconds, ex.downtime_window || summary.downtime || '') + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Incidents</div><div class="mnz-dse-v">' + Number(ex.incidents_this_window || 0) + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Triggers Impacted</div><div class="mnz-dse-v">' + Number(ex.services_impacted || 0) + '</div></div>' +
					'<div class="mnz-dse-card"><div class="mnz-dse-k">Total impact</div><div class="mnz-dse-v mnz-dse-impact-' + impactBand(ex.total_impact_window) + '">' + Number(ex.total_impact_window || 0) + '</div></div>' +
				'</div>' +
			'</div>';
		jQuery('#dse-exec').html(html);
	}

	function renderSummary(data) {
		var s = data.summary || {};
		var gap = parseFloat(s.slo_gap || 0);
		var gapClass = gap >= 0 ? 'mnz-dse-gap-good' : 'mnz-dse-gap-bad';
		var downtimeHms = durationToHms(s.downtime_seconds || 0, s.downtime || '');
		var uptimeHms = durationToHms(s.uptime_seconds || 0, s.uptime || '');
		var mttrHms = durationToHms(s.mttr_seconds || 0, s.mttr || '');
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Dynamic SLA Intelligence</div><div class="mnz-dse-cards">' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Availability</div><div class="mnz-dse-v">' + fmtPct(s.availability) + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">SLO Target</div><div class="mnz-dse-v">' + fmtPct(s.slo_target) + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Gap</div><div class="mnz-dse-v ' + gapClass + '">' + (gap >= 0 ? '+' : '') + (isNaN(gap) ? '-' : gap.toFixed(2) + '%') + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Downtime</div><div class="mnz-dse-v">' + downtimeHms + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">Uptime</div><div class="mnz-dse-v">' + uptimeHms + '</div></div>' +
			'<div class="mnz-dse-card"><div class="mnz-dse-k">MTTR</div><div class="mnz-dse-v">' + mttrHms + '</div></div>' +
			'</div></div>';
		jQuery('#dse-summary').html(html);
	}

	function renderInsights(data) {
		var rows = Array.isArray(data.timeline) ? data.timeline : [];
		if (!rows.length) {
			jQuery('#dse-insights').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">No insights for current filter.</div></div>');
			return;
		}

		var weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
		var bucketCount = {};
		var inBusiness = 0;
		var outBusiness = 0;
		var sevDur = {};
		var nowTs = Math.floor(Date.now() / 1000);
		var last14 = nowTs - (14 * 86400);
		var dayBins = {};

		rows.forEach(function(row) {
			var start = Number(row.effective_start || row.start || 0);
			var end = Number(row.effective_end || row.end || 0);
			if (!isFinite(start) || start <= 0) {
				return;
			}
			if (!isFinite(end) || end <= 0) {
				end = nowTs;
			}
			var dt = new Date(start * 1000);
			var key = weekday[dt.getDay()] + ' ' + String(dt.getHours()).padStart(2, '0') + 'h';
			bucketCount[key] = (bucketCount[key] || 0) + 1;

			var isBusinessDay = dt.getDay() >= 1 && dt.getDay() <= 5;
			var hour = dt.getHours();
			var isBusinessHour = hour >= 8 && hour < 18;
			if (isBusinessDay && isBusinessHour) {
				inBusiness++;
			}
			else {
				outBusiness++;
			}

			var sev = String(row.severity_label || 'Unknown');
			var dur = Math.max(0, (Number(row.end || 0) || nowTs) - start);
			if (!sevDur[sev]) {
				sevDur[sev] = { total: 0, count: 0 };
			}
			sevDur[sev].total += dur;
			sevDur[sev].count += 1;

			if (start >= last14) {
				var dayKey = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
				dayBins[dayKey] = (dayBins[dayKey] || 0) + 1;
			}
		});

		var bestPattern = '-';
		var bestCount = 0;
		Object.keys(bucketCount).forEach(function(k) {
			if (bucketCount[k] > bestCount) {
				bestCount = bucketCount[k];
				bestPattern = k;
			}
		});

		var worstSev = '-';
		var worstMttr = 0;
		Object.keys(sevDur).forEach(function(sev) {
			var avg = sevDur[sev].total / Math.max(1, sevDur[sev].count);
			if (avg > worstMttr) {
				worstMttr = avg;
				worstSev = sev;
			}
		});

		var totalMix = inBusiness + outBusiness;
		var inPct = totalMix > 0 ? (inBusiness / totalMix) * 100 : 0;
		var outPct = totalMix > 0 ? (outBusiness / totalMix) * 100 : 0;
		var inBar = Math.max(0, Math.min(100, inPct));
		var outBar = Math.max(0, Math.min(100, 100 - inBar));

		var last14Values = Object.keys(dayBins).sort().slice(-14).map(function(k) { return dayBins[k]; });
		var burnRate = last14Values.length ? (last14Values.reduce(function(a, b) { return a + b; }, 0) / last14Values.length) : 0;
		var burnRisk = burnRate >= 3 ? 'high' : (burnRate >= 1 ? 'medium' : 'low');

		var bars = '<div class="mnz-dse-mini-bars">';
		for (var i = 0; i < Math.max(14, last14Values.length); i++) {
			var v = last14Values[i] || 0;
			var h = last14Values.length ? Math.max(2, (v / Math.max.apply(null, last14Values.concat([1]))) * 28) : 2;
			bars += '<span class="mnz-dse-mini-bar" style="height:' + h + 'px" title="' + v + ' incidents"></span>';
		}
		bars += '</div>';

		var html = '<div class="mnz-dse-panel">' +
			'<div class="mnz-dse-section-title">Insights Inteligentes</div>' +
			'<div class="mnz-dse-insights-list">' +
				'<div><b>Padrao detectado:</b> ' + escapeHtml(bestPattern) + ' (' + bestCount + ' incidents)</div>' +
				'<div><b>Tendencia:</b> modo <code>' + escapeHtml(incidentMode) + '</code> aplicado nos graficos.</div>' +
				'<div><b>Recomendacao:</b> ' + (outPct > 60 ? 'avaliar janela de suporte fora do horario comercial.' : 'manter monitoracao atual e revisar picos.') + '</div>' +
			'</div>' +
			'<div class="mnz-dse-insights-grid">' +
				'<div class="mnz-dse-insight-card"><div class="mnz-dse-k">Horario comercial vs fora</div><div class="mnz-dse-v2">' + inPct.toFixed(2) + '% / ' + outPct.toFixed(2) + '%</div><div class="mnz-dse-bizbar" title="In business: ' + inPct.toFixed(2) + '% | Outside: ' + outPct.toFixed(2) + '%"><span class="mnz-dse-bizbar-in" style="width:' + inBar.toFixed(2) + '%"></span><span class="mnz-dse-bizbar-out" style="width:' + outBar.toFixed(2) + '%"></span></div><div class="mnz-dse-bizbar-legend"><span><i class="mnz-dse-dot mnz-dse-dot-in"></i>In business</span><span><i class="mnz-dse-dot mnz-dse-dot-out"></i>Outside</span></div></div>' +
				'<div class="mnz-dse-insight-card"><div class="mnz-dse-k">MTTR por severidade (pior)</div><div class="mnz-dse-v2">' + escapeHtml(worstSev) + ' - ' + fmtHms(worstMttr) + '</div></div>' +
				'<div class="mnz-dse-insight-card"><div class="mnz-dse-k">SLO burn rate (14d)</div><div class="mnz-dse-v2">' + burnRate.toFixed(2) + '/dia (' + burnRisk + ')</div>' + bars + '</div>' +
			'</div>' +
		'</div>';
		jQuery('#dse-insights').html(html);
	}

	function renderDaily(data) {
		var rows = Array.isArray(data.timeline) ? data.timeline : [];
		var toTs = Number((data.summary && data.summary.window_to) || Math.floor(Date.now() / 1000));
		if (!isFinite(toTs) || toTs <= 0) {
			toTs = Math.floor(Date.now() / 1000);
		}
		var fromTs = toTs - (24 * 3600);
		var counts = [];
		var details = [];
		for (var i = 0; i < 24; i++) {
			counts.push(0);
			details.push([]);
		}

		rows.forEach(function(row) {
			var start = Number(row.effective_start || row.start || 0);
			var end = Number(row.effective_end || row.end || 0);
			if (!isFinite(end) || end <= 0) {
				end = toTs;
			}
			forEachBucketByMode(start, end, fromTs, toTs, incidentMode, function(bucketTs) {
				var idx = Math.floor((bucketTs - fromTs) / 3600);
				idx = Math.max(0, Math.min(23, idx));
				counts[idx] += 1;
				details[idx].push(row);
			});
		});

		var maxCount = 0;
		counts.forEach(function(v) {
			if (v > maxCount) {
				maxCount = v;
			}
		});

		var bars = '<div class="mnz-dse-daily-bars">';
		for (var hour = 0; hour < 24; hour++) {
			var c = counts[hour];
			var ts = fromTs + (hour * 3600);
			var dt = new Date(ts * 1000);
			var lbl = String(dt.getHours()).padStart(2, '0') + ':00';
			var h;
			if (maxCount <= 0) {
				h = 4;
			}
			else {
				h = Math.max(4, (c / maxCount) * 88);
			}
			var tip = incidentTooltipLines(lbl, details[hour]).replace(/"/g, '&quot;');
			bars += '<div class="mnz-dse-bar-item" title="' + tip + '">' +
				'<span class="mnz-dse-bar-val">' + c + '</span>' +
				'<div class="mnz-dse-bar" style="height:' + h + 'px"></div>' +
				'<span class="mnz-dse-bar-lbl">' + lbl + '</span>' +
			'</div>';
		}
		bars += '</div>';
		jQuery('#dse-daily').html('<div class="mnz-dse-panel"><div class="mnz-dse-panel-head"><div class="mnz-dse-section-title">Daily distribution (last 24h incidents)</div>' + renderModeSwitch() + '</div>' + bars + '</div>');
	}

	function renderHeatmap(data) {
		var rows = Array.isArray(data.timeline) ? data.timeline : [];
		var fromTs = Number((data.summary && data.summary.window_from) || 0);
		var toTs = Number((data.summary && data.summary.window_to) || Math.floor(Date.now() / 1000));
		if (!isFinite(fromTs) || fromTs <= 0 || !isFinite(toTs) || toTs <= fromTs) {
			fromTs = toTs - (30 * 86400);
		}
		if (!rows.length) {
			jQuery('#dse-heatmap').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}

		var weekLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
		var hourLabels = [];
		for (var h = 0; h < 24; h++) {
			hourLabels.push(h + 'h');
		}
		var matrix = [];
		var matrixDetails = [];
		for (var w = 0; w < 7; w++) {
			matrix[w] = [];
			matrixDetails[w] = [];
			for (var hh = 0; hh < 24; hh++) {
				matrix[w][hh] = 0;
				matrixDetails[w][hh] = [];
			}
		}

		rows.forEach(function(row) {
			var start = Number(row.start || 0);
			var end = Number(row.end || 0);
			if (!isFinite(end) || end <= 0) {
				end = toTs;
			}
			forEachBucketByMode(start, end, fromTs, toTs, incidentMode, function(bucketTs) {
				var dt = new Date(bucketTs * 1000);
				var wday = dt.getDay();
				var hour = dt.getHours();
				matrix[wday][hour] += 1;
				matrixDetails[wday][hour].push(row);
			});
		});

		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-panel-head"><div class="mnz-dse-section-title">Incident heatmap (day x hour)</div>' + renderModeSwitch() + '</div><div class="mnz-dse-heatmap-grid">';
		html += '<div class="mnz-dse-heatmap-row mnz-dse-heatmap-head"><div class="mnz-dse-heatmap-corner"></div>';
		hourLabels.forEach(function(lbl) {
			html += '<div class="mnz-dse-heatmap-h">' + String(lbl).replace(/</g, '&lt;') + '</div>';
		});
		html += '</div>';

		matrix.forEach(function(row, widx) {
			html += '<div class="mnz-dse-heatmap-row"><div class="mnz-dse-heatmap-w">' + weekLabels[widx] + '</div>';
			row.forEach(function(v, hidx) {
				var cls = 'mnz-dse-heatmap-cell';
				if (v === 1) {
					cls += ' mnz-dse-heatmap-hit-1';
				}
				else if (v >= 2) {
					cls += ' mnz-dse-heatmap-hit-2';
				}
				var cellLabel = weekLabels[widx] + ' ' + hidx + ':00';
				var tip = incidentTooltipLines(cellLabel, matrixDetails[widx][hidx]).replace(/"/g, '&quot;');
				html += '<div class="' + cls + '" title="' + tip + '">' + v + '</div>';
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

	function appendExcludedIncident(id) {
		var current = String(jQuery('#dse-exclude-incidentids').val() || '').trim();
		var arr = current ? current.split(/[\s,;]+/) : [];
		if (arr.indexOf(String(id)) < 0) {
			arr.push(String(id));
		}
		jQuery('#dse-exclude-incidentids').val(arr.filter(Boolean).join(','));
	}

	function renderTopTriggers(data) {
		var rows = Array.isArray(data.top_triggers) ? data.top_triggers : [];
		if (!rows.length) {
			jQuery('#dse-top-triggers').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}
		var maxImpact = 1;
		rows.forEach(function(row) {
			var impact = Number(row.impact || 0);
			if (isFinite(impact) && impact > maxImpact) {
				maxImpact = impact;
			}
		});

		var tableId = 'dse-top-triggers-table';
		getTablePagerState(tableId, 25);
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Top triggers by downtime</div>' +
			'<div class="mnz-dse-table-tools"><input type="text" class="mnz-dse-table-filter" data-filter-target="#dse-top-triggers-table" placeholder="Filter table..."></div>' +
			'<table class="list-table" id="dse-top-triggers-table"><thead><tr>' +
			'<th class="mnz-dse-sortable" data-key="triggerid" data-type="num">Trigger ID</th>' +
			'<th class="mnz-dse-sortable" data-key="host" data-type="text">Host</th>' +
			'<th class="mnz-dse-sortable" data-key="name" data-type="text">Name</th>' +
			'<th class="mnz-dse-sortable" data-key="startts" data-type="num">Start time</th>' +
			'<th class="mnz-dse-sortable" data-key="severity" data-type="num">Severity</th>' +
			'<th class="mnz-dse-sortable" data-key="impact" data-type="num">Impact</th>' +
			'<th class="mnz-dse-sortable" data-key="downtime" data-type="num">Downtime</th>' +
			'<th>Action</th>' +
			'</tr></thead><tbody>';
		rows.forEach(function(row) {
			var start = row.start ? new Date(row.start * 1000).toLocaleString() : '-';
			var impact = Number(row.impact || 0);
			var pct = Math.max(4, Math.min(100, Math.round((impact / Math.max(1, maxImpact)) * 100)));
			var impactTip = String(row.impact_formula || '').replace(/"/g, '&quot;');
			var band = impactBand(impact);
			html += '<tr data-triggerid="' + Number(row.triggerid || 0) + '"' +
				' data-host="' + escapeHtml(String(row.host || '-').toLowerCase()) + '"' +
				' data-name="' + escapeHtml(String(row.name || '').toLowerCase()) + '"' +
				' data-startts="' + Number(row.start || 0) + '"' +
				' data-severity="' + Number(row.severity || 0) + '"' +
				' data-impact="' + Number(row.impact || 0) + '"' +
				' data-downtime="' + Number(row.downtime_seconds || 0) + '">' +
				'<td>' + row.triggerid + '</td>' +
				'<td>' + String(row.host || '-').replace(/</g, '&lt;') + '</td>' +
				'<td>' + String(row.name || '').replace(/</g, '&lt;') + '</td>' +
				'<td>' + start + '</td>' +
				'<td><span class="mnz-dse-sev ' + severityBadgeClass(row.severity) + '">' + String(row.severity_label || '-').replace(/</g, '&lt;') + '</span></td>' +
				'<td><div class="mnz-dse-impact-wrap" title="' + impactTip + '"><span class="mnz-dse-impact-badge mnz-dse-impact-' + band + '">' + band + '</span><span class="mnz-dse-impact-val">' + Math.round(impact) + '</span><span class="mnz-dse-impact-bar"><span class="mnz-dse-impact-fill mnz-dse-impact-fill-' + band + '" style="width:' + pct + '%"></span></span></div></td>' +
				'<td>' + fmtHms(Number(row.downtime_seconds || parseDurationTextToSeconds(row.downtime || 0))) + '</td>' +
				'<td><button type="button" class="btn-alt mnz-dse-exclude-btn" data-triggerid="' + row.triggerid + '">Exclude</button></td>' +
			'</tr>';
		});
		html += '</tbody></table>' +
			'<div class="mnz-dse-table-footer">' +
				'<div class="mnz-dse-table-meta" data-table="' + tableId + '"></div>' +
				'<div class="mnz-dse-table-pager">' +
					'<label class="mnz-dse-limit-wrap">Limit <select class="mnz-dse-table-limit" data-table="' + tableId + '"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="200">200</option><option value="500">500</option></select></label>' +
					'<button type="button" class="btn-alt mnz-dse-table-prev" data-table="' + tableId + '">&lt; Prev</button>' +
					'<button type="button" class="btn-alt mnz-dse-table-next" data-table="' + tableId + '">Next &gt;</button>' +
				'</div>' +
			'</div>' +
			'</div>';
		jQuery('#dse-top-triggers').html(html);
		applyTableView(tableId);
	}

	function renderHostsImpact(data) {
		var rows = Array.isArray(data.hosts_impact) ? data.hosts_impact : [];
		if (!rows.length) {
			jQuery('#dse-host-impact').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}

		var maxImpact = rows.reduce(function(max, row) {
			var v = Number(row.impact || 0);
			return isFinite(v) && v > max ? v : max;
		}, 1);

		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Top hosts by impact</div><div class="mnz-dse-hostimpact-list">';
		rows.forEach(function(row) {
			var impact = Number(row.impact || 0);
			var pct = Math.max(2, Math.round((impact / Math.max(1, maxImpact)) * 100));
			var band = String(row.impact_level || impactBand(Number(row.impact_avg || impact)));
			var tip = 'Impact: ' + Math.round(impact) + ' | Triggers: ' + Number(row.triggers || 0) + ' | Downtime: ' + durationToHms(row.downtime_seconds || 0, row.downtime || '');
			html += '<div class="mnz-dse-hostimpact-row" title="' + tip.replace(/"/g, '&quot;') + '">' +
				'<span class="mnz-dse-hostimpact-name">' + String(row.host || '-').replace(/</g, '&lt;') + '</span>' +
				'<span class="mnz-dse-hostimpact-bar"><span class="mnz-dse-hostimpact-fill mnz-dse-impact-fill-' + band + '" style="width:' + pct + '%"></span></span>' +
				'<span class="mnz-dse-hostimpact-val"><span class="mnz-dse-impact-badge mnz-dse-impact-' + band + '">' + band + '</span> ' + Math.round(impact) + '</span>' +
			'</div>';
		});
		html += '</div></div>';
		jQuery('#dse-host-impact').html(html);
	}

	function renderTimeline(data) {
		var rows = Array.isArray(data.timeline) ? data.timeline : [];
		var total = Number(data.timeline_total || rows.length || 0);
		if (!isFinite(total) || total < 0) {
			total = rows.length;
		}
		var page = Math.max(1, Number(data.timeline_page || problemPagerState.page || 1));
		var limit = Math.max(10, Number(data.timeline_limit || problemPagerState.limit || 25));
		var pages = Math.max(1, Number(data.timeline_pages || Math.ceil(total / Math.max(1, limit)) || 1));
		problemPagerState.page = page;
		problemPagerState.limit = limit;

		if (!rows.length) {
			jQuery('#dse-timeline').html('<div class="mnz-dse-panel"><div class="mnz-dse-empty">' + (d.noDataLabel || 'No data') + '</div></div>');
			return;
		}

		var tableId = 'dse-timeline-table';
		var html = '<div class="mnz-dse-panel"><div class="mnz-dse-section-title">Problem</div>' +
			'<div class="mnz-dse-table-tools"><input type="text" class="mnz-dse-table-filter" data-filter-target="#dse-timeline-table" placeholder="Filter table..."></div>' +
			'<table class="list-table" id="dse-timeline-table"><thead><tr>' +
			'<th class="mnz-dse-sortable" data-key="incidentid" data-type="num">Incident ID</th>' +
			'<th class="mnz-dse-sortable" data-key="startts" data-type="num">Start time</th>' +
			'<th class="mnz-dse-sortable" data-key="endts" data-type="num">Recovery time</th>' +
			'<th class="mnz-dse-sortable" data-key="status" data-type="text">Status</th>' +
			'<th class="mnz-dse-sortable" data-key="severity" data-type="num">Severity</th>' +
			'<th class="mnz-dse-sortable" data-key="host" data-type="text">Host</th>' +
			'<th class="mnz-dse-sortable" data-key="trigger" data-type="text">Trigger</th>' +
			'<th class="mnz-dse-sortable" data-key="tags" data-type="text">Problem tags</th>' +
			'<th class="mnz-dse-sortable" data-key="duration" data-type="num">Duration</th>' +
			'<th>Action</th>' +
			'</tr></thead><tbody>';
		rows.forEach(function(row) {
			var start = row.start ? new Date(row.start * 1000).toLocaleString() : '-';
			var end = row.end ? new Date(row.end * 1000).toLocaleString() : '-';
			var statusCls = row.status === 'PROBLEM' ? 'mnz-dse-status-bad' : 'mnz-dse-status-good';
			var incidentId = Number(row.incidentid || 0);
			var tagsLabel = String(row.tags_label || '').trim();
			if (!tagsLabel && Array.isArray(row.tags)) {
				tagsLabel = row.tags.map(function(tag) {
					var k = String((tag && tag.tag) || '').trim();
					var v = String((tag && tag.value) || '').trim();
					if (!k) {
						return '';
					}
					return v ? (k + ':' + v) : k;
				}).filter(Boolean).join(' | ');
			}
			if (!tagsLabel) {
				tagsLabel = '-';
			}
			var tagsParts = tagsLabel === '-' ? [] : tagsLabel.split('|').map(function(p) { return String(p).trim(); }).filter(Boolean);
			var tagsDisplay = tagsLabel;
			if (tagsParts.length > 3) {
				tagsDisplay = tagsParts.slice(0, 3).join(' | ') + ' | +' + (tagsParts.length - 3) + ' more';
			}
			html += '<tr data-incidentid="' + incidentId + '"' +
				' data-startts="' + Number(row.start || 0) + '"' +
				' data-endts="' + Number(row.end || 0) + '"' +
				' data-status="' + escapeHtml(String(row.status || '-').toLowerCase()) + '"' +
				' data-severity="' + Number(row.severity || 0) + '"' +
				' data-host="' + escapeHtml(String(row.host || '-').toLowerCase()) + '"' +
				' data-trigger="' + escapeHtml(String(row.trigger_name || '-').toLowerCase()) + '"' +
				' data-tags="' + escapeHtml(String(tagsLabel).toLowerCase()) + '"' +
				' data-duration="' + Number(row.duration_seconds || 0) + '">' +
				'<td>' + (incidentId > 0 ? String(incidentId) : '-') + '</td>' +
				'<td>' + start + '</td>' +
				'<td>' + end + '</td>' +
				'<td><span class="' + statusCls + '">' + String(row.status || '-') + '</span></td>' +
				'<td><span class="mnz-dse-sev ' + severityBadgeClass(row.severity) + '">' + String(row.severity_label || '-').replace(/</g, '&lt;') + '</span></td>' +
				'<td>' + String(row.host || '-').replace(/</g, '&lt;') + '</td>' +
				'<td>' + String(row.trigger_name || '-').replace(/</g, '&lt;') + '</td>' +
				'<td class="mnz-dse-tags-cell" title="' + escapeHtml(tagsLabel) + '">' + escapeHtml(tagsDisplay) + '</td>' +
				'<td>' + fmtHms(Number(row.duration_seconds || 0)) + '</td>' +
				'<td>' + (incidentId > 0 ? '<button type="button" class="btn-alt mnz-dse-exclude-incident-btn" data-incidentid="' + incidentId + '">Exclude</button>' : '-') + '</td>' +
			'</tr>';
		});
		html += '</tbody></table>' +
			'<div class="mnz-dse-table-footer">' +
				'<div class="mnz-dse-table-meta">Total: ' + total + ' | Page ' + page + '/' + pages + ' | Showing: ' + rows.length + '</div>' +
				'<div class="mnz-dse-table-pager">' +
					'<label class="mnz-dse-limit-wrap">Limit <select class="mnz-dse-problem-limit"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="200">200</option><option value="500">500</option></select></label>' +
					'<button type="button" class="btn-alt mnz-dse-problem-prev"' + (page <= 1 ? ' disabled' : '') + '>&lt; Prev</button>' +
					'<button type="button" class="btn-alt mnz-dse-problem-next"' + (page >= pages ? ' disabled' : '') + '>Next &gt;</button>' +
				'</div>' +
			'</div>' +
			'</div>';
		jQuery('#dse-timeline').html(html);
		jQuery('.mnz-dse-problem-limit').val(String(limit));
		applyTableView(tableId);
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

	function toBase64Json(obj) {
		try {
			var json = JSON.stringify(obj || {});
			return btoa(unescape(encodeURIComponent(json)));
		}
		catch (e) {
			return '';
		}
	}

	function openReport(autoPrint) {
		if (!lastCalculatedData) {
			setStatus('Run calculation before generating report.', 'error');
			return;
		}
		var persona = String(jQuery('#dse-report-persona').val() || 'noc');
		var payload = toBase64Json(lastCalculatedData);
		if (!payload) {
			setStatus('Unable to encode report payload.', 'error');
			return;
		}

		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'zabbix.php?action=dynamic.sla.enterprise.report';
		form.target = '_blank';

		function add(name, value) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = name;
			i.value = String(value == null ? '' : value);
			form.appendChild(i);
		}
		add('persona', persona);
		add('auto_print', autoPrint ? '1' : '0');
		add('payload', payload);

		document.body.appendChild(form);
		form.submit();
		document.body.removeChild(form);
	}

	function run(forceFresh) {
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
		var cached = !forceFresh ? getCachedResponse(CACHE_KEYS.calc, payload, CACHE_TTL_CALC) : null;
		if (isUsableCalcCache(cached)) {
			lastCalculatedData = cached;
			problemPagerState.page = Number(cached.timeline_page || problemPagerState.page || 1);
			problemPagerState.limit = Number(cached.timeline_limit || problemPagerState.limit || 25);
			renderExecutive(cached);
			renderSummary(cached);
			renderInsights(cached);
			setTimeout(function() {
				renderDaily(cached);
				renderHeatmap(cached);
				renderHostsImpact(cached);
				renderTopTriggers(cached);
				renderTimeline(cached);
			}, 0);
			pushAudit('calc', { source: 'cache', window: getWindowHms(cached.summary || {}) });
			setStatus('SLA calculation loaded from cache', 'ok');
			btn.prop('disabled', false).text(d.runLabel || 'Calculate SLA');
			return;
		}

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
			lastCalculatedData = res.data;
			problemPagerState.page = Number(res.data.timeline_page || problemPagerState.page || 1);
			problemPagerState.limit = Number(res.data.timeline_limit || problemPagerState.limit || 25);
			setCachedResponse(CACHE_KEYS.calc, payload, res.data);
			renderExecutive(res.data);
			renderSummary(res.data);
			renderInsights(res.data);
			setTimeout(function() {
				renderDaily(res.data);
				renderHeatmap(res.data);
				renderHostsImpact(res.data);
				renderTopTriggers(res.data);
				renderTimeline(res.data);
			}, 0);
			pushAudit('calc', { source: (res.data && res.data.cache && res.data.cache.hit) ? 'server-cache' : 'api', window: getWindowHms((res.data && res.data.summary) ? res.data.summary : {}) });
			setStatus((res.data && res.data.note) ? res.data.note : 'SLA calculation finished', (res.data && res.data.note) ? 'error' : 'ok');
		}).fail(function(jqXHR) {
			setStatus(parseError(jqXHR, d.failedLabel || 'Failed to calculate SLA'), 'error');
		}).always(function() {
			btn.prop('disabled', false).text(d.runLabel || 'Calculate SLA');
		});
	}

	function clearAllCache() {
		removeStorageKeys();
		jQuery.ajax({
			url: 'zabbix.php?action=dynamic.sla.enterprise.data',
			method: 'POST',
			dataType: 'json',
			data: {
				mode: 'clear_cache',
				clear_cache: '1'
			}
		}).always(function() {
			pushAudit('clear_cache', {});
			setStatus('Dashboard cache cleared', 'ok');
		});
	}

	jQuery('#dse-period').on('change', function() {
		problemPagerState.page = 1;
		applyPeriodPreset();
		loadOptions('period');
	});
	jQuery('#dse-from,#dse-to').on('change', function() {
		problemPagerState.page = 1;
		loadOptions('period');
	});
	jQuery('#dse-groupids').on('change', function() {
		problemPagerState.page = 1;
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
		problemPagerState.page = 1;
		loadOptions('hosts');
	});
	jQuery('#dse-severity').on('change', function() {
		problemPagerState.page = 1;
		loadOptions('severity');
	});
	jQuery('#dse-impact-level').on('change', function() {
		problemPagerState.page = 1;
		loadOptions('impact');
	});
	jQuery('#dse-tag-add').on('click', function(e) {
		e.preventDefault();
		var rules = getTagRulesUiState();
		rules.push({ join: 'and', key: '', operator: 'equals', value: '' });
		renderTagRules(rules);
	});
	jQuery(document).on('click', '.mnz-dse-tag-remove', function(e) {
		e.preventDefault();
		var rules = getTagRulesUiState();
		var idx = jQuery(this).closest('.mnz-dse-tag-row').index();
		if (idx >= 0 && idx < rules.length) {
			rules.splice(idx, 1);
		}
		renderTagRules(rules);
		problemPagerState.page = 1;
		loadOptions('tags');
	});
	jQuery(document).on('change', '.mnz-dse-tag-join,.mnz-dse-tag-operator,.mnz-dse-tag-key,.mnz-dse-tag-value', function() {
		var $row = jQuery(this).closest('.mnz-dse-tag-row');
		if ($row.length) {
			var op = normTagOp($row.find('.mnz-dse-tag-operator').val());
			$row.find('.mnz-dse-tag-value').prop('disabled', op === 'exists');
			renderTagValuesDatalistForKey($row.find('.mnz-dse-tag-key').val() || '');
		}
		problemPagerState.page = 1;
		loadOptions('tags');
	});
	jQuery(document).on('focus', '.mnz-dse-tag-value,.mnz-dse-tag-key', function() {
		var $row = jQuery(this).closest('.mnz-dse-tag-row');
		renderTagValuesDatalistForKey($row.find('.mnz-dse-tag-key').val() || '');
	});
	jQuery('#dse-refresh-options').on('click', function(e) {
		e.preventDefault();
		removeStorageKeys();
		loadOptions('manual');
	});
	jQuery('#dse-run').on('click', function(e) {
		e.preventDefault();
		problemPagerState.page = 1;
		run();
	});
	jQuery('#dse-generate-report').on('click', function(e) {
		e.preventDefault();
		openReport(false);
	});
	jQuery('#dse-clear-cache').on('click', function(e) {
		e.preventDefault();
		clearAllCache();
	});
	jQuery('#dse-export-views').on('click', function(e) {
		e.preventDefault();
		exportSavedViews();
	});
	jQuery('#dse-import-views').on('click', function(e) {
		e.preventDefault();
		jQuery('#dse-import-file').trigger('click');
	});
	jQuery('#dse-import-file').on('change', function(e) {
		var file = e.target && e.target.files ? e.target.files[0] : null;
		if (!file) {
			return;
		}
		var reader = new FileReader();
		reader.onload = function(evt) {
			try {
				var parsed = JSON.parse(String((evt && evt.target && evt.target.result) || ''));
				var importedViews = normalizeImportedViews(parsed);
				if (!importedViews.length) {
					setStatus('No valid saved views found in file.', 'error');
				}
				else {
					mergeImportedViews(importedViews);
				}
			}
			catch (err) {
				setStatus('Invalid JSON file for saved views import.', 'error');
			}
			jQuery('#dse-import-file').val('');
		};
		reader.readAsText(file);
	});
	jQuery('#dse-save-view').on('click', function(e) {
		e.preventDefault();
		openSaveAsDialog();
	});
	jQuery('#dse-save-view-confirm').on('click', function(e) {
		e.preventDefault();
		saveCurrentView();
	});
	jQuery('#dse-save-view-cancel').on('click', function(e) {
		e.preventDefault();
		closeSaveAsDialog();
	});
	jQuery('#dse-save-view-name').on('keydown', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			saveCurrentView();
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			closeSaveAsDialog();
		}
	});
	jQuery('#dse-save-view-name').on('input', function() {
		if (pendingOverwriteViewId !== null) {
			resetSaveDialogState();
		}
	});
	jQuery(document).on('change', '.mnz-dse-problem-limit', function() {
		problemPagerState.limit = Math.max(10, Number(jQuery(this).val() || 25));
		problemPagerState.page = 1;
		run(true);
	});
	jQuery(document).on('click', '.mnz-dse-problem-prev', function() {
		problemPagerState.page = Math.max(1, Number(problemPagerState.page || 1) - 1);
		run(true);
	});
	jQuery(document).on('click', '.mnz-dse-problem-next', function() {
		problemPagerState.page = Math.max(1, Number(problemPagerState.page || 1) + 1);
		run(true);
	});
	jQuery('#dse-save-overlay').on('click', function(e) {
		if (e.target === this) {
			closeSaveAsDialog();
		}
	});
	jQuery(document).on('click', '.mnz-dse-saved-tab', function(e) {
		e.preventDefault();
		applySavedView(String(jQuery(this).data('view-id') || ''));
	});
	jQuery(document).on('click', '.mnz-dse-saved-del', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var id = String(jQuery(this).data('view-del') || '');
		if (!id) {
			return;
		}
		var views = getSavedViews().filter(function(v) { return String(v.id || '') !== id; });
		if (activeSavedViewId === id) {
			activeSavedViewId = null;
		}
		setSavedViews(views);
		renderSavedViews();
		setStatus('Saved view removed.', 'ok');
	});
	jQuery(document).on('click', '.mnz-dse-exclude-btn', function() {
		appendExcludedTrigger(jQuery(this).data('triggerid'));
		run();
	});
	jQuery(document).on('click', '.mnz-dse-exclude-incident-btn', function() {
		appendExcludedIncident(jQuery(this).data('incidentid'));
		run();
	});
	jQuery(document).on('click', '.mnz-dse-mode-btn', function(e) {
		e.preventDefault();
		var mode = String(jQuery(this).data('mode') || 'active');
		if (mode !== 'active' && mode !== 'starts') {
			mode = 'active';
		}
		incidentMode = mode;
		if (lastCalculatedData) {
			renderInsights(lastCalculatedData);
			renderDaily(lastCalculatedData);
			renderHeatmap(lastCalculatedData);
		}
	});
	jQuery(document).on('input', '.mnz-dse-table-filter', function() {
		var target = String(jQuery(this).data('filter-target') || '');
		if (!target) {
			return;
		}
		var $table = jQuery(target);
		if (!$table.length) {
			return;
		}
		var tableId = String($table.attr('id') || '');
		if (!tableId) {
			return;
		}
		getTablePagerState(tableId, 50).page = 1;
		applyTableView(tableId);
	});
	jQuery(document).on('click', '.mnz-dse-sortable', function() {
		var $th = jQuery(this);
		var key = String($th.data('key') || '');
		var type = String($th.data('type') || 'text');
		if (!key) {
			return;
		}

		var $table = $th.closest('table');
		var $tbody = $table.find('tbody');
		var rows = $tbody.find('tr').get();
		var currentKey = String($table.data('sortKey') || '');
		var currentDir = String($table.data('sortDir') || 'asc');
		var nextDir = (currentKey === key && currentDir === 'asc') ? 'desc' : 'asc';
		var mul = nextDir === 'asc' ? 1 : -1;

		rows.sort(function(a, b) {
			var $a = jQuery(a);
			var $b = jQuery(b);
			var va = $a.attr('data-' + key);
			var vb = $b.attr('data-' + key);
			if (type === 'num') {
				va = Number(va || 0);
				vb = Number(vb || 0);
				if (!isFinite(va)) { va = 0; }
				if (!isFinite(vb)) { vb = 0; }
			}
			else {
				va = String(va || '').toLowerCase();
				vb = String(vb || '').toLowerCase();
			}
			if (va < vb) { return -1 * mul; }
			if (va > vb) { return 1 * mul; }
			return 0;
		});

		jQuery.each(rows, function(_, row) { $tbody.append(row); });
		$table.data('sortKey', key);
		$table.data('sortDir', nextDir);
		$th.closest('tr').find('.mnz-dse-sortable').removeClass('mnz-dse-sort-asc mnz-dse-sort-desc');
		$th.addClass(nextDir === 'asc' ? 'mnz-dse-sort-asc' : 'mnz-dse-sort-desc');

		var tableId = String($table.attr('id') || '');
		if (tableId) {
			getTablePagerState(tableId, 50).page = 1;
			applyTableView(tableId);
		}
	});
	jQuery(document).on('change', '.mnz-dse-table-limit', function() {
		var tableId = String(jQuery(this).data('table') || '');
		if (!tableId) {
			return;
		}
		var state = getTablePagerState(tableId, 50);
		state.limit = Math.max(1, Number(jQuery(this).val() || 50));
		state.page = 1;
		applyTableView(tableId);
	});
	jQuery(document).on('click', '.mnz-dse-table-prev', function() {
		var tableId = String(jQuery(this).data('table') || '');
		if (!tableId) {
			return;
		}
		var state = getTablePagerState(tableId, 50);
		state.page = Math.max(1, Number(state.page || 1) - 1);
		applyTableView(tableId);
	});
	jQuery(document).on('click', '.mnz-dse-table-next', function() {
		var tableId = String(jQuery(this).data('table') || '');
		if (!tableId) {
			return;
		}
		var state = getTablePagerState(tableId, 50);
		state.page = Number(state.page || 1) + 1;
		applyTableView(tableId);
	});
	jQuery(document).on('click', function() {
		jQuery('.mnz-dse-ms').removeClass('is-open');
	});
	applyPeriodPreset();
	renderTagRules([]);
	setupMultiSelects();
	renderSavedViews();
	renderAudit();
	setResultsCollapsed(true);
	setStatus('Select at least one Host group to enable calculation.', 'ok');
	loadOptions('init').always(function() {
		if (hasSelectedGroups()) {
			setResultsCollapsed(false);
		}
	});
});
