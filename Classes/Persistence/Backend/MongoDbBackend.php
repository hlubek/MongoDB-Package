<?php
declare(ENCODING = 'utf-8');
namespace TYPO3\MongoDB\Persistence\Backend;

/*                                                                        *
 * This script belongs to the FLOW3 package "MongoDB".                    *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use \TYPO3\FLOW3\Persistence\Generic\Qom;

/**
 * A MongoDB persistence backend
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @scope singleton
 */
class MongoDbBackend extends \TYPO3\FLOW3\Persistence\Generic\Backend\AbstractBackend {

	/**
	 * @var \Mongo
	 */
	protected $client;

	/**
	 * @var \MongoDB
	 */
	protected $database;

	/**
	 * The URL of the MongoDB server. Valid URLs could be:
	 * - mongodb://localhost:27017
	 * - mongodb://user:pass@127.0.0.1:27017
	 *
	 * @var string
	 */
	protected $dataSourceName;

	/**
	 * The MongoDB database to use. If it doesn't exist, it will be created.
	 *
	 * @var string
	 */
	protected $databaseName;

	/**
	 * @param string $dataSourceName
	 * @return void
	 */
	public function setDataSourceName($dataSourceName) {
		$this->dataSourceName = $dataSourceName;
	}

	/**
	 * @param string $databaseName
	 * @return void
	 */
	public function setDatabase($databaseName) {
		$this->databaseName = $databaseName;
	}

	/**
	 * Initializes the backend and connects the MongoDB client,
	 * will be called by PersistenceManager
	 *
	 * @param array $options
	 * @return void
	 */
	public function initialize(array $options) {
		parent::initialize($options);
		$this->classSchemata = $this->reflectionService->getClassSchemata();
		$this->connect();
	}

	/**
	 * Connect to MongoDB and select the database
	 *
	 * @return void
	 */
	protected function connect() {
		$this->client = new \Mongo($this->dataSourceName);
		$this->database = $this->client->selectDB($this->databaseName);
	}

	/**
	 * Actually store an object, backend-specific
	 *
	 * @param object $object
	 * @param string $identifier
	 * @param string $parentIdentifier
	 * @param array $objectData
	 * @return integer one of self::OBJECTSTATE_*
	 */
	protected function storeObject($object, $identifier, $parentIdentifier, array &$objectData) {
		if ($this->persistenceSession->hasObject($object)) {
			$objectState = self::OBJECTSTATE_RECONSTITUTED;
		} else {
				// Just get the identifier and register the object, create document with properties later
			$this->persistenceSession->registerObject($object, $identifier);
			$objectState = self::OBJECTSTATE_NEW;
		}

		$classSchema = $this->classSchemata[get_class($object)];
		$dirty = FALSE;
		$objectData = array(
			'identifier' => $identifier,
			'classname' => $classSchema->getClassName(),
			'properties' => $this->collectProperties($identifier, $object, $classSchema->getProperties(), $dirty),
			'parentIdentifier' => $parentIdentifier
		);

		if ($objectState === self::OBJECTSTATE_NEW || $dirty) {
			$this->validateObject($object);
			$this->storeObjectDocument($objectData);
		}

		return $objectState;
	}

	/**
	 * Creates or updates a document for the given object data. An update is
	 * done by using the revision inside the metadata of the object.
	 *
	 * @param array $objectData The object data for the object to store
	 * @return string The identifier of the created record
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 * @todo Catch exceptions for conflicts when updating the document
	 * @todo (Later) Try to use an update handler inside MongoDB for partial updates
	 */
	protected function storeObjectDocument(array $objectData) {
		$objectData['_id'] = $objectData['identifier'];
		unset($objectData['identifier']);


		$collection = $this->database->selectCollection($this->convertClassNameToCollection($objectData['classname']));
		$collection->save($objectData);

		return $objectData['_id'];
	}

	/**
	 *
	 * @param string $className
	 * @return string
	 */
	protected function convertClassNameToCollection($className) {
		return strtr($className, '\\', '_');
	}

