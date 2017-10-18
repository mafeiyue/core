<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author macjohnny <estebanmarin@gmx.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Roman Kreisel <mail@romankreisel.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author voxsim <Simon Vocella>
 * @authod Piotr Mrowczynski <piotr@owncloud.com>
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

namespace OC\Group;

use OC\Group\BackendGroup;
use OC\Hooks\PublicEmitter;
use OC\MembershipManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OC\User\Account;

/**
 * Class Group Manager. This class is responsible for access to the \OC\Group\Group
 * classes and their caching, providing optimal access.
 *
 * Hooks available in scope \OC\Group:
 * - preAddUser(\OC\Group\Group $group, \OC\User\User $user)
 * - postAddUser(\OC\Group\Group $group, \OC\User\User $user)
 * - preRemoveUser(\OC\Group\Group $group, \OC\User\User $user)
 * - postRemoveUser(\OC\Group\Group $group, \OC\User\User $user)
 * - preDelete(\OC\Group\Group $group)
 * - postDelete(\OC\Group\Group $group)
 * - preCreate(string $groupId)
 * - postCreate(\OC\Group\Group $group)
 *
 * @package OC\Group
 */
class Manager extends PublicEmitter implements IGroupManager {
	/** @var \OC\Group\Backend[] $externalBackends */
	private $externalBackends = [];

	/** @var \OC\Group\Backend $internalBackend */
	private $internalBackend;

	/** @var \OC\User\Manager $userManager */
	private $userManager;

	/** @var \OC\MembershipManager $membershipManager */
	private $membershipManager;

	/** @var \OCP\IGroup[] */
	private $cachedGroups = [];

	/** @var array - key is user id and value \OCP\IGroup[] */
	private $cachedUserGroups = [];

	/** @var \OC\SubAdmin */
	private $subAdmin = null;

	/** @var \OC\Group\GroupMapper */
	private $groupMapper;

	/** @var \OCP\IDBConnection */
	private $db;

	/**
	 * @param \OC\User\Manager $userManager
	 * @param \OC\MembershipManager $membershipManager
	 * @param \OC\Group\GroupMapper $groupMapper
	 * @param \OCP\IDBConnection $db
	 */
	public function __construct(\OC\User\Manager $userManager, \OC\MembershipManager $membershipManager, \OC\Group\GroupMapper $groupMapper, \OCP\IDBConnection $db) {
		// Add database backend
		$this->db = $db;
		$this->internalBackend = new \OC\Group\Database($this->db);
		$this->userManager = $userManager;
		$this->groupMapper = $groupMapper;
		$this->membershipManager = $membershipManager;
		$cachedGroups = & $this->cachedGroups;
		$cachedUserGroups = & $this->cachedUserGroups;
		$this->listen('\OC\Group', 'postDelete', function ($group) use (&$cachedGroups, &$cachedUserGroups) {
			/**
			 * @var \OC\Group\Group $group
			 */
			unset($cachedGroups[$group->getGID()]);
			$cachedUserGroups = [];
		});
		$this->listen('\OC\Group', 'postAddUser', function ($group) use (&$cachedUserGroups) {
			/**
			 * @var \OC\Group\Group $group
			 */
			$cachedUserGroups = [];
		});
		$this->listen('\OC\Group', 'postRemoveUser', function ($group) use (&$cachedUserGroups) {
			/**
			 * @var \OC\Group\Group $group
			 */
			$cachedUserGroups = [];
		});
	}

	/**
	 * Get the active backends
	 *
	 * @return \OCP\GroupInterface[]
	 */
	public function getBackends() {
		$backends = [];
		foreach ($this->externalBackends as $backend) {
			$backends[] = $backend;
		}

		$backends[] = $this->internalBackend;
		return $backends;
	}

	/**
	 * @param \OC\Group\Backend $backend
	 */
	public function addBackend($backend) {
		$this->externalBackends[] = $backend;
		$this->clearCaches();
	}

	public function clearBackends() {
		$this->externalBackends = [];
		$this->clearCaches();
	}

	/**
	 * @param string $gid
	 * @return \OCP\IGroup|null
	 */
	public function get($gid) {
		if (isset($this->cachedGroups[$gid])) {
			return $this->cachedGroups[$gid];
		}

		try {
			$backendGroup = $this->groupMapper->getGroup($gid);
			return $this->getByBackendGroup($backendGroup);
		} catch (DoesNotExistException $ex) {
			return null;
		}
	}

	/**
	 * @param string $gid
	 * @return bool
	 */
	public function groupExists($gid) {
		return !is_null($this->get($gid));
	}

