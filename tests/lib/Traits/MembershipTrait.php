<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace Test\Traits;

use OCP\IConfig;
use Test\Util\MemoryMembershipManager;

/**
 * Allow creating users in a temporary backend
 */
trait MembershipTrait {

	private $previousGroupManagerMembershipManager;
	private $previousUserManagerMembershipManager;


	protected function setUpMembershipTrait() {
		$db = \OC::$server->getDatabaseConnection();
		$config = $this->createMock(IConfig::class);
		$membershipManager = new MemoryMembershipManager($db, $config);
		$membershipManager->testCaseName = get_class($this);
		$this->previousGroupManagerMembershipManager = \OC::$server->getGroupManager()
			->resetMembershipManager($membershipManager);
		$this->previousUserManagerMembershipManager = \OC::$server->getUserManager()
			->resetMembershipManager($membershipManager);

		if ($this->previousGroupManagerMembershipManager instanceof MemoryMembershipManager) {
			throw new \Exception("Missing tearDown call in " . $this->previousGroupManagerMembershipManager->testCaseName);
		}
		if ($this->previousUserManagerMembershipManager instanceof MemoryMembershipManager) {
			throw new \Exception("Missing tearDown call in " . $this->previousUserManagerMembershipManager->testCaseName);
		}
	}

	protected function tearDownMembershipTrait() {
		\OC::$server->getGroupManager()
			->resetMembershipManager($this->previousGroupManagerMembershipManager);
		\OC::$server->getUserManager()
			->resetMembershipManager($this->previousUserManagerMembershipManager);
	}
}