	/**
	 * MongoDB does not do partial updates, thus this method always collects the
	 * full set of properties.
	 * Value objects are always inlined.
	 *
	 * @param string $identifier The object's identifier
	 * @param object $object The object to work on
	 * @param array $properties The properties to collect (as per class schema)
	 * @param boolean $dirty A dirty flag that is passed by reference and set to TRUE if a dirty property was found
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function collectProperties($identifier, $object, array $properties, &$dirty) {
		$propertyData = array();
		foreach ($properties as $propertyName => $propertyMetaData) {
			$this->checkPropertyValue($object, $propertyName, $propertyMetaData);
			$propertyValue = \TYPO3\FLOW3\Reflection\ObjectAccess::getProperty($object, $propertyName, TRUE);

			if ($this->persistenceSession->isDirty($object, $propertyName)) {
				$dirty = TRUE;
			}

			$this->flattenValue($identifier, $object, $propertyName, $propertyMetaData, $propertyData);
		}

		return $propertyData;
	}

	/**
	 * Remove all non-aggregate-root objects that have the given identifier set
	 * as their parentIdentifier inside the MongoDB document.
	 *
	 * @param string $identifier The identifier of the parent object
	 * @return void
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function removeEntitiesByParent($identifier) {
		$result = $this->queryView($this->getEntityByParentIdentifierView(), array('parentIdentifier' => $identifier));
		if ($result !== NULL && isset($result->rows) && is_array($result->rows)) {
			foreach ($result->rows as $row) {
				$object = $this->persistenceSession->getObjectByIdentifier($row->id);
				if ($this->classSchemata[get_class($object)]->getModelType() === \TYPO3\FLOW3\Reflection\ClassSchema::MODELTYPE_ENTITY
						&& $this->classSchemata[get_class($object)]->isAggregateRoot() === FALSE) {
					$this->removeEntity($object);
				}
			};
		}
	}

	/**
	 * Removes an entity
	 *
	 * @param object $object An object
	 * @return void
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function removeEntity($object) {
		$identifier = $this->persistenceSession->getIdentifierByObject($object);
		$revision = $this->getRevisionByObject($object);

		$this->removeEntitiesByParent($identifier);

		$this->doOperation(function($client) use ($identifier, $revision) {
			return $client->deleteDocument($identifier, $revision);
		});

		$this->emitRemovedObject($object);
	}

	/**
	 * Remove a value object. Does nothing for MongoDB, since value objects
	 * are embedded in documents.
	 *
	 * @param object $object
	 * @return void
	 */
	protected function removeValueObject($object) {}

	/**
	 * Process object data for an object
	 *
	 * @param object $object
	 * @param string $parentIdentifier
	 * @return array The object data for the given object
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	protected function processObject($object, $parentIdentifier) {
		$className = get_class($object);
		$classSchema = $this->classSchemata[$className];
		if ($classSchema->getModelType() === \TYPO3\FLOW3\Reflection\ClassSchema::MODELTYPE_VALUEOBJECT) {
			$valueIdentifier = $this->persistenceSession->getIdentifierByObject($object);
			$noDirtyOnValueObject = FALSE;
			return array(
				'identifier' => $valueIdentifier,
				'classname' => $className,
				'properties' => $this->collectProperties($valueIdentifier, $object, $classSchema->getProperties(), $noDirtyOnValueObject)
			);
		} else {
			return array(
				'identifier' => $this->persistObject($object, $parentIdentifier)
			);
		}
	}

	/**
	 * Get the MongoDB revision of an object
	 *
	 * @param object $object An object
	 * @return string The current revision if it was set, NULL otherwise
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function getRevisionByObject($object) {
		$metadata = $this->collectMetadata($object);
		if (is_array($metadata) && isset($metadata['MongoDB_Revision'])) {
			return $metadata['MongoDB_Revision'];
		}
		return NULL;
	}

	/**
	 * Returns the number of records matching the query.
	 *
	 * @param \TYPO3\FLOW3\Persistence\QueryInterface $query
	 * @return integer
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function getObjectCountByQuery(\TYPO3\FLOW3\Persistence\QueryInterface $query) {
		$collection = $this->database->selectCollection($this->convertClassNameToCollection($query->getType()));

		$cursor = $this->queryCollection($collection, $query);

		return $cursor->count();
	}

	/**
	 * Returns the object data for the given identifier.
	 *
	 * @param string $identifier The UUID or Hash of the object
	 * @param string $objectType
	 * @return array
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 * @todo Maybe introduce a ObjectNotFound exception?
	 */
	public function getObjectDataByIdentifier($identifier, $objectType = NULL) {
		$collection = $this->database->selectCollection($this->convertClassNameToCollection($objectType));
		$doc = $collection->findOne(array('_id' => $identifier));

		$data = $this->documentsToObjectData(array($doc));
		return $data[0];
	}