	/**
	 * @param string $gid
	 * @return \OCP\IGroup|null
	 */
	public function createGroup($gid) {
		if (!$this->isValid($gid)) {
			return null;
		} else if ($this->groupExists($gid)) {
			$l = \OC::$server->getL10N('lib');
			throw new \Exception($l->t('The group name is already being used'));
		}

		// Create group in the internal backend services
		$this->emit('\OC\Group', 'preCreate', [$gid]);

		// Create group in the external and internal backend services
		// Success of creation on backend is not important, since it can already by created
		$this->internalBackend->createGroup($gid);

		// Try to create group internally, it should not throw any exception, because
		// group does not exists as of $this->get called earlier in the function
		$group = $this->createGroupFromBackendAndCache($gid, $this->internalBackend);
		// Emit post create
		$this->emit('\OC\Group', 'postCreate', [$group]);

		return $group;
	}

	/**
	 * @param string $gid
	 * @param GroupInterface $backend
	 * @return \OCP\IGroup
	 */
	public function createGroupFromBackend($gid, $backend) {
		if (!$this->isValid($gid)) {
			return null;
		}

		// Create group in the internal backend services
		$this->emit('\OC\Group', 'preCreate', [$gid]);

		// Try to create group internally, or throw exception if duplicate
		$group = $this->createGroupFromBackendAndCache($gid, $backend);

		// Emit post create
		$this->emit('\OC\Group', 'postCreate', [$group]);

		return $group;
	}

	/**
	 * @param string $search search string
	 * @param int|null $limit limit
	 * @param int|null $offset offset
	 * @param string|null $scope scope string
	 * @return \OCP\IGroup[] groups
	 */
	public function search($search, $limit = null, $offset = null, $scope = null) {
		// Search for backend groups matching pattern and convert to \OCP\IGroup
		$backendGroups = $this->groupMapper->search($search, $limit, $offset);
		$groups =  array_map(function($backendGroup) {
			// Get Group object for each backend group and cache
			return $this->getByBackendGroup($backendGroup);
		}, $backendGroups);

		// Filter groups for exluded backends in the scope and return group id for each group.
		return $this->filterExcludedBackendsForScope($groups, $scope);
	}

	/**
	 * @param \OC\User\User|null|false $user user
	 * @param string|null $scope scope string
	 * @return \OCP\IGroup[]
	 */
	public function getUserGroups($user, $scope = null) {
		if (!$user) {
			return [];
		}

		/** @var Group[] $groupsForUser */
		$groupsForUser = $this->getUserGroupsCached($user);

		// Filter groups for exluded backends in the scope and return group id for each group.
		return $this->filterExcludedBackendsForScope($groupsForUser, $scope);
	}

	/**
	 * Get a list of group ids for a user
	 *
	 * @param \OC\User\User|null|false $user
	 * @param string|null $scope string
	 * @return array with group ids
	 */
	public function getUserGroupIds($user, $scope = null) {
		/** @var \OCP\IGroup[] $groupsForUser */
		$groupsForUser = $this->getUserGroups($user, $scope);

		// Filter groups for exluded backends in the scope and return group id for each group.
		return array_map(function($group) {
			/** @var Group $group */
			return $group->getGID();
		}, $groupsForUser);
	}

	/**
	 * @param string $uid the user id
	 * @param string|null $scope scope string
	 * @return \OCP\IGroup[]
	 */
	public function getUserIdGroups($uid, $scope = null) {
		if (!$uid) {
			return [];
		}

		/** @var Group[] $groupsForUser */
		$groupsForUser = $this->getUserIdGroupsCached($uid);

		// Filter groups for exluded backends in the scope and return group id for each group.
		return $this->filterExcludedBackendsForScope($groupsForUser, $scope);
	}

	/**
	 * Checks if a userId is in the admin group
	 *
	 * @param string $userId
	 * @return bool if admin
	 */
	public function isAdmin($userId) {
		return $this->membershipManager->isGroupUser($userId, 'admin');
	}

	/**
	 * Checks if a userId is in a group identified by gid
	 *
	 * @param string $userId
	 * @param string $gid
	 * @return bool if in group
	 */
	public function isInGroup($userId, $gid) {
		return $this->membershipManager->isGroupUser($userId, $gid);
	}

	/**
	 * Finds users in a group
	 *
	 * @param string $gid
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OCP\IUser[]
	 */
	public function findUsersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
		/** @var Account[] $accounts */
		$accounts = $this->membershipManager->find($gid, $search, $limit, $offset);

		$matchingUsers = [];
		foreach($accounts as $account) {
			$matchingUsers[$account->getUserId()] = $this->userManager->getByAccount($account);
		}

