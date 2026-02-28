(function() {
	'use strict';

	var ACTION = 'dynamic.sla.enterprise.view';
	var LABEL = 'Inteligence Enterprise';
	var ITEM_CLASS = 'mnz-dse-nav-item';

	function getActionFromUrl(url) {
		try {
			var u = new URL(url, window.location.origin);
			return u.searchParams.get('action') || '';
		}
		catch (e) {
			return '';
		}
	}

	function createItem() {
		var li = document.createElement('li');
		li.className = ITEM_CLASS;
		var a = document.createElement('a');
		a.href = 'zabbix.php?action=' + ACTION;
		a.textContent = LABEL;
		if (getActionFromUrl(window.location.href) === ACTION) {
			a.classList.add('is-selected', 'selected');
		}
		li.appendChild(a);
		return li;
	}

	function findServicesSubmenu() {
		var candidates = document.querySelectorAll('aside a, nav a, .sidebar a');
		for (var i = 0; i < candidates.length; i++) {
			var a = candidates[i];
			var href = a.getAttribute('href') || '';
			var action = getActionFromUrl(href);
			if (action === 'service.list' || action === 'sla.list' || action === 'sla.report' || action === 'sla.dashboard') {
				var li = a.closest('li');
				if (!li) continue;
				var ul = li.parentElement;
				if (!ul || ul.querySelector('.' + ITEM_CLASS)) continue;
				return ul;
			}
		}
		return null;
	}

	function injectMenuItem() {
		var ul = findServicesSubmenu();
		if (!ul || ul.querySelector('.' + ITEM_CLASS)) return;
		ul.appendChild(createItem());
	}

	function init() {
		injectMenuItem();
		var observer = new MutationObserver(function(mutations) {
			for (var i = 0; i < mutations.length; i++) {
				if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
					injectMenuItem();
					break;
				}
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