	/**
	 * Returns the object data matching the $query.
	 *
	 * @param \TYPO3\FLOW3\Persistence\QueryInterface $query
	 * @return array
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function getObjectDataByQuery(\TYPO3\FLOW3\Persistence\QueryInterface $query) {
		$collection = $this->database->selectCollection($this->convertClassNameToCollection($query->getType()));

		$cursor = $this->queryCollection($collection, $query);

		// TODO Use cursor properly!
		$result = iterator_to_array($cursor);

		if ($result !== NULL) {
			return $this->documentsToObjectData($result);
		} else {
			return array();
		}
	}

	/**
	 *
	 * @param \MongoCollection $collection
	 * @param \TYPO3\FLOW3\Persistence\QueryInterface $query
	 * @return array
	 */
	protected function queryCollection(\MongoCollection $collection, \TYPO3\FLOW3\Persistence\QueryInterface $query) {
		$mongoDbQuery = array();
		if ($query->getConstraint() !== NULL) {
			$this->convertConstraint($query->getConstraint(), $mongoDbQuery);
		}
		var_dump($mongoDbQuery);
		return $collection->find($mongoDbQuery);
	}

	/**
	 *
	 * @param Qom\Constraint $constraint
	 * @param array &$mongoDbQuery
	 */
	protected function convertConstraint(Qom\Constraint $constraint, &$mongoDbQuery) {
		if ($constraint instanceof Qom\Comparison) {
			if ($constraint->getOperator() === \TYPO3\FLOW3\Persistence\QueryInterface::OPERATOR_EQUAL_TO) {
				if ($constraint->getOperand1() instanceof Qom\PropertyValue) {
					$propertyName = $constraint->getOperand1()->getPropertyName();
					$mongoDbQuery['properties.' . $propertyName . '.value'] = $constraint->getOperand2();
				} else {
					throw new \InvalidArgumentException('Operand ' . get_class($operand) . ' is not supported by CouchDB QueryView', 1288606014);
				}
			} else {
				throw new \InvalidArgumentException('Operator ' . $constraint->getOperator() . ' is not supported by MongoDB backend', 1310225081);
			}
		} elseif ($constraint instanceof Qom\LogicalAnd) {
			$this->convertConstraint($constraint->getConstraint1(), $mongoDbQuery);
			$this->convertConstraint($constraint->getConstraint2(), $mongoDbQuery);
		} elseif ($constraint instanceof Qom\LogicalOr) {
			if (!isset($mongoDbQuery['$or'])) {
				$mongoDbQuery['$or'] = array();
			}
			$orOperands = &$mongoDbQuery['$or'];
			$orOperands[] = array();
			$orOperand = &$orOperands[0];
			$this->convertConstraint($constraint->getConstraint1(), $orOperand);
			$orOperands[] = array();
			$orOperand = &$orOperands[1];
			$this->convertConstraint($constraint->getConstraint2(), $orOperand);
		}  elseif ($constraint instanceof Qom\LogicalNot) {
			if ($constraint->getConstraint() instanceof Qom\LogicalOr) {
				$orConstraint = $constraint->getConstraint();
				$transformedConstraint = new Qom\LogicalAnd(
					new Qom\LogicalNot($orConstraint->getConstraint1()),
					new Qom\LogicalNot($orConstraint->getConstraint2())
				);
				$this->convertConstraint($transformedConstraint, $mongoDbQuery);
			} elseif ($constraint->getConstraint() instanceof Qom\LogicalAnd) {
				$orConstraint = $constraint->getConstraint();
				$transformedConstraint = new Qom\LogicalOr(
					new Qom\LogicalNot($orConstraint->getConstraint1()),
					new Qom\LogicalNot($orConstraint->getConstraint2())
				);
				$this->convertConstraint($transformedConstraint, $mongoDbQuery);
			} elseif ($constraint->getConstraint() instanceof Qom\Comparison) {
				$comparison = $constraint->getConstraint();
				if ($comparison->getOperator() === \TYPO3\FLOW3\Persistence\QueryInterface::OPERATOR_EQUAL_TO) {
					if ($comparison->getOperand1() instanceof Qom\PropertyValue) {
						$propertyName = $comparison->getOperand1()->getPropertyName();
						$mongoDbQuery['properties.' . $propertyName . '.value'] = array(
							'$ne' => $comparison->getOperand2()
						);
					} else {
						throw new \InvalidArgumentException('Operand ' . get_class($operand) . ' in not is not supported by MongoDB QueryView', 1310229870);
					}
				} else {
					throw new \InvalidArgumentException('Operator ' . $constraint->getOperator() . ' is not supported by MongoDB backend', 1310225081);
				}
			} else {
				throw new \InvalidArgumentException('Constraint ' . get_class($constraint) . ' in not is not supported by MongoDB backend', 1310229649);
			}
		} else {
			throw new \InvalidArgumentException('Constraint ' . get_class($constraint) . ' is not supported by MongoDB backend', 1310225081);
		}
	}