		return $matchingUsers;
	}

	/**
	 * Get a list of all display names in a group identified by $gid,
	 * which satisfy search predicate
	 *
	 * @param string $gid
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return array an array of display names (value) and user ids (key)
	 */
	public function displayNamesInGroup($gid, $search = '', $limit = -1, $offset = 0) {
		/** @var Account[] $accounts */
		$accounts = $this->membershipManager->find($gid, $search, $limit, $offset);

		$matchingUsers = [];
		foreach($accounts as $account) {
			$matchingUsers[$account->getUserId()] = $account->getDisplayName();
		}
		return $matchingUsers;
	}

	/**
	 * @return \OC\SubAdmin
	 */
	public function getSubAdmin() {
		if (!$this->subAdmin) {
			$this->subAdmin = new \OC\SubAdmin(
				$this->userManager,
				$this,
				$this->membershipManager,
				$this->db
			);
		}

		return $this->subAdmin;
	}

	/**
	 * @param string $gid
	 * @param GroupInterface $backend
	 * @return \OCP\IGroup
	 */
	private function createGroupFromBackendAndCache($gid, $backend) {
		// Add group internally
		$backendGroup = $this->createBackendGroup($gid, $backend);

		// Retrieve group object for newly created backend group
		return $this->getByBackendGroup($backendGroup);
	}

	/**
	 * @param BackendGroup $backendGroup
	 * @return \OCP\IGroup
	 */
	public function getByBackendGroup($backendGroup) {
		$gid = $backendGroup->getGroupId();
		if (!isset($this->cachedGroups[$gid])) {
			$this->cachedGroups[$gid] = new Group($backendGroup, $this->groupMapper, $this, $this->userManager, $this->membershipManager);
		}
		return $this->cachedGroups[$gid];
	}


	/**
	 * @param string $gid
	 * @param GroupInterface $backend
	 * @return BackendGroup|\OCP\AppFramework\Db\Entity
	 */
	private function createBackendGroup($gid, $backend) {
		// Create new backend group, set group id, displayname and backend class
		$backendGroup = new BackendGroup();
		$backendGroup->setGroupId($gid);
		$backendGroup->setDisplayName($gid);
		$backendGroup->setBackend(get_class($backend));

		// Add group internally
		return $this->groupMapper->insert($backendGroup);
	}

	/**
	 * @param string $gid
	 * @return bool
	 */
	private function isValid($gid) {
		if ($gid === '' || is_null($gid)) {
			return false;
		}
		return true;
	}

	/**
	 * Clear all caches used within this manager
	 */
	private function clearCaches() {
		$this->cachedGroups = [];
		$this->cachedUserGroups = [];
	}

	/**
	 * get a list of group ids for a user
	 * @param \OC\User\User$user
	 * @return \OCP\IGroup[]
	 */
	private function getUserGroupsCached($user) {
		if (!isset($this->cachedUserGroups[$user->getUID()])) {
			// Retrieve backend groups for specific user's account internal id
			$accountId = $user->getID();
			$this->cachedUserGroups[$user->getUID()] = array_map(function($backendGroup) {
				// Get \OCP\IGroup object for each backend group and cache
				return $this->getByBackendGroup($backendGroup);
			}, $this->membershipManager->getUserBackendGroupsById($accountId));
		}

		return $this->cachedUserGroups[$user->getUID()];
	}

	/**
	 * get a list of group ids for a user
	 * @param string $userId
	 * @return Group[]
	 */
	private function getUserIdGroupsCached($userId) {
		if (!isset($this->cachedUserGroups[$userId])) {
			// Retrieve backend groups for user id
			$this->cachedUserGroups[$userId] = array_map(function($backendGroup) {
				// Get Group object for each backend group and cache
				return $this->getByBackendGroup($backendGroup);
			}, $this->membershipManager->getUserBackendGroups($userId));
		}

		return $this->cachedUserGroups[$userId];
	}

	/**
	 * Filter groups by backends that opt-out of the given scope
	 *
	 * @param \OCP\IGroup[] $groups groups to filter
	 * @param string|null $scope scope string
	 * @return \OCP\IGroup[] filtered groups
	 */
	private function filterExcludedBackendsForScope($groups, $scope) {
		return array_filter($groups, function($group) use ($scope) {
			/** @var \OCP\IGroup $group */
			if ($backend = $group->getBackend()){
				return $backend->isVisibleForScope($scope);
			}
			return false;
		});
	}

	/**
	 * Checks whether a given backend is used
	 *
	 * @param string $backendClass Full classname including complete namespace
	 *
	 * @deprecated 10.0.0 - use getBackends of \OCP\IGroupManager
	 * @return bool
	 */
	public function isBackendUsed($backendClass) {
		$backendClass = strtolower(ltrim($backendClass, '\\'));
		foreach ($this->getBackends() as $backend) {
			if (strtolower(get_class($backend)) === $backendClass) {
				return true;
			}
		}

		return false;
	}

	/**
	 * only used for unit testing
	 *
	 * @param GroupMapper $mapper
	 * @param array $externalBackends
	 * @return array
	 */
	public function reset(GroupMapper $mapper, $externalBackends) {
		$return = [$this->groupMapper, $this->externalBackends];
		$this->groupMapper = $mapper;
		$this->externalBackends = $externalBackends;

		return $return;
	}

	/**
	 * only used for unit testing
	 *
	 * @param MembershipManager $membershipManager
	 * @return MembershipManager
	 */
	public function resetMembershipManager(MembershipManager $membershipManager) {
		$return = $this->membershipManager;
		$this->membershipManager = $membershipManager;

		return $return;
	}
}
