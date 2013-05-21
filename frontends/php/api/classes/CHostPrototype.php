<?php
/*
** Zabbix
** Copyright (C) 2000-2012 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with host prototypes.
 *
 * @package API
 */
class CHostPrototype extends CHostBase {

	protected $sortColumns = array('hostid', 'host', 'name', 'status');

	public function __construct() {
		parent::__construct();

		$this->getOptions = array_merge($this->getOptions, array(
			'discoveryids'  		=> null,
			'inherited'				=> null,
			'selectDiscoveryRule' 	=> null,
			'selectGroupPrototypes' => null,
			'selectParentHost'		=> null,
			'selectTemplates' 		=> null,
			'selectInventory' 		=> null,
			'editable'				=> null,
			'nopermissions'			=> null,
			'sortfield'    			=> '',
			'sortorder'     		=> ''
		));
	}

	/**
	 * Get host prototypes.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function get(array $options) {
		$options = zbx_array_merge($this->getOptions, $options);
		$options['filter']['flags'] = ZBX_FLAG_DISCOVERY_PROTOTYPE;

		// build and execute query
		$sql = $this->createSelectQuery($this->tableName(), $options);
		$res = DBselect($sql, $options['limit']);

		// fetch results
		$result = array();
		while ($row = DBfetch($res)) {
			// a count query, return a single result
			if ($options['countOutput'] !== null) {
			if ($options['groupCount'] !== null) {
				$result[] = $row;
			}
			else {
				$result = $row['rowscount'];
			}
			}
			// a normal select query
			else {
				$result[$row[$this->pk()]] = $row;
			}
		}

		if ($options['countOutput'] !== null) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
			$result = $this->unsetExtraFields($result, array('triggerid'), $options['output']);
		}

		if ($options['preservekeys'] === null) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Validates the input parameters for the create() method.
	 *
	 * @throws APIException if the input is invalid
	 *
	 * @param array $hostPrototypes
	 *
	 * @return void
	 */
	protected function validateCreate(array $hostPrototypes) {
		// validators
		$hostPrototypeValidator = $this->getHostPrototypValidator();
		$groupPrototypeValidator = $this->getGroupPrototypeValidator();

		$groupPrototypeGroupIds = array();
		foreach ($hostPrototypes as $hostPrototype) {
			// host prototype
			$hostPrototypeValidator->setObjectName(isset($hostPrototype['host']) ? $hostPrototype['host'] : '');
			$this->checkValidator($hostPrototype, $hostPrototypeValidator);

			// group prototypes
			foreach ($hostPrototype['groupPrototypes'] as $groupPrototype) {
				$groupPrototypeValidator->setObjectName(isset($groupPrototype['name']) ? $groupPrototype['name'] : '');
				$this->checkValidator($groupPrototype, $groupPrototypeValidator);

				// save affected host group IDs
				if (isset($groupPrototype['groupid'])) {
					$groupPrototypeGroupIds[$groupPrototype['groupid']] = $groupPrototype['groupid'];
				}
			}
		}

		$this->checkDiscoveryRulePermissions(zbx_objectValues($hostPrototypes, 'ruleid'));
		$this->checkHostGroupsPermissions($groupPrototypeGroupIds);

		// check if the host is discovered
		$discoveryRules = API::getApi()->select('items', array(
			'output' => array('hostid'),
			'itemids' => zbx_objectValues($hostPrototypes, 'ruleid')
		));
		$this->checkValidator(zbx_objectValues($discoveryRules, 'hostid'), new CHostNotDiscoveredValidator(array(
			'message' => _('Cannot create a host prototype on a discovered host "%1$s".')
		)));

		// check if group prototypes use discovered host groups
		$this->checkValidator(array_unique($groupPrototypeGroupIds), new CHostGroupNotDiscoveredValidator(array(
			'message' => _('Group prototype cannot be based on a discovered host group "%1$s".')
		)));

		$this->checkDuplicates($hostPrototypes, 'host', _('Host prototype "%1$s" already exists.'), 'ruleid');
		$this->checkExistingHostPrototypes($hostPrototypes);
	}

	/**
	 * Returns the complete validator for creating a new host prototype.
	 *
	 * @return CSchemaValidator
	 */
	protected function getHostPrototypValidator() {
		return new CSchemaValidator(array(
			'validators' => array(
				'host' => new CLldMacroStringValidator(array(
					'maxLength' => 64,
					'regex' => '/^('.ZBX_PREG_INTERNAL_NAMES.'|\{#'.ZBX_PREG_MACRO_NAME_LLD.'\})+$/',
					'messageEmpty' => _('Empty host.'),
					'messageMaxLength' => _('Host name "%1$s" is too long, it must not be longer than %2$d characters.'),
					'messageRegex' => _('Incorrect characters used for host "%1$s".'),
					'messageMacro' => _('Host name for host prototype "%1$s" must contain macros.')
				)),
				'name' => new CStringValidator(array(
					'messageEmpty' => _('Empty name for host prototype "%1$s".')
				)),
				'status' => new CSetValidator(array(
					'values' => array(HOST_STATUS_MONITORED, HOST_STATUS_NOT_MONITORED),
					'messageInvalid' => _('Incorrect status for host prototype "%1$s".')
				)),
				'ruleid' => new CIdValidator(array(
					'messageEmpty' => _('No discovery rule ID given for host prototype "%1$s".'),
					'messageInvalid' => _('Incorrect discovery rule ID for host prototype "%1$s".')
				)),
				'groupPrototypes' => new CGroupPrototypeCollectionValidator(array(
					'messageEmpty' => _('Host prototype "%1$s" must have at least one host group.'),
					'messageHostGroups' => _('Host prototype "%1$s" must have at least one host group.'),
					'messageDuplicateName' => _('Duplicate group prototype name "%2$s" for host prototype "%1$s".'),
					'messageDuplicateGroupId' => _('Duplicate group prototype group ID "%2$s" for host prototype "%1$s".')
				)),
				'inventory' => null,
				'templates' => null
			),
			'required' => array('host', 'ruleid', 'groupPrototypes'),
			'messageRequired' => _('No "%2$s" given for host prototype "%1$s".'),
			'messageUnsupported' => _('Unsupported parameter "%2$s" for host prototype "%1$s".')
		));
	}