	/**
	 * Process MongoDB results, add metadata and process object
	 * values by loading objects. This method processes documents
	 * batched for loading nested entities.
	 *
	 * @param array $documents Documents as objects
	 * @param array &$knownObjects
	 * @return array
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function documentsToObjectData(array $documents, array &$knownObjects = array()) {
		$identifiersToFetch = array();
		$data = array();
		foreach ($documents as $document) {
			$objectData = \TYPO3\FLOW3\Utility\Arrays::convertObjectToArray($document);
			$objectData['identifier'] = $objectData['_id'];
			unset($objectData['_id']);

			$knownObjects[$objectData['identifier']] = TRUE;

			if (!isset($objectData['classname'])) {
				throw new \TYPO3\MongoDB\InvalidResultException('Expected property "classname" in document', 1310221818, NULL, $document);
			}
			if (!isset($this->classSchemata[$objectData['classname']])) {
				throw new \TYPO3\MongoDB\InvalidResultException('Class "' . $objectData['classname'] . '" was not registered', 1310221905, NULL, $document);
			}

			$this->processResultProperties($objectData['properties'], $identifiersToFetch, $knownObjects, $this->classSchemata[$objectData['classname']]);

			$data[] = $objectData;
		}

		if (count($identifiersToFetch) > 0) {
			// TODO Implement eager loading of additional documents
			$documents = array();

			$fetchedObjectsData = $this->documentsToObjectData($documents, $knownObjects);

			foreach ($fetchedObjectsData as $fetchedObjectData) {
				$identifiersToFetch[$fetchedObjectData['identifier']] = $fetchedObjectData;
			}
		}

		return $data;
	}

	/**
	 * Process an array of object data properties and add identifiers to fetch
	 * for recursive processing in nested objects
	 *
	 * @param array &$properties
	 * @param array &$identifiersToFetch
	 * @param array &$knownObjects
	 * @param \TYPO3\FLOW3\Reflection\ClassSchema $classSchema
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function processResultProperties(array &$properties, array &$identifiersToFetch, array &$knownObjects, \TYPO3\FLOW3\Reflection\ClassSchema $classSchema) {
		foreach ($properties as $propertyName => &$propertyData) {
				// Skip unknown properties
			if (!$classSchema->hasProperty($propertyName)) {
				continue;
			}
			$propertyMetadata = $classSchema->getProperty($propertyName);
			if (!$propertyData['multivalue']) {
				if (isset($propertyData['value']['identifier']) && !isset($propertyData['value']['classname'])) {
					if ($propertyMetadata['lazy'] !== TRUE) {
						if (!isset($knownObjects[$propertyData['value']['identifier']])) {
							$identifiersToFetch[$propertyData['value']['identifier']] = NULL;
							$propertyData['value'] = &$identifiersToFetch[$propertyData['value']['identifier']];
						}
					} else {
						$propertyData['value'] = array('identifier' => $propertyData['value']['identifier'], 'classname' => $propertyData['type'], 'properties' => array());
					}
				} elseif (is_array($propertyData['value']) && isset($propertyData['value']['properties'])) {
					$this->processResultProperties($propertyData['value']['properties'], $identifiersToFetch, $knownObjects, $this->classSchemata[$propertyData['value']['classname']]);
				}
			} else {
				for ($index = 0; $index < count($propertyData['value']); $index++) {
					if (isset($propertyData['value'][$index]['value']['identifier']) && !isset($propertyData['value'][$index]['value']['classname'])) {
						if ($propertyMetadata['lazy'] !== TRUE) {
							if (!isset($knownObjects[$propertyData['value'][$index]['value']['identifier']])) {
								$identifiersToFetch[$propertyData['value'][$index]['value']['identifier']] = NULL;
								$propertyData['value'][$index]['value'] = &$identifiersToFetch[$propertyData['value'][$index]['value']['identifier']];
							}
						} else {
							$propertyData['value'][$index]['value'] = array('identifier' => $propertyData['value'][$index]['value']['identifier'], 'classname' => $propertyData['value'][$index]['type'], 'properties' => array());
						}
					} elseif (is_array($propertyData['value']) && isset($propertyData['value'][$index]['value']['properties']) && is_array($propertyData['value'][$index]['value'])) {
						$this->processResultProperties($propertyData['value'][$index]['value']['properties'], $identifiersToFetch, $knownObjects, $this->classSchemata[$propertyData['value'][$index]['value']['classname']]);
					}
				}
			}
		}
	}

	/**
	 * Do a MongoDB operation and handle error conversion and creation of
	 * the database on the fly.
	 *
	 * @param \Closure $MongoDBOperation
	 * @return mixed
	 */
	protected function doOperation(\Closure $MongoDBOperation) {
		try {
			return $MongoDBOperation($this->client);
		} catch(\TYPO3\MongoDB\Client\ClientException $clientException) {
			$information = $clientException->getInformation();
			if (isset($information['error']) && $information['error'] === 'not_found' && $information['reason'] === 'no_db_file') {
				if ($this->client->createDatabase($this->databaseName)) {
					return $this->doOperation($MongoDBOperation);
				} else {
					throw new \TYPO3\FLOW3\Persistence\Exception('Could not create database ' . $this->database, 1286901880);
				}
			} else {
				throw $clientException;
			}
		}
	}

	/**
	 * Delete the database with all documents, it will be recreated on
	 * next access.
	 *
	 * @return void
	 */
	public function resetStorage() {
		$collectioNames = $this->database->listCollections();
		foreach ($collectioNames as $collectionName) {
			$this->database->dropCollection($collectionName);
		}
	}

	/**
	 * @return \TYPO3\MongoDB\EntityByParentIdentifierView
	 */
	public function getEntityByParentIdentifierView() {
		if ($this->entityByParentIdentifierView === NULL) {
			$this->entityByParentIdentifierView = new \TYPO3\MongoDB\EntityByParentIdentifierView();
		}
		return $this->entityByParentIdentifierView;
	}

	/**
	 * @return boolean
	 */
	public function getEnableMongoDBLucene() {
		return $this->enableMongoDBLucene;
	}

	/**
	 * @param boolean $enableMongoDBLucene
	 * @return void
	 */
	public function setEnableMongoDBLucene($enableMongoDBLucene) {
		$this->enableMongoDBLucene = $enableMongoDBLucene;
	}

}
?>