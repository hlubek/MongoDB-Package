<?php
declare(ENCODING = 'utf-8');
namespace TYPO3\MongoDB\Tests\Functional;

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

/**
 * A MongoDB backend functional test.
 *
 * Make sure to configure a test database for the Testing context in
 * Configuration/Testing/Settings.yaml.
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 */
class MongoDBTest extends \TYPO3\FLOW3\Tests\FunctionalTestCase {

	/**
	 * @var boolean
	 */
	static protected $testablePersistenceEnabled = TRUE;

	/**
	 *
	 * @var \TYPO3\FLOW3\Persistence\Generic\Backend\BackendInterface
	 */
	protected $backend;

	/**
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function setUp() {
		parent::setUp();

		$this->setUpDependencies();

		$this->resetPersistenceBackend();
	}

	/**
	 * Set up test dependencies
	 */
	public function setUpDependencies() {
		$this->backend = $this->objectManager->get('TYPO3\FLOW3\Persistence\Generic\Backend\BackendInterface');
	}

	/**
	 * Persist all and destroy the persistence session for the next test
	 *
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function tearDown() {
		// Will call persistAll()
		parent::tearDown();

		$persistenceSession = $this->objectManager->get('TYPO3\FLOW3\Persistence\Generic\Session');
		$persistenceSession->destroy();
	}

	/**
	 * @test
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function backendIsMongoDbBackend() {
		$this->assertType('TYPO3\MongoDB\Persistence\Backend\MongoDbBackend', $this->backend);
	}

	/**
	 * @test
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	public function persistEntity() {
		$repository = $this->objectManager->get('TYPO3\MongoDB\Tests\Functional\Fixtures\Domain\Repository\TestEntityRepository');
		$entity = new \TYPO3\MongoDB\Tests\Functional\Fixtures\Domain\Model\TestEntity();
		$entity->setName('Foobar');
		$repository->add($entity);

		$this->tearDown();

		$entities = $repository->findAll();

		$this->assertEquals(1, count($entities));

		$foundEntity = $entities[0];
		$this->assertEquals('Foobar', $foundEntity->getName());
	}

	/**
	 * Delete the database
	 *
	 * @return void
	 * @author Christopher Hlubek <hlubek@networkteam.com>
	 */
	protected function resetPersistenceBackend() {
		$backend = $this->objectManager->get('TYPO3\FLOW3\Persistence\Generic\Backend\BackendInterface');
		$backend->resetStorage();
	}
}
?>