	/**
	 * Returns the complete validator for creating a new group prototype.
	 *
	 * @return CSchemaValidator
	 */
	protected function getGroupPrototypeValidator() {
		return new CSchemaValidator(array(
			'validators' => array(
				'name' => new CLldMacroStringValidator(array(
					'empty' => true,
					'messageMacro' => _('Name for group prototype "%1$s" must contain macros.')
				)),
				'groupid' => new CIdValidator(array(
					'empty' => true,
					'messageInvalid' => _('Incorrect host group ID "%2$s" for group prototype.')
				)),
			),
			'postValidators' => array(new CGroupPrototypeValidator(array(
				'messageMissing' => _('Host group prototype must have either a name or a group ID set.'),
				'messageBoth' => _('Host group prototype "%1$s" cannot have a name and a group ID set at the same time.'),
			))),
			'messageUnsupported' => _('Unsupported parameter "%2$s" for group prototype "%1$s".')
		));
	}

	/**
	 * Creates the given host prototypes.
	 *
	 * @param array $hostPrototypes
	 *
	 * @return array
	 */
	public function create(array $hostPrototypes) {
		$hostPrototypes = zbx_toArray($hostPrototypes);

		foreach ($hostPrototypes as &$hostPrototype) {
			// if the visible name is not set, use the technical name instead
			if (!isset($hostPrototype['name']) || zbx_empty(trim($hostPrototype['name']))) {
				$hostPrototype['name'] = $hostPrototype['host'];
			}

			if (isset($hostPrototype['templates'])) {
				$hostPrototype['templates'] = zbx_toArray($hostPrototype['templates']);
			}
		}
		unset($hostPrototype);

		$this->validateCreate($hostPrototypes);
		$hostPrototypes = $this->createReal($hostPrototypes);
		$this->inherit($hostPrototypes);

		return array('hostids' => zbx_objectValues($hostPrototypes, 'hostid'));
	}

	/**
	 * Creates the host prototypes and inherits them to linked hosts and templates.
	 *
	 * @param array $hostPrototypes
	 *
	 * @return array	an array of host prototypes with host IDs
	 */
	protected function createReal(array $hostPrototypes) {
		foreach ($hostPrototypes as &$hostPrototype) {
			$hostPrototype['flags'] = ZBX_FLAG_DISCOVERY_PROTOTYPE;
		}
		unset($hostPrototype);

		// save the host prototypes
		$hostPrototypeIds = DB::insert($this->tableName(), $hostPrototypes);

		$groupPrototypes = array();
		$hostPrototypeDiscoveryRules = array();
		$hostPrototypeInventory = array();
		foreach ($hostPrototypes as $key => $hostPrototype) {
			$hostPrototypes[$key]['hostid'] = $hostPrototype['hostid'] = $hostPrototypeIds[$key];

			// save group prototypes
			foreach ($hostPrototype['groupPrototypes'] as $groupPrototype) {
				$groupPrototype['hostid'] = $hostPrototype['hostid'];
				$groupPrototypes[] = $groupPrototype;
			}

			// discovery rules
			$hostPrototypeDiscoveryRules[] = array(
				'hostid' => $hostPrototype['hostid'],
				'parent_itemid' => $hostPrototype['ruleid']
			);

			// inventory
			if (isset($hostPrototype['inventory']) && $hostPrototype['inventory']) {
				$hostPrototypeInventory[] = array(
					'hostid' => $hostPrototype['hostid'],
					'inventory_mode' => $hostPrototype['inventory']['inventory_mode']
				);
			}
		}

		// save group prototypes
		$groupPrototypes = DB::save('group_prototype', $groupPrototypes);
		$i = 0;
		foreach ($hostPrototypes as &$hostPrototype) {
			foreach ($hostPrototype['groupPrototypes'] as &$groupPrototype) {
				$groupPrototype['group_prototypeid'] = $groupPrototypes[$i]['group_prototypeid'];
				$i++;
			}
			unset($groupPrototype);
		}
		unset($hostPrototype);

		// link host prototypes to discovery rules
		DB::insert('host_discovery', $hostPrototypeDiscoveryRules, false);

		// save inventory
		DB::insert('host_inventory', $hostPrototypeInventory, false);

		// link templates
		foreach ($hostPrototypes as $hostPrototype) {
			if (isset($hostPrototype['templates']) && $hostPrototype['templates']) {
				$this->link(zbx_objectValues($hostPrototype['templates'], 'templateid'), array($hostPrototype['hostid']));
			}
		}

		// TODO: REMOVE info
		$createdHostPrototypes = $this->get(array(
			'hostids' => $hostPrototypeIds,
			'output' => array('host'),
			'selectParentHost' => array('host'),
			'nopermissions' => true
		));
		foreach ($createdHostPrototypes as $hostProtototype) {
			info(_s('Created: Host prototype "%1$s" on "%2$s".', $hostProtototype['host'], $hostProtototype['parentHost']['host']));
		}

		return $hostPrototypes;
	}

