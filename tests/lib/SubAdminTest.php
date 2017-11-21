<?php
/**
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
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
namespace Test;

class SubAdminTest extends TestCase {

	/** @var \OC\User\Manager */
	private $userManager;

	/** @var \OC\MembershipManager */
	private $membershipManager;

	/** @var \OC\Group\Manager */
	private $groupManager;

	/** @var \OCP\IDBConnection */
	private $dbConn;

	/** @var \OCP\IUser[] */
	private $users;

	/** @var \OCP\IGroup[] */
	private $groups;
	
	public function setup() {
		$this->users = [];
		$this->groups = [];

		$this->userManager = \OC::$server->getUserManager();
		$this->groupManager = \OC::$server->getGroupManager();
		$this->membershipManager = \OC::$server->getMembershipManager();
		$this->dbConn = \OC::$server->getDatabaseConnection();

		// Create 3 users and 3 groups
		for ($i = 0; $i < 3; $i++) {
			$this->users[] = $this->userManager->createUser($this->getUniqueID('user'), 'user');
			$this->groups[] = $this->groupManager->createGroup('group'.$i);
		}

		// Create admin group
		if (!$this->groupManager->groupExists('admin')) {
			$this->groupManager->createGroup('admin');
		}
	}

	public function tearDown() {
		foreach($this->users as $user) {
			$user->delete();
		}

		foreach($this->groups as $group) {
			$group->delete();
		}
		$users = $this->userManager->search('');
		foreach($users as $user) {
			$this->membershipManager->removeMemberships($user->getID());
		}
	}

	public function testCreateSubAdmin() {
		$result = $this->membershipManager->isGroupAdmin($this->users[0]->getUID());
		$this->assertFalse($result);

		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));

		// Look for subadmin in the database
		$result = $this->membershipManager->isGroupAdmin($this->users[0]->getUID());
		$this->assertTrue($result);

		// Delete subadmin
		$this->membershipManager->removeMemberships($this->users[0]->getID());
	}

	public function testDeleteSubAdmin() {
		$result = $this->membershipManager->isGroupAdmin($this->users[0]->getUID());
		$this->assertFalse($result);

		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));

		$result = $this->membershipManager->isGroupAdmin($this->users[0]->getUID());
		$this->assertTrue($result);

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[0]));

		$result = $this->membershipManager->isGroupAdmin($this->users[0]->getUID());
		$this->assertFalse($result);
	}

	public function testGetSubAdminsGroups() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[1]));

		$result = $subAdmin->getSubAdminsGroups($this->users[0]);
		
		$this->assertContains($this->groups[0], $result);
		$this->assertContains($this->groups[1], $result);
		$this->assertNotContains($this->groups[2], $result);
		$this->assertNotContains(null, $result);

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[1]));
	}

	public function testGetGroupsSubAdmins() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->createSubAdmin($this->users[1], $this->groups[0]));

		$result = $subAdmin->getGroupsSubAdmins($this->groups[0]);
		
		$this->assertContains($this->users[0], $result);
		$this->assertContains($this->users[1], $result);
		$this->assertNotContains($this->users[2], $result);
		$this->assertNotContains(null, $result);

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[1], $this->groups[0]));
	}

	public function testGetAllSubAdmin() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);

		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->createSubAdmin($this->users[1], $this->groups[1]));
		$this->assertTrue($subAdmin->createSubAdmin($this->users[2], $this->groups[1]));

		$result = $subAdmin->getAllSubAdmins();

		$this->assertContains(['user' => $this->users[0], 'group' => $this->groups[0]], $result);
		$this->assertContains(['user' => $this->users[1], 'group' => $this->groups[1]], $result);
		$this->assertContains(['user' => $this->users[2], 'group' => $this->groups[1]], $result);
		$this->assertNotContains(['user' => null, 'group' => null], $result);
	}

	public function testIsSubAdminofGroup() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));

		$this->assertTrue($subAdmin->isSubAdminOfGroup($this->users[0], $this->groups[0]));
		$this->assertFalse($subAdmin->isSubAdminOfGroup($this->users[0], $this->groups[1]));
		$this->assertFalse($subAdmin->isSubAdminOfGroup($this->users[1], $this->groups[0]));

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[0]));
	}

	public function testIsSubAdmin() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);

		// There is an orphaned group of which users[0] is subadmin of
		$this->assertNull($this->groupManager->get('orphanedGroup'));

		$this->assertFalse($this->membershipManager->isGroupAdmin($this->users[0]->getUID(), 'orphanedGroup'));
		$this->assertFalse($subAdmin->isSubAdmin($this->users[0]));
		$this->assertEmpty($subAdmin->getSubAdminsGroups($this->users[0]));

		// User users[0] is not a subadmin
		$this->assertFalse($subAdmin->isSubAdmin($this->users[1]));

		$this->assertTrue($subAdmin->createSubAdmin($this->users[1], $this->groups[0]));
		$this->assertTrue($subAdmin->isSubAdmin($this->users[1]));

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[1], $this->groups[0]));
		$this->assertFalse($subAdmin->isSubAdmin($this->users[1]));
	}

	public function testIsSubAdminAsAdmin() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->groupManager->get('admin')->addUser($this->users[1]);

		// User is not subadmin, but is admin
		$this->assertFalse($subAdmin->isSubAdmin($this->users[1]));
		$this->assertTrue($this->groupManager->isAdmin($this->users[1]->getUID()));
	}

	public function testIsUserAccessible() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->groups[0]->addUser($this->users[1]);
		$this->groups[1]->addUser($this->users[1]);
		$this->groups[1]->addUser($this->users[2]);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->createSubAdmin($this->users[2], $this->groups[2]));

		$this->assertTrue($subAdmin->isUserAccessible($this->users[0], $this->users[1]));
		$this->assertFalse($subAdmin->isUserAccessible($this->users[0], $this->users[2]));
		$this->assertFalse($subAdmin->isUserAccessible($this->users[2], $this->users[0]));

		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[0], $this->groups[0]));
		$this->assertTrue($subAdmin->deleteSubAdmin($this->users[2], $this->groups[2]));
	}

	public function testIsUserAccessibleAsUser() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertFalse($subAdmin->isUserAccessible($this->users[0], $this->users[1]));
	}

	public function testIsUserAccessibleAdmin() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);
		$this->assertTrue($subAdmin->createSubAdmin($this->users[0], $this->groups[0]));
		$this->groupManager->get('admin')->addUser($this->users[1]);

		$this->assertFalse($subAdmin->isUserAccessible($this->users[0], $this->users[1]));

	}

	public function testPostDeleteUser() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);

		$user = array_shift($this->users);
		foreach($this->groups as $group) {
			$this->assertTrue($subAdmin->createSubAdmin($user, $group));
		}

		$user->delete();
		$this->assertEmpty($subAdmin->getAllSubAdmins());
	}

	public function testPostDeleteGroup() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);

		$group = array_shift($this->groups);
		foreach($this->users as $user) {
			$this->assertTrue($subAdmin->createSubAdmin($user, $group));
		}

		$group->delete();
		$this->assertEmpty($subAdmin->getAllSubAdmins());
	}

	public function testHooks() {
		$subAdmin = new \OC\SubAdmin($this->userManager, $this->groupManager, $this->membershipManager, $this->dbConn);

		$test = $this;
		$u = $this->users[0];
		$g = $this->groups[0];
		$count = 0;

		$subAdmin->listen('\OC\SubAdmin', 'postCreateSubAdmin', function ($user, $group) use ($test, $u, $g, &$count) {
			$test->assertEquals($u->getUID(), $user->getUID());
			$test->assertEquals($g->getGID(), $group->getGID());
			$count++;
		});

		$subAdmin->listen('\OC\SubAdmin', 'postDeleteSubAdmin', function ($user, $group) use ($test, $u, $g, &$count) {
			$test->assertEquals($u->getUID(), $user->getUID());
			$test->assertEquals($g->getGID(), $group->getGID());
			$count++;
		});

		$this->assertTrue($subAdmin->createSubAdmin($u, $g));
		$this->assertEquals(1, $count);

		$this->assertTrue($subAdmin->deleteSubAdmin($u, $g));
		$this->assertEquals(2, $count);
	}

}
