<?php

/**
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @authod Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH.
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
namespace Test\Group;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OC\Group\BackendGroup;
use OC\Group\Database;
use OC\MembershipManager;
use OC\Group\GroupMapper;
use OC\SubAdmin;
use OC\User\Account;
use OC\User\Manager as UserManager;
use OC\Group\Manager as GroupManager;
use OC\User\User;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\GroupInterface;
use OCP\IDBConnection;

class ManagerTest extends \Test\TestCase {

	/** @var IDBConnection */
	private $connection;

	/** @var MembershipManager | \PHPUnit_Framework_MockObject_MockObject */
	private $membershipManager;

	/** @var UserManager | \PHPUnit_Framework_MockObject_MockObject  */
	private $userManager;

	/** @var GroupMapper | \PHPUnit_Framework_MockObject_MockObject */
	private $groupMapper;

	private function getTestUser($id) {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->expects($this->any())
			->method('getUID')
			->will($this->returnValue('user'.$id));
		$mockUser->expects($this->any())
			->method('getID')
			->will($this->returnValue($id));
		$mockUser->expects($this->any())
			->method('getDisplayName')
			->will($this->returnValue('User '.$id));
		return $mockUser;
	}

	private function getTestBackend($implementedActions = null, $visibleForScopes = null) {
		if (is_null($implementedActions)) {
			$implementedActions =
				GroupInterface::ADD_TO_GROUP |
				GroupInterface::REMOVE_FROM_GOUP |
				GroupInterface::COUNT_USERS |
				GroupInterface::CREATE_GROUP |
				GroupInterface::DELETE_GROUP;
		}
		// need to declare it this way due to optional methods
		// thanks to the implementsActions logic
		$backend = $this->getMockBuilder(\OCP\GroupInterface::class)
			->disableOriginalConstructor()
			->setMethods([
				'getGroupDetails',
				'implementsActions',
				'getUserGroups',
				'inGroup',
				'getGroups',
				'groupExists',
				'usersInGroup',
				'createGroup',
				'deleteGroup',
				'addToGroup',
				'removeFromGroup',
				'isVisibleForScope',
			])
			->getMock();
		$backend->expects($this->any())
			->method('implementsActions')
			->will($this->returnCallback(function($actions) use ($implementedActions) {
				return (bool)($actions & $implementedActions);
			}));
		if (is_null($visibleForScopes)) {
			$backend->expects($this->any())
				->method('isVisibleForScope')
				->willReturn(true);
		} else {
			$backend->expects($this->any())
				->method('isVisibleForScope')
				->will($this->returnValueMap($visibleForScopes));
		}
		return $backend;
	}

	/**
	 * Helper function to create group with some internal id
	 *
	 * @param int $id
	 * @param string $backend
	 * @return BackendGroup
	 */
	private function getBackendGroup($id, $backend) {
		$backendGroup = new BackendGroup();
		$backendGroup->setId($id);
		$backendGroup->setGroupId("group$id");
		$backendGroup->setDisplayName("group$id");
		$backendGroup->setBackend($backend);
		return $backendGroup;
	}

	/**
	 * Helper function to create account with some internal id
	 *
	 * @param int $id
	 * @param string $backend
	 * @return Account
	 */
	private function getAccount($id, $backend) {
		$account = new Account();
		$account->setId($id);
		$account->setUserId("user$id");
		$account->setDisplayName("user$id");
		$account->setBackend($backend);
		return $account;
	}

	public function setUp() {
		parent::setUp();
		$this->connection = $this->createMock(IDBConnection::class);;
		$this->membershipManager = $this->createMock(MembershipManager::class);
		$this->userManager = $this->createMock(UserManager::class);
		$this->groupMapper = $this->createMock(GroupMapper::class);
	}

	public function testGet() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('getGroup')
			->with('group1')
			->willReturn($backendGroup);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());

		// This call will fetch from cache
		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());
		$this->assertTrue($manager->groupExists('group1'));
	}

	public function testGetNotExists() {
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$this->groupMapper->expects($this->exactly(2))
			->method('getGroup')
			->with('group1')
			->willThrowException(new DoesNotExistException(''));

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$this->assertNull($manager->get('group1'));
		$this->assertFalse($manager->groupExists('group1'));
	}

	public function testMultipleBackends() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend1
		 */
		$backend1 = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend1->expects($this->never())
			->method('groupExists');

		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend2
		 */
		$backend2 = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend2->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend1));
		$this->groupMapper->expects($this->exactly(1))
			->method('getGroup')
			->with('group1')
			->willReturn($backendGroup);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend1);
		$manager->addBackend($backend2);

		$group = $manager->get('group1');
		$this->assertNotNull($group);
		$this->assertEquals('group1', $group->getGID());
		$this->assertEquals('group1', $group->getDisplayName());
		$this->assertEquals($backend1, $group->getBackend());

		$backends = $manager->getBackends();
		$this->assertEquals(3, count($backends));
	}

	public function testCreate() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();
		$backend->expects($this->never())
			->method('groupExists');

		// Create group will never be called, since the default database backend is OC\Group\Database
		// One should use createGroupFromBackend
		$backend->expects($this->never())
			->method('createGroup');

		$this->connection->expects($this->exactly(1))
			->method('insertIfNotExist')
			->willReturn(1);

		$backendGroup = $this->getBackendGroup(1, 'OC\Group\Database');
		$this->groupMapper->expects($this->exactly(1))
			->method('getGroup')
			->willThrowException(new DoesNotExistException(''));
		$this->groupMapper->expects($this->exactly(1))
			->method('insert')
			->willReturn($backendGroup);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$group = $manager->createGroup('group1');
		$this->assertEquals(1, $group->getID());
		$this->assertEquals('group1', $group->getGID());
		$this->assertEquals('group1', $group->getDisplayName());
		$this->assertEquals('OC\Group\Database', get_class($group->getBackend()));
	}

	public function testCreateFromBackend() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backendGroupCreated = false;
		$backend = $this->getTestBackend();
		$backend->expects($this->any())
			->method('groupExists')
			->with('group1')
			->will($this->returnCallback(function () use (&$backendGroupCreated) {
				return $backendGroupCreated;
			}));
		$backend->expects($this->once())
			->method('createGroup')
			->will($this->returnCallback(function () use (&$backendGroupCreated) {
				$backendGroupCreated = true;
				return true;
			}));

		$this->groupMapper->expects($this->never())
			->method('getGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('insert')
			->willReturn($backendGroup);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// Group manager is not capable of inserting users in external backends, thus
		// one need to pre create group in the backend and add it using createGroupFromBackend
		$backend->createGroup('group1');

		$group = $manager->createGroupFromBackend('group1', $backend);
		$this->assertEquals('group1', $group->getGID());
		$this->assertEquals('group1', $group->getDisplayName());
		$this->assertEquals($backend, $group->getBackend());
	}

	public function testCreateWrong() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->never())
			->method('createGroup');


		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$group = $manager->createGroup(null);
		$this->assertEquals(null, $group);
	}

	public function testCreateFromBackendWrong() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->never())
			->method('createGroup');

		$this->groupMapper->expects($this->never())
			->method('getGroup');

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$group = $manager->createGroupFromBackend(null, $backend);
		$this->assertEquals(null, $group);
	}

	/**
	 * If group with the same gid exists internally, it should throw exception
	 */
	public function testCreateExistsInternallyExistsInBackend() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Create group will never be called, since the default database backend is OC\Group\Database
		// One should use createGroupFromBackend
		$backend->expects($this->never())
			->method('createGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('getGroup')
			->willReturn($backendGroup);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$exceptionThrown = false;
		try {
			$manager->createGroup('group1');
		} catch (\Exception $exception) {
			$exceptionThrown = true;
		}
		$this->assertTrue($exceptionThrown);
	}

	public function testSearch() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('search')
			->with('1')
			->willReturn([$backendGroup]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->search('1');
		$this->assertEquals(1, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testSearchMultipleBackends() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup1 = $this->getBackendGroup(1, 'OC\Group\Database');
		$backendGroup2 = $this->getBackendGroup(2, get_class($backend));
		$backendGroup3 = $this->getBackendGroup(3, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('search')
			->with('group')
			->willReturn([$backendGroup1, $backendGroup2, $backendGroup3]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->search('group');
		$this->assertEquals(3, count($groups));
		$group1 = reset($groups);
		$group2 = next($groups);
		$group3 = next($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals('OC\Group\Database', get_class($group1->getBackend()));

		$this->assertEquals('group2', $group2->getGID());
		$this->assertEquals($backend, $group2->getBackend());

		$this->assertEquals('group3', $group3->getGID());
		$this->assertEquals($backend, $group3->getBackend());
	}

	public function testSearchMultipleBackendsLimitAndOffset() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup1 = $this->getBackendGroup(1, 'OC\Group\Database');
		$backendGroup2 = $this->getBackendGroup(2, get_class($backend));
		$backendGroup3 = $this->getBackendGroup(3, get_class($backend));
		$this->groupMapper->expects($this->exactly(1))
			->method('search')
			->with('group', 1, 1)
			->willReturn([$backendGroup2]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->search('group', 1, 1);
		$this->assertEquals(1, count($groups));
		$group = reset($groups);
		$this->assertEquals('group2', $group->getGID());
		$this->assertEquals(get_class($backend), get_class($group->getBackend()));

	}

	public function testSearchBackendsForScope() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend(null, [
			[null, false],
			['sharing', true],
		]);

		// Backend in group manager is not involved and won't be called for these functions
		$backend->expects($this->never())
			->method('getGroups');
		$backend->expects($this->never())
			->method('groupExists');

		// this group will be visible for all scopes
		$backendGroup1 = $this->getBackendGroup(1, 'OC\Group\Database');

		// this group will be visible for all scopes
		$backendGroup2 = $this->getBackendGroup(2, get_class($backend));

		// this group will be visible only for sharing scope
		$backendGroup3 = $this->getBackendGroup(3, get_class($backend));
		$this->groupMapper->expects($this->any())
			->method('search')
			->with('group')
			->willReturn([$backendGroup1, $backendGroup2, $backendGroup3]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// search without scope
		$groups = $manager->search('group', null, null, null);
		$this->assertEquals(1, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
		// search with scope
		$groups = $manager->search('group', null, null, 'sharing');
		$this->assertEquals(3, count($groups));
		$group1 = reset($groups);
		$group2 = next($groups);
		$group3 = next($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals('group2', $group2->getGID());
		$this->assertEquals('group3', $group3->getGID());
	}

	public function testGetUserGroups() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->any())
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$this->assertEquals(1, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testGetUserGroupIds() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->any())
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroupIds($this->getTestUser(1));
		$this->assertEquals(1, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1);
	}

	public function testGetUserGroupsWithScope() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend(null, [
			[null, false],
			['sharing', true],
		]);

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->any())
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$this->assertEquals(0, count($groups));

		$groups = $manager->getUserGroups($this->getTestUser(1), 'sharing');
		$this->assertCount(1, $groups);
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
	}

	public function testGetUserGroupsWithNullFalseUser() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$this->membershipManager->expects($this->never())
			->method('getUserBackendGroupsById');

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups(null);
		$this->assertEquals(0, count($groups));

		$groups = $manager->getUserGroups(false);
		$this->assertCount(0, $groups);

		$groups = $manager->getUserGroupIds(null);
		$this->assertCount(0, $groups);

		$groups = $manager->getUserGroupIds(false);
		$this->assertCount(0, $groups);

		$groups = $manager->getUserIdGroups(null);
		$this->assertCount(0, $groups);

		$groups = $manager->getUserIdGroups(false);
		$this->assertCount(0, $groups);
	}

	public function testInGroup() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$this->membershipManager->expects($this->at(0))
			->method('isGroupUser')
			->with('user1', 'group1')
			->willReturn(true);
		$this->membershipManager->expects($this->at(1))
			->method('isGroupUser')
			->with('user1', 'group2')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		/**
		 * @var \OC\User\User $user
		 */
		$user = $this->getTestUser(1);
		$inGroup = $manager->isInGroup($user->getUID(), 'group1');
		$this->assertEquals(true, $inGroup);
		$inGroup = $manager->isInGroup($user->getUID(), 'group2');
		$this->assertEquals(false, $inGroup);
	}

	public function testIsAdmin() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$this->membershipManager->expects($this->at(0))
			->method('isGroupUser')
			->with('user1', 'admin')
			->willReturn(true);
		$this->membershipManager->expects($this->at(1))
			->method('isGroupUser')
			->with('user2', 'admin')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		/**
		 * @var \OC\User\User $user1
		 */
		$user1 = $this->getTestUser(1);
		/**
		 * @var \OC\User\User $user2
		 */
		$user2 = $this->getTestUser(2);
		$inGroup = $manager->isAdmin($user1->getUID());
		$this->assertEquals(true, $inGroup);
		$inGroup = $manager->isAdmin($user2->getUID());
		$this->assertEquals(false, $inGroup);
	}

	public function testGetUserGroupsMultipleBackends() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup1 = $this->getBackendGroup(1, get_class($backend));
		$backendGroup2 = $this->getBackendGroup(2, 'OC\Group\Database');
		$this->membershipManager->expects($this->any())
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup1, $backendGroup2]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$this->assertEquals(2, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals($backend, $group1->getBackend());
		$group2 = next($groups);
		$this->assertEquals('group2', $group2->getGID());
		$this->assertEquals('OC\Group\Database', get_class($group2->getBackend()));
	}

	public function testGetUserGroupsUserNull() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups(null);
		$this->assertEmpty($groups);
	}

	public function testDisplayNamesInGroup() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', -1, 0)
			->willReturn([$account1, $account2]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('group', 'user');
		$this->assertEquals(2, count($users));
		$this->assertTrue(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
	}

	public function testDisplayNamesInGroupWithLimitSpecified() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$account3 = $this->getAccount(3, 'OC\User\Database');
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', 2, 0)
			->willReturn([$account1, $account2]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('group', 'user', 2);
		$this->assertEquals(2, count($users));
		$this->assertTrue(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
	}

	public function testDisplayNamesInGroupWithLimitAndOffsetSpecified() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$account3 = $this->getAccount(3, 'OC\User\Database');
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', 2, 1)
			->willReturn([$account2, $account3]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('group', 'user', 2, 1);
		$this->assertEquals(2, count($users));
		$this->assertTrue(isset($users['user2']));
		$this->assertTrue(isset($users['user3']));
	}

	public function testDisplayNamesInGroupWithSearchEmpty() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$account3 = $this->getAccount(3, 'OC\User\Database');
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', '')
			->willReturn([$account1, $account2, $account3]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->displayNamesInGroup('group', '');
		$this->assertEquals(3, count($users));
		$this->assertTrue(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
		$this->assertTrue(isset($users['user3']));
	}

	public function testGetWithDeletedGroup() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('deleteGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->groupMapper->expects($this->at(0))
			->method('getGroup')
			->with('group1')
			->willReturn($backendGroup);
		$this->groupMapper->expects($this->at(1))
			->method('delete');
		$this->groupMapper->expects($this->at(2))
			->method('getGroup')
			->with('group1')
			->willThrowException(new DoesNotExistException(''));

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// Fetch group, should exists
		$group = $manager->get('group1');
		$this->assertEquals('group1', $group->getGID());

		// Group should emit signal about deletion
		$group->delete();

		// This call should not fetch the group
		$group = $manager->get('group1');
		$this->assertNull($group);
	}

	public function testGetUserGroupsWithDeletedGroup() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('deleteGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->at(0))
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);
		$this->membershipManager->expects($this->at(1))
			->method('removeGroupMembers')
			->with(1)
			->willReturn(true);
		$this->membershipManager->expects($this->at(2))
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// Fetch group, should exists
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());

		// Group should emit signal about deletion
		$group->delete();

		// This call should not fetch the group
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$this->assertEmpty($groups);
	}

	public function testGetUserGroupsWithAddUser() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('addToGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->exactly(2))
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);
		$this->membershipManager->expects($this->once())
			->method('addGroupUser')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// Fetch group, should exists
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());

		// Group should emit signal about adding user
		$group->addUser($this->getTestUser(2));

		// This call should not fetch the group
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testGetUserGroupsWithAddUserFailed() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('addToGroup');

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->once())
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);
		$this->membershipManager->expects($this->once())
			->method('addGroupUser')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		// Fetch group, should exists
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());

		// Group should not emit signal since add user failed
		$group->addUser($this->getTestUser(2));

		// This call should fetch groups, since add user failed
		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testGetUserGroupsWithRemoveUser() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('removeFromGroup')
			->will($this->returnValue(true));

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->exactly(2))
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);
		$this->membershipManager->expects($this->once())
			->method('removeGroupUser')
			->willReturn(true);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());

		$group->removeUser($this->getTestUser(2));

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testGetUserGroupsWithRemoveUserFailed() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');
		$backend->expects($this->once())
			->method('removeFromGroup')
			->will($this->returnValue(true));

		$backendGroup = $this->getBackendGroup(1, get_class($backend));
		$this->membershipManager->expects($this->exactly(1))
			->method('getUserBackendGroupsById')
			->with(1)
			->willReturn([$backendGroup]);
		$this->membershipManager->expects($this->once())
			->method('removeGroupUser')
			->willReturn(false);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());

		$group->removeUser($this->getTestUser(2));

		$groups = $manager->getUserGroups($this->getTestUser(1));
		$group = reset($groups);
		$this->assertEquals('group1', $group->getGID());
	}

	public function testGetUserIdGroups() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('getUserGroups');
		$backend->expects($this->never())
			->method('groupExists');

		$backendGroup1 = $this->getBackendGroup(1, get_class($backend));
		$backendGroup2 = $this->getBackendGroup(2, 'OC\Group\Database');
		$this->membershipManager->expects($this->any())
			->method('getUserBackendGroups')
			->with('user1')
			->willReturn([$backendGroup1, $backendGroup2]);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$groups = $manager->getUserIdGroups('user1');
		$this->assertEquals(2, count($groups));
		$group1 = reset($groups);
		$this->assertEquals('group1', $group1->getGID());
		$this->assertEquals($backend, $group1->getBackend());
		$group2 = next($groups);
		$this->assertEquals('group2', $group2->getGID());
		$this->assertEquals('OC\Group\Database', get_class($group2->getBackend()));
	}

	public function testFindUsersInGroup() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$user1 = $this->createMock(User::class);
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$user2 = $this->createMock(User::class);
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', -1, 0)
			->willReturn([$account1, $account2]);
		$this->userManager->expects($this->at(0))
			->method('getByAccount')
			->with($account1)
			->willReturn($user1);
		$this->userManager->expects($this->at(1))
			->method('getByAccount')
			->with($account2)
			->willReturn($user2);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->findUsersInGroup('group', 'user');
		$this->assertEquals(2, count($users));
		$this->assertTrue(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
	}

	public function testFindUsersInGroupWithLimitSpecified() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$user1 = $this->createMock(User::class);
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$user2 = $this->createMock(User::class);
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', 1, 0)
			->willReturn([$account1]);
		$this->userManager->expects($this->at(0))
			->method('getByAccount')
			->with($account1)
			->willReturn($user1);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->findUsersInGroup('group', 'user', 1);
		$this->assertEquals(1, count($users));
		$this->assertTrue(isset($users['user1']));
	}

	public function testFindUsersInGroupWithLimitAndOffsetSpecified() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$user1 = $this->createMock(User::class);
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$user2 = $this->createMock(User::class);
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', 'user', 1, 1)
			->willReturn([$account2]);
		$this->userManager->expects($this->at(0))
			->method('getByAccount')
			->with($account2)
			->willReturn($user2);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->findUsersInGroup('group', 'user',1,1);
		$this->assertEquals(1, count($users));
		$this->assertTrue(isset($users['user2']));
	}

	public function testFindUsersInGroupWithSearchEmptyAndLimitAndOffsetSpecified() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		// Backend in group manager is not involved and won't be called
		$backend->expects($this->never())
			->method('groupExists');

		$backend->expects($this->never())
			->method('inGroup');

		$userBackend = $this->createMock(\OC_User_Backend::class);
		$account1 = $this->getAccount(1, get_class($userBackend));
		$user1 = $this->createMock(User::class);
		$account2 = $this->getAccount(2, 'OC\User\Database');
		$user2 = $this->createMock(User::class);
		$this->membershipManager->expects($this->any())
			->method('find')
			->with('group', '', 2, 0)
			->willReturn([$account1, $account2]);
		$this->userManager->expects($this->at(0))
			->method('getByAccount')
			->with($account1)
			->willReturn($user1);
		$this->userManager->expects($this->at(1))
			->method('getByAccount')
			->with($account2)
			->willReturn($user2);

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$users = $manager->findUsersInGroup('group', '',2,0);
		$this->assertEquals(2, count($users));
		$this->assertTrue(isset($users['user1']));
		$this->assertTrue(isset($users['user2']));
	}


	public function testIsBackendUsed() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$isUsed = $manager->isBackendUsed(get_class($backend));
		$this->assertTrue($isUsed);

		$manager->clearBackends();

		$isUsed = $manager->isBackendUsed(get_class($backend));
		$this->assertFalse($isUsed);
	}

	public function testGetSubAdmin() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$subAdmin = $manager->getSubAdmin();
		$this->assertInstanceOf(SubAdmin::class, $subAdmin);
	}

	public function testResets() {
		/**
		 * @var \PHPUnit_Framework_MockObject_MockObject | \OC\Group\Backend $backend
		 */
		$backend = $this->getTestBackend();

		$manager = new \OC\Group\Manager($this->userManager, $this->membershipManager, $this->groupMapper, $this->connection);
		$manager->addBackend($backend);

		$array = $manager->reset($this->groupMapper, []);
		$this->assertEquals($array[0], $this->groupMapper);
		$this->assertEquals($array[1][0], $backend);

		$memb = $manager->resetMembershipManager($this->membershipManager);
		$this->assertEquals($memb, $this->membershipManager);
	}
}