	/**
	 * Validates the input parameters for the update() method.
	 *
	 * @throws APIException if the input is invalid
	 *
	 * @param array $hostPrototypes
	 *
	 * @return void
	 */
	protected function validateUpdate(array $hostPrototypes) {
		foreach ($hostPrototypes as $host) {
			if (empty($host['hostid'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Invalid method parameters.'));
			}
		}

		$hostPrototypes = zbx_toHash($hostPrototypes, 'hostid');

		$this->checkHostPrototypePermissions(zbx_objectValues($hostPrototypes, 'hostid'));

		$hostPrototypes = $this->extendObjects($this->tableName(), $hostPrototypes, array('host'));

		// host prototype validator
		$hostPrototypeValidator = $this->getHostPrototypValidator();
		$hostPrototypeValidator->required = array();
		$hostPrototypeValidator->validators['hostid'] = null;

		// group prototype validator
		$groupPrototypeValidator = $this->getGroupPrototypeValidator();
		$groupPrototypeValidator->required = array();
		$groupPrototypeValidator->validators['group_prototypeid'] = new CIdValidator(array(
			'messageEmpty' => _('Group prototype ID cannot be empty.'),
			'messageInvalid' => _('Incorrect group prototype ID.')
		));

		$groupPrototypeGroupIds = array();
		foreach ($hostPrototypes as $hostPrototype) {
			// host prototype
			$hostPrototypeValidator->setObjectName($hostPrototype['host']);
			$this->checkValidator($hostPrototype, $hostPrototypeValidator);

			// group prototypes
			if (isset($hostPrototype['groupPrototypes'])) {
				foreach ($hostPrototype['groupPrototypes'] as $groupPrototype) {
					$groupPrototypeValidator->setObjectName(isset($groupPrototype['name']) ? $groupPrototype['name'] : '');
					$this->checkValidator($groupPrototype, $groupPrototypeValidator);

					// save affected host group IDs
					$groupPrototypeGroupIds[$groupPrototype['groupid']] = $groupPrototype['groupid'];
				}
			}
		}

		$this->checkHostGroupsPermissions($groupPrototypeGroupIds);

		// check for duplicates
		$relationMap = $this->createRelationMap($hostPrototypes, 'hostid', 'parent_itemid', 'host_discovery');
		$hostPrototypes = $relationMap->mapIdOne($hostPrototypes, 'ruleid');
		$this->checkDuplicates($hostPrototypes, 'host', _('Host prototype "%1$s" already exists.'));
		$this->checkExistingHostPrototypes($hostPrototypes);

		// check if group prototypes use discovered host groups
		$this->checkValidator(array_unique($groupPrototypeGroupIds), new CHostGroupNotDiscoveredValidator(array(
			'message' => _('Group prototype cannot be based on a discovered host group "%1$s".')
		)));
	}

	/**
	 * Updates the given host prototypes.
	 *
	 * @param array $hostPrototypes
	 *
	 * @return array
	 */
	public function update(array $hostPrototypes) {
		$hostPrototypes = zbx_toArray($hostPrototypes);

		$hostPrototypes = $this->extendObjects($this->tableName(), $hostPrototypes, array('host'));
		foreach ($hostPrototypes as &$hostPrototype) {
			if (isset($hostPrototype['templates'])) {
				$hostPrototype['templates'] = zbx_toArray($hostPrototype['templates']);
			}

			// if the visible name is not set, use the technical name instead
			if (isset($hostPrototype['name']) && zbx_empty(trim($hostPrototype['name']))) {
				$hostPrototype['name'] = $hostPrototype['host'];
			}
		}
		unset($hostPrototype);

		$this->validateUpdate($hostPrototypes);
		$hostPrototypes = $this->updateReal($hostPrototypes);

		// load additional data required for inheritance
		$hostPrototypes = zbx_toHash($hostPrototypes, 'hostid');
		$relationMap = $this->createRelationMap($hostPrototypes, 'hostid', 'parent_itemid', 'host_discovery');
		$hostPrototypes = $relationMap->mapIdOne($hostPrototypes, 'ruleid');
		$this->inherit($hostPrototypes);

		return array('hostids' => zbx_objectValues($hostPrototypes, 'hostid'));
	}

	/**
	 * Updates the host prototypes and propagates the changes to linked hosts and templates.
	 *
	 * @param array $hostPrototypes
	 *
	 * @return array
	 */
	protected function updateReal(array $hostPrototypes) {
		// save the host prototypes
		foreach ($hostPrototypes as $hostPrototype) {
			DB::updateByPk($this->tableName(), $hostPrototype['hostid'], $hostPrototype);
		}

		$exHostPrototypes = $this->get(array(
			'output' => array('hostid'),
			'selectGroupPrototypes' => API_OUTPUT_EXTEND,
			'selectTemplates' => array('templateid'),
			'selectInventory' => API_OUTPUT_EXTEND,
			'hostids' => zbx_objectValues($hostPrototypes, 'hostid'),
			'preservekeys' => true
		));

		// update related objects
		$inventoryCreate = array();
		$inventoryDeleteIds = array();
		foreach ($hostPrototypes as $key => $hostPrototype) {
			$exHostPrototype = $exHostPrototypes[$hostPrototype['hostid']];

			// group prototypes
			if (isset($hostPrototype['groupPrototypes'])) {
				foreach ($hostPrototype['groupPrototypes'] as &$groupPrototype) {
					$groupPrototype['hostid'] = $hostPrototype['hostid'];
				}
				unset($groupPrototype);

				// save group prototypes
				$exGroupPrototypes = zbx_toHash($exHostPrototype['groupPrototypes'], 'group_prototypeid');
				$modifiedGroupPrototypes = array();
				foreach ($hostPrototype['groupPrototypes'] as $groupPrototype) {
					if (isset($groupPrototype['group_prototypeid'])) {
						unset($exGroupPrototypes[$groupPrototype['group_prototypeid']]);
					}

					$modifiedGroupPrototypes[] = $groupPrototype;
				}
				if ($exGroupPrototypes) {
					$this->deleteGroupPrototypes(array_keys($exGroupPrototypes));
				}
				$hostPrototypes[$key]['groupPrototypes'] = DB::save('group_prototype', $modifiedGroupPrototypes);
			}

			// templates
			if (isset($hostPrototype['templates'])) {
				$existingTemplateIds = zbx_objectValues($exHostPrototype['templates'], 'templateid');
				$newTemplateIds = zbx_objectValues($hostPrototype['templates'], 'templateid');
				$this->unlink(array_diff($existingTemplateIds, $newTemplateIds), array($hostPrototype['hostid']));
				$this->link(array_diff($newTemplateIds, $existingTemplateIds), array($hostPrototype['hostid']));
			}

			// inventory
			if (isset($hostPrototype['inventory']) ) {
				$inventory = zbx_array_mintersect(array('inventory_mode'), $hostPrototype['inventory']);
				$inventory['hostid'] = $hostPrototype['hostid'];

				if ($hostPrototype['inventory']
					&& (!isset($hostPrototype['inventory']['inventory_mode']) || $hostPrototype['inventory']['inventory_mode'] != HOST_INVENTORY_DISABLED)) {

					if ($exHostPrototype['inventory']) {
						DB::update('host_inventory', array(
							'values' => $inventory,
							'where' => array('hostid' => $inventory['hostid'])
						));
					}
					else {
						$inventoryCreate[] = $inventory;
					}

				}
				else {
					$inventoryDeleteIds[] = $hostPrototype['hostid'];
				}
			}
		}

		// save inventory
		DB::insert('host_inventory', $inventoryCreate, false);
		DB::delete('host_inventory', array('hostid' => $inventoryDeleteIds));

		// TODO: REMOVE info
		$updatedHostPrototypes = $this->get(array(
			'hostids' => zbx_objectValues($hostPrototypes, 'hostid'),
			'output' => array('host'),
			'selectParentHost' => array('host'),
			'nopermissions' => true
		));
		foreach ($updatedHostPrototypes as $hostProtototype) {
			info(_s('Updated: Host prototype "%1$s" on "%2$s".', $hostProtototype['host'], $hostProtototype['parentHost']['host']));
		}

		return $hostPrototypes;
	}

	/**
	 * Updates the children of the host prototypes on the given hosts and propagates the inheritance to the child hosts.
	 *
	 * @param array $hostPrototypes		array of host prototypes to inherit
	 * @param array $hostids   			array of hosts to inherit to; if set to null, the children will be updated on all
	 *                              	child hosts
	 *
	 * @return bool
	 */
	protected function inherit(array $hostPrototypes, array $hostids = null) {
		if (empty($hostPrototypes)) {
			return true;
		}

		// prepare the child host prototypes
		$newHostPrototypes = $this->prepareInheritedObjects($hostPrototypes, $hostids);
		if (!$newHostPrototypes) {
			return true;
		}

		$insertHostPrototypes = array();
		$updateHostPrototypes = array();
		foreach ($newHostPrototypes as $newHostPrototype) {
			if (isset($newHostPrototype['hostid'])) {
				$updateHostPrototypes[] = $newHostPrototype;
			}
			else {
				$insertHostPrototypes[] = $newHostPrototype;
			}
		}

		// save the new host prototypes
		if (!zbx_empty($insertHostPrototypes)) {
			$insertHostPrototypes = $this->createReal($insertHostPrototypes);
		}

		if (!zbx_empty($updateHostPrototypes)) {
			$updateHostPrototypes = $this->updateReal($updateHostPrototypes);
		}

		// propagate the inheritance to the children
		return $this->inherit(array_merge($updateHostPrototypes, $insertHostPrototypes));
	}


	/**
	 * Prepares and returns an array of child host prototypes, inherited from host prototypes $hostPrototypes
	 * on the given hosts.
	 *
	 * Each host prototype must have the "ruleid" parameter set.
	 *
	 * @param array     $hostPrototypes
	 * @param array		$hostIds
	 *
	 * @return array 	an array of unsaved child host prototypes
	 */
	protected function prepareInheritedObjects(array $hostPrototypes, array $hostIds = null) {
		// fetch the related discovery rules with their hosts
		$discoveryRules = API::DiscoveryRule()->get(array(
			'output' => array('itemid', 'hostid'),
			'selectHosts' => array('hostid'),
			'itemids' => zbx_objectValues($hostPrototypes, 'ruleid'),
			'templated' => true,
			'nopermissions' => true,
			'preservekeys' => true
		));

		// fetch all child hosts to inherit to
		// do not inherit host prototypes on discovered hosts
		$chdHosts = API::Host()->get(array(
			'output' => array('hostid', 'host', 'status'),
			'selectParentTemplates' => array('templateid'),
			'templateids' => zbx_objectValues($discoveryRules, 'hostid'),
			'hostids' => $hostIds,
			'nopermissions' => true,
			'templated_hosts' => true,
			'filter' => array('flags' => ZBX_FLAG_DISCOVERY_NORMAL)
		));
		if (empty($chdHosts)) {
			return array();
		}

		// fetch the child discovery rules
		$childDiscoveryRules = API::DiscoveryRule()->get(array(
			'output' => array('itemid', 'templateid', 'hostid'),
			'preservekeys' => true,
			'filter' => array(
				'templateid' => array_keys($discoveryRules)
			)
		));

		// fetch child host prototypes and group them by discovery rule
		$childHostPrototypes = API::HostPrototype()->get(array(
			'output' => array('hostid', 'host', 'templateid'),
			'selectGroupPrototypes' => API_OUTPUT_EXTEND,
			'selectDiscoveryRule' => array('itemid'),
			'discoveryids' => zbx_objectValues($childDiscoveryRules, 'itemid'),
		));
		foreach ($childDiscoveryRules as &$childDiscoveryRule) {
			$childDiscoveryRule['hostPrototypes'] = array();
		}
		unset($childDiscoveryRule);
		foreach ($childHostPrototypes as $childHostPrototype) {
			$discoveryRuleId = $childHostPrototype['discoveryRule']['itemid'];
			unset($childHostPrototype['discoveryRule']);

			$childDiscoveryRules[$discoveryRuleId]['hostPrototypes'][] = $childHostPrototype;
		}

		// match each discovery that the parent host prototypes belong to to the child discovery rule for each host
		$discoveryRuleChildren = array();
		foreach ($childDiscoveryRules as $childRule) {
			$discoveryRuleChildren[$childRule['templateid']][$childRule['hostid']] = $childRule['itemid'];
		}

		$newHostPrototypes = array();
		foreach ($chdHosts as $host) {
			$hostId = $host['hostid'];

			// skip items not from parent templates of current host
			$templateIds = zbx_toHash($host['parentTemplates'], 'templateid');
			$parentHostPrototypes = array();
			foreach ($hostPrototypes as $inum => $parentHostPrototype) {
				$parentTemplateId = $discoveryRules[$parentHostPrototype['ruleid']]['hostid'];

				if (isset($templateIds[$parentTemplateId])) {
					$parentHostPrototypes[$inum] = $parentHostPrototype;
				}
			}

			foreach ($parentHostPrototypes as $parentHostPrototype) {
				$childDiscoveryRuleId = $discoveryRuleChildren[$parentHostPrototype['ruleid']][$hostId];
				$exHostPrototype = null;

				// check if the child discovery rule already has host prototypes
				$exHostPrototypes = $childDiscoveryRules[$childDiscoveryRuleId]['hostPrototypes'];
				if ($exHostPrototypes) {
					$exHostPrototypesHosts = zbx_toHash($exHostPrototypes, 'host');
					$exHostPrototypesTemplateIds = zbx_toHash($exHostPrototypes, 'templateid');

					// look for an already created inherited host prototype
					// if one exists - update it
					if (isset($exHostPrototypesTemplateIds[$parentHostPrototype['hostid']])) {
						$exHostPrototype = $exHostPrototypesTemplateIds[$parentHostPrototype['hostid']];

						// check if there's a host prototype on the target host with the same host name but from a different template
						// or no template
						if (isset($exHostPrototypesHosts[$parentHostPrototype['host']])
							&& !idcmp($exHostPrototypesHosts[$parentHostPrototype['host']]['templateid'], $parentHostPrototype['hostid'])) {

							$discoveryRule = DBfetch(DBselect('SELECT i.name FROM items i WHERE i.itemid='.zbx_dbstr($exHostPrototype['discoveryRule']['itemid'])));
							self::exception(ZBX_API_ERROR_PARAMETERS, _s('Host prototype "%1$s" already exists on "%2$s".', $parentHostPrototype['host'], $discoveryRule['name']));
						}
					}

					// look for a host prototype with the same host name
					// if one exists - convert it to an inherited host prototype
					if (isset($exHostPrototypesHosts[$parentHostPrototype['host']])) {
						$exHostPrototype = $exHostPrototypesHosts[$parentHostPrototype['host']];

						// check that this host prototype is not inherited from a different template
						if ($exHostPrototype['templateid'] > 0 && !idcmp($exHostPrototype['templateid'], $parentHostPrototype['hostid'])) {
							$discoveryRule = DBfetch(DBselect('SELECT i.name FROM items i WHERE i.itemid='.zbx_dbstr($exHostPrototype['discoveryRule']['itemid'])));
							self::exception(ZBX_API_ERROR_PARAMETERS, _s('Host prototype "%1$s" already exists on "%2$s", inherited from another template.', $parentHostPrototype['host'], $discoveryRule['name']));
						}
					}
				}

				// copy host prototype
				$newHostPrototype = $parentHostPrototype;
				$newHostPrototype['ruleid'] = $discoveryRuleChildren[$parentHostPrototype['ruleid']][$hostId];
				$newHostPrototype['templateid'] = $parentHostPrototype['hostid'];

				// update an existing inherited host prototype
				if ($exHostPrototype) {
					// look for existing group prototypes to update
					$exGroupPrototypesByTemplateId = zbx_toHash($exHostPrototype['groupPrototypes'], 'templateid');
					$exGroupPrototypesByName = zbx_toHash($exHostPrototype['groupPrototypes'], 'name');
					$exGroupPrototypesByGroupId = zbx_toHash($exHostPrototype['groupPrototypes'], 'groupid');

					// look for a group prototype that can be updated
					foreach ($newHostPrototype['groupPrototypes'] as &$groupPrototype) {
						// updated an inherited item prototype by templateid
						if (isset($exGroupPrototypesByTemplateId[$groupPrototype['group_prototypeid']])) {
							$groupPrototype['group_prototypeid'] = $exGroupPrototypesByTemplateId[$groupPrototype['group_prototypeid']]['group_prototypeid'];
						}
						// updated an inherited item prototype by name
						elseif (isset($groupPrototype['name']) && !zbx_empty($groupPrototype['name'])
								&& isset($exGroupPrototypesByName[$groupPrototype['name']])) {

							$groupPrototype['templateid'] = $groupPrototype['group_prototypeid'];
							$groupPrototype['group_prototypeid'] = $exGroupPrototypesByName[$groupPrototype['name']]['group_prototypeid'];
						}
						// updated an inherited item prototype by group ID
						elseif (isset($groupPrototype['groupid']) && $groupPrototype['groupid']
								&& isset($exGroupPrototypesByGroupId[$groupPrototype['groupid']])) {

							$groupPrototype['templateid'] = $groupPrototype['group_prototypeid'];
							$groupPrototype['group_prototypeid'] = $exGroupPrototypesByGroupId[$groupPrototype['groupid']]['group_prototypeid'];
						}
						// create a new child group prototype
						else {
							$groupPrototype['templateid'] = $groupPrototype['group_prototypeid'];
							unset($groupPrototype['group_prototypeid']);
						}

						unset($groupPrototype['hostid']);
					}
					unset($groupPrototype);

					$newHostPrototype['hostid'] = $exHostPrototype['hostid'];
				}
				// create a new inherited host prototype
				else {
					foreach ($newHostPrototype['groupPrototypes'] as &$groupPrototype) {
						$groupPrototype['templateid'] = $groupPrototype['group_prototypeid'];
						unset($groupPrototype['group_prototypeid'], $groupPrototype['hostid']);
					}
					unset($groupPrototype);

					unset($newHostPrototype['hostid']);
				}
				$newHostPrototypes[] = $newHostPrototype;
			}
		}

		return $newHostPrototypes;
	}

	/**
	 * Inherits all host prototypes from the templates given in "templateids" to hosts or templates given in "hostids".
	 *
	 * @param array $data
	 *
	 * @return bool
	 */
	public function syncTemplates(array $data) {
		$data['templateids'] = zbx_toArray($data['templateids']);
		$data['hostids'] = zbx_toArray($data['hostids']);

		$discoveryRules = API::DiscoveryRule()->get(array(
			'output' => array('itemid'),
			'hostids' => $data['templateids']
		));
		$hostPrototypes = $this->get(array(
			'discoveryids' => zbx_objectValues($discoveryRules, 'itemid'),
			'preservekeys' => true,
			'output' => API_OUTPUT_EXTEND,
			'selectGroupPrototypes' => API_OUTPUT_EXTEND,
			'selectTemplates' => array('templateid'),
			'selectDiscoveryRule' => array('itemid')
		));

		// the ID of the discovery rule must be passed in the "ruleid" parameter
		foreach ($hostPrototypes as &$hostPrototype) {
			$hostPrototype['ruleid'] = $hostPrototype['discoveryRule']['itemid'];
			unset($hostPrototype['discoveryRule']);
		}
		unset($hostPrototype);

		$this->inherit($hostPrototypes, $data['hostids']);

		return true;
	}

	/**
	 * Validates the input parameters for the delete() method.
	 *
	 * @throws APIException if the input is invalid
	 *
	 * @param array $hostPrototypeIds
	 * @param bool 	$nopermissions
	 *
	 * @return void
	 */
	protected function validateDelete($hostPrototypeIds, $nopermissions) {
		if (!$hostPrototypeIds) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Empty input parameter.'));
		}

		if (!$nopermissions) {
			$this->checkHostPrototypePermissions($hostPrototypeIds);
			$this->checkNotInherited($hostPrototypeIds);
		}
	}

	/**
	 * Delete host prototypes.
	 *
	 * @param string|array 	$hostPrototypeIds
	 * @param bool 			$nopermissions		if set to true, permission and template checks will be skipped
	 *
	 * @return array
	 */
	public function delete($hostPrototypeIds, $nopermissions = false) {
		$hostPrototypeIds = zbx_toArray($hostPrototypeIds);
		$this->validateDelete($hostPrototypeIds, $nopermissions);

		// include child IDs
		$parentHostPrototypeIds = $hostPrototypeIds;
		$childHostPrototypeIds = array();
		do {
			$query = DBselect('SELECT h.hostid FROM hosts h WHERE '.dbConditionInt('h.templateid', $parentHostPrototypeIds));
			$parentHostPrototypeIds = array();
			while ($hostPrototype = DBfetch($query)) {
				$parentHostPrototypeIds[] = $hostPrototype['hostid'];
				$childHostPrototypeIds[] = $hostPrototype['hostid'];
			}
		} while (!empty($parentHostPrototypeIds));

		$hostPrototypeIds = array_merge($hostPrototypeIds, $childHostPrototypeIds);

		$deleteHostPrototypes = $this->get(array(
			'hostids' => $hostPrototypeIds,
			'output' => array('host'),
			'selectGroupPrototypes' => array('group_prototypeid'),
			'selectParentHost' => array('host'),
			'nopermissions' => true
		));

		// delete discovered hosts
		$discoveredHosts = DBfetchArray(DBselect(
			'SELECT hostid FROM host_discovery WHERE '.dbConditionInt('parent_hostid', $hostPrototypeIds)
		));
		if ($discoveredHosts) {
			API::Host()->delete(zbx_objectValues($discoveredHosts, 'hostid'), true);
		}

		// delete group prototypes and discovered groups
		$groupPrototypeIds = array();
		foreach ($deleteHostPrototypes as $groupPrototype) {
			foreach ($groupPrototype['groupPrototypes'] as $groupPrototype) {
				$groupPrototypeIds[] = $groupPrototype['group_prototypeid'];
			}
		}
		$this->deleteGroupPrototypes($groupPrototypeIds);

		// delete host prototypes
		DB::delete($this->tableName(), array('hostid' => $hostPrototypeIds));

		// TODO: REMOVE info
		foreach ($deleteHostPrototypes as $hostProtototype) {
			info(_s('Deleted: Host prototype "%1$s" on "%2$s".', $hostProtototype['host'], $hostProtototype['parentHost']['host']));
		}

		return array('hostids' => $hostPrototypeIds);
	}

	/**
	 * Returns true if all of the given objects are available for reading.
	 *
	 * @param $ids
	 *
	 * @return bool
	 */
	public function isReadable(array $ids) {
		if (empty($ids)) {
			return true;
		}
		$ids = array_unique($ids);

		$count = $this->get(array(
			'hostids' => $ids,
			'countOutput' => true
		));
		return count($ids) == $count;
	}

	/**
	 * Returns true if all of the given objects are available for writing.
	 *
	 * @param $ids
	 *
	 * @return bool
	 */
	public function isWritable(array $ids) {
		if (empty($ids)) {
			return true;
		}
		$ids = array_unique($ids);

		$count = $this->get(array(
			'hostids' => $ids,
			'editable' => true,
			'countOutput' => true
		));
		return count($ids) == $count;
	}

	protected function link(array $templateids, array $targetids) {
		if (!$this->isWritable($targetids)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		parent::link($templateids, $targetids);
	}

	/**
	 * Checks if the current user has access to the given LLD rules.
	 *
	 * @throws APIException if the user doesn't have write permissions for the given LLD rules
	 *
	 * @param array $discoveryRuleIds
	 */
	protected function checkDiscoveryRulePermissions(array $discoveryRuleIds) {
		if (!API::DiscoveryRule()->isWritable($discoveryRuleIds)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * Checks if the current user has access to the given host groups.
	 *
	 * @throws APIException if the user doesn't have write permissions for the given host groups
	 *
	 * @param array $hostGroupIds
	 */
	protected function checkHostGroupsPermissions(array $hostGroupIds) {
		if (!API::HostGroup()->isWritable($hostGroupIds)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * Checks if the current user has access to the given host prototypes.
	 *
	 * @throws APIException if the user doesn't have write permissions for the host prototypes.
	 *
	 * @param array $hostPrototypeIds
	 */
	protected function checkHostPrototypePermissions(array $hostPrototypeIds) {
		if (!$this->isWritable($hostPrototypeIds)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * Checks if the given host prototypes are not inherited from a template.
	 *
	 * @throws APIException 	if at least one host prototype is iherited
	 *
	 * @param array $hostPrototypeIds
	 */
	protected function checkNotInherited(array $hostPrototypeIds) {
		$query = DBSelect('SELECT hostid FROM hosts h WHERE h.templateid>0 AND '.dbConditionInt('h.hostid', $hostPrototypeIds), 1);

		if ($hostPrototype = DBfetch($query)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('Cannot delete templated host prototype.'));
		}
	}

	/**
	 * Check if any item from list already exists.
	 * If items have item ids it will check for existing item with different itemid.
	 *
	 * @throw APIException
	 *
	 * @param array $hostPrototypes
	 */
	protected function checkExistingHostPrototypes(array $hostPrototypes) {
		$hostsByDiscoveryRuleId = array();
		$hostIds = array();
		foreach ($hostPrototypes as $hostPrototype) {
			$hostsByDiscoveryRuleId[$hostPrototype['ruleid']][] = $hostPrototype['host'];

			if (isset($hostPrototype['hostid'])) {
				$hostIds[] = $hostPrototype['hostid'];
			}
		}

		$sqlWhere = array();
		foreach ($hostsByDiscoveryRuleId as $discoveryRuleId => $hosts) {
			$sqlWhere[] = '(hd.parent_itemid='.$discoveryRuleId.' AND '.dbConditionString('h.host', $hosts).')';
		}

		if ($sqlWhere) {
			$sql = 'SELECT i.name,h.host'.
				' FROM hosts h,host_discovery hd,items i'.
				' WHERE h.hostid=hd.hostid AND hd.parent_itemid=i.itemid AND ('.implode(' OR ', $sqlWhere).')';

			// if we update existing items we need to exclude them from result.
			if ($hostIds) {
				$sql .= ' AND '.dbConditionInt('h.hostid', $hostIds, true);
			}
			$query = DBselect($sql, 1);
			while ($row = DBfetch($query)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Host prototype "%1$s" already exists in discovery rule "%2$s".', $row['host'], $row['name']));
			}
		}
	}

	protected function applyQueryFilterOptions($tableName, $tableAlias, array $options, array $sqlParts) {
		$sqlParts = parent::applyQueryFilterOptions($tableName, $tableAlias, $options, $sqlParts);

		// do not return host prototypes from discovered hosts
		$sqlParts['from'][] = 'host_discovery hd';
		$sqlParts['from'][] = 'items i';
		$sqlParts['from'][] = 'hosts ph';
		$sqlParts['where'][] = $this->fieldId('hostid').'=hd.hostid';
		$sqlParts['where'][] = 'hd.parent_itemid=i.itemid';
		$sqlParts['where'][] = 'i.hostid=ph.hostid';
		$sqlParts['where'][] = 'ph.flags='.ZBX_FLAG_DISCOVERY_NORMAL;

		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			$permission = $options['editable'] ? PERM_READ_WRITE : PERM_READ;

			$sqlParts['where'][] = 'EXISTS ('.
				'SELECT NULL'.
				' FROM '.
					'host_discovery hd,items i,hosts_groups hgg'.
					' JOIN rights r'.
						' ON r.id=hgg.groupid'.
						' AND '.dbConditionInt('r.groupid', getUserGroupsByUserId(self::$userData['userid'])).
				' WHERE h.hostid=hd.hostid'.
					' AND hd.parent_itemid=i.itemid'.
					' AND i.hostid=hgg.hostid'.
				' GROUP BY hgg.hostid'.
				' HAVING MIN(r.permission)>'.PERM_DENY.
				' AND MAX(r.permission)>='.$permission.
				')';
		}

		// discoveryids
		if ($options['discoveryids'] !== null) {
			$sqlParts['where'][] = dbConditionInt('hd.parent_itemid', (array) $options['discoveryids']);

			if ($options['groupCount'] !== null) {
				$sqlParts['group']['hd'] = 'hd.parent_itemid';
			}
		}

		// inherited
		if ($options['inherited'] !== null) {
			$sqlParts['where'][] = ($options['inherited']) ? 'h.templateid IS NOT NULL' : 'h.templateid IS NULL';
		}

		return $sqlParts;
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$hostPrototypeIds = array_keys($result);

		// adding discovery rule
		if ($options['selectDiscoveryRule'] !== null && $options['selectDiscoveryRule'] != API_OUTPUT_COUNT) {
			$relationMap = $this->createRelationMap($result, 'hostid', 'parent_itemid', 'host_discovery');
			$discoveryRules = API::DiscoveryRule()->get(array(
				'output' => $options['selectDiscoveryRule'],
				'nodeids' => $options['nodeids'],
				'itemids' => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true,
			));
			$result = $relationMap->mapOne($result, $discoveryRules, 'discoveryRule');
		}

		// adding group prototypes
		if ($options['selectGroupPrototypes'] !== null && $options['selectGroupPrototypes'] != API_OUTPUT_COUNT) {
			$relationMap = $this->createRelationMap($result, 'hostid', 'group_prototypeid', 'group_prototype');
			$groupPrototypes = API::getApi()->select('group_prototype', array(
				'output' => $options['selectGroupPrototypes'],
				'nodeids' => $options['nodeids'],
				'group_prototypeids' => $relationMap->getRelatedIds(),
				'preservekeys' => true
			));
			$result = $relationMap->mapMany($result, $groupPrototypes, 'groupPrototypes');
		}

		// adding host
		if ($options['selectParentHost'] !== null && $options['selectParentHost'] != API_OUTPUT_COUNT) {
			$relationMap = new CRelationMap();
			$dbRules = DBselect(
				'SELECT hd.hostid,i.hostid AS parent_hostid'.
					' FROM host_discovery hd,items i'.
					' WHERE '.dbConditionInt('hd.hostid', $hostPrototypeIds).
					' AND hd.parent_itemid=i.itemid'
			);
			while ($relation = DBfetch($dbRules)) {
				$relationMap->addRelation($relation['hostid'], $relation['parent_hostid']);
			}

			$hosts = API::Host()->get(array(
				'output' => $options['selectParentHost'],
				'nodeids' => $options['nodeids'],
				'hostids' => $relationMap->getRelatedIds(),
				'templated_hosts' => true,
				'nopermissions' => true,
				'preservekeys' => true,
			));
			$result = $relationMap->mapOne($result, $hosts, 'parentHost');
		}

		// adding templates
		if ($options['selectTemplates'] !== null) {
			if ($options['selectTemplates'] != API_OUTPUT_COUNT) {
				$relationMap = $this->createRelationMap($result, 'hostid', 'templateid', 'hosts_templates');
				$templates = API::Template()->get(array(
					'output' => $options['selectTemplates'],
					'nodeids' => $options['nodeids'],
					'templateids' => $relationMap->getRelatedIds(),
					'preservekeys' => true
				));
				$result = $relationMap->mapMany($result, $templates, 'templates');
			}
			else {
				$templates = API::Template()->get(array(
					'nodeids' => $options['nodeids'],
					'hostids' => $hostPrototypeIds,
					'countOutput' => true,
					'groupCount' => true
				));
				$templates = zbx_toHash($templates, 'hostid');
				foreach ($result as $hostid => $host) {
					$result[$hostid]['templates'] = isset($templates[$hostid]) ? $templates[$hostid]['rowscount'] : 0;
				}
			}
		}

		// adding inventory
		if ($options['selectInventory'] !== null) {
			$relationMap = $this->createRelationMap($result, 'hostid', 'hostid');

			// only allow to retrieve the hostid and inventory_mode fields
			$output = array();
			if ($this->outputIsRequested('hostid', $options['selectInventory'])) {
				$output[] = 'hostid';
			}
			if ($this->outputIsRequested('inventory_mode', $options['selectInventory'])) {
				$output[] = 'inventory_mode';
			}
			$inventory = API::getApi()->select('host_inventory', array(
				'output' => $output,
				'filter' => array('hostid' => $hostPrototypeIds)
			));
			$result = $relationMap->mapOne($result, zbx_toHash($inventory, 'hostid'), 'inventory');
		}

		return $result;
	}

	/**
	 * Deletes the given group prototype and all discovered groups.
	 *
	 * @param array $groupPrototypeIds
	 */
	protected function deleteGroupPrototypes(array $groupPrototypeIds) {
		// delete discovered groups
		$hostGroups = DBfetchArray(DBselect(
			'SELECT groupid FROM group_discovery WHERE '.dbConditionInt('parent_group_prototypeid', $groupPrototypeIds)
		));
		if ($hostGroups) {
			API::HostGroup()->delete(zbx_objectValues($hostGroups, 'groupid'), true);
		}

		// delete group prototypes
		DB::delete('group_prototype', array('group_prototypeid' => $groupPrototypeIds));
	}
}
