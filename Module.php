<?php declare(strict_types = 0);

namespace Modules\DynamicSlaEnterprise;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
	public function init(): void {
		$menu_item = (new CMenuItem(_('Dynamic SLA Enterprise')))
			->setAction('dynamic.sla.enterprise.view');

		$services_submenu = APP::Component()
			->get('menu.main')
			->findOrAdd(_('Services'))
			->getSubmenu();

		try {
			$services_submenu->insertAfter(_('SLA report'), $menu_item);
		}
		catch (\Throwable $e) {
			$services_submenu->add($menu_item);
		}
	}
}