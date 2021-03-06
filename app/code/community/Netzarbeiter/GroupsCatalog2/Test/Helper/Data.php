<?php

/**
 * @loadSharedFixture global.yaml
 * @doNotIndexAll
 */
class Netzarbeiter_GroupsCatalog2_Test_Helper_Data extends EcomDev_PHPUnit_Test_Case
{
    protected $configSection = 'netzarbeiter_groupscatalog2';
    protected $configGroup = 'general';
    /** @var Netzarbeiter_GroupsCatalog2_Helper_Data */
    protected $helper;
    protected $originalCustomerSession;

    public static function setUpBeforeClass() {
        // Fix SET @SQL_MODE='NO_AUTO_VALUE_ON_ZERO' bugs from shared fixture files
        /** @var $db Varien_Db_Adapter_Interface */

        // With the merge of https://github.com/IvanChepurnyi/EcomDev_PHPUnit/pull/93 this hack isn't required any more
        $db = Mage::getSingleton('core/resource')->getConnection('customer_write');
        $db->update(
            Mage::getSingleton('core/resource')->getTableName('customer/customer_group'),
            array('customer_group_id' => 0),
            "customer_group_code='NOT LOGGED IN'"
        );

        // Rebuild GroupsCatalog2 product index
        Mage::getModel('index/indexer')->getProcessByCode('groupscatalog2_product')->reindexEverything();
    }

    /**
     * @var string $code
     * @return Mage_Core_Model_Store
     * @throwException Exception
     */
    protected function getFrontendStore($code = null)
    {
        foreach (Mage::app()->getStores() as $store) {
            if (null === $code) {
                if (! $store->isAdmin()) return $store;
            } else {
                if ($store->getCode() == $code) return $store;
            }
        }
        $this->throwException(new Exception('Unable to find frontend store'));
    }

    /**
     * @return Mage_Core_Model_Store
     */
    protected function getAdminStore()
    {
        return Mage::app()->getStore('admin');
    }

    /**
     * @return string
     */
    protected function getConfigPrefix()
    {
        return $this->configSection . '/' . $this->configGroup .'/';
    }

    protected function setUp()
    {
        /** @var helper Netzarbeiter_GroupsCatalog2_Helper_Data */
        $this->helper = Mage::helper('netzarbeiter_groupscatalog2');

        // Mock customer session
        $mockSession = $this->getModelMockBuilder('customer/session')
                    ->disableOriginalConstructor()
                    ->getMock();

        $registryKey = '_singleton/customer/session';
        if (Mage::registry($registryKey)) {
            $this->originalCustomerSession = Mage::registry($registryKey);
            Mage::unregister($registryKey);
        }
        Mage::register($registryKey, $mockSession);
    }

    protected function tearDown()
    {
        // Get rid of mocked customer session
        $registryKey = '_singleton/customer/session';
        Mage::unregister($registryKey);
        if ($this->originalCustomerSession) {
            Mage::register($registryKey, $this->originalCustomerSession);
            $this->originalCustomerSession = null;
        }
    }

    // Tests #######

    public function testGetConfig()
    {
        $store = $this->getFrontendStore('germany');
        $store->setConfig($this->getConfigPrefix() . 'test', 256);
        $this->assertEquals($this->helper->getConfig('test', $store), 256);
    }

    public function testGetGroups()
    {
        $groups = $this->helper->getGroups();

        $this->assertInstanceOf('Mage_Customer_Model_Resource_Group_Collection', $groups);
    }

    public function testGetGroupsContainsNotLoggedIn()
    {
        $group = $this->helper->getGroups()->getItemByColumnValue('customer_group_code', 'NOT LOGGED IN');
        $this->assertInstanceOf('Mage_Customer_Model_Group', $group);
    }

    public function testIsModuleActiveFrontend()
    {
        $store = $this->getFrontendStore();

        $store->setConfig($this->getConfigPrefix() . 'is_active', 1);
        $this->assertEquals(true, $this->helper->isModuleActive($store), 'Store config active');

        $this->helper->setModuleActive(false);
        $this->assertEquals(false, $this->helper->isModuleActive($store), 'ModuleActive Flag should override store config');

        $this->helper->resetActivationState();
        $this->assertEquals(true, $this->helper->isModuleActive($store), 'resetActivationState() should revert to store config');

        $store->setConfig($this->getConfigPrefix() . 'is_active', 0);
        $this->assertEquals(false, $this->helper->isModuleActive($store), 'Store config inactive');
    }

    public function testIsModuleActiveAdmin()
    {
        $store = $this->getAdminStore();

        $store->setConfig($this->getConfigPrefix() . 'is_active', 1);
        $this->assertEquals(false, $this->helper->isModuleActive($store), 'Admin store is always inactive by default');
        $this->assertEquals(true, $this->helper->isModuleActive($store, false), 'Admin check disabled should return store setting');

        $store->setConfig($this->getConfigPrefix() . 'is_active', 0);
        $this->helper->setModuleActive(true);
        $this->assertEquals(false, $this->helper->isModuleActive($store), 'Admin scope should ignore module state flag');
        $this->assertEquals(true, $this->helper->isModuleActive($store, false), 'Admin check disabled should return module state flag');

        $this->helper->resetActivationState();
    }

    /**
     * @param string $storeCode
     * @param int $customerGroupId
     * @dataProvider dataProvider
     */
    public function testIsProductVisible($storeCode, $customerGroupId)
    {
        // Complete mock of customer session
        /* @var $session PHPUnit_Framework_MockObject_MockObject Stub */
        $mockSession = Mage::getSingleton('customer/session');
        $mockSession->expects($this->any()) // Will be only called if current store is deactivated
            ->method('getCustomerGroupId')
            ->will($this->returnValue($customerGroupId));

        $this->setCurrentStore($storeCode);
        foreach (array(1, 2, 3) as $productId) {
            $product = Mage::getModel('catalog/product')->load($productId);
            $expected = $this->expected('%s-%s-%s', $storeCode, $customerGroupId, $productId)->getIsVisible();
            $visible = $this->helper->isEntityVisible($product, $customerGroupId);

            $message = sprintf(
                "Visibility for product %d, store %s, customer group %s (%d) is expected to be %d but found to be %d",
                $productId, $storeCode,
                $this->helper->getGroups()->getItemById($customerGroupId)->getCustomerGroupCode(),
                $customerGroupId, $expected, $visible
            );
            $this->assertEquals($expected, $visible, $message);
        }
    }

    /**
     * @param string $entityTypeCode
     * @param int|string|Mage_Core_Model_Store $store
     * @dataProvider dataProvider
     */
    public function testGetEntityVisibleDefaultGroupIds($entityTypeCode, $store)
    {
        $store = Mage::app()->getStore($store);
        $expected = $this->expected('%s-%s', $entityTypeCode, $store->getCode());
        $groups = $this->helper->getEntityVisibleDefaultGroupIds($entityTypeCode, $store);
        $message = sprintf(
            'Default visible to groups for store %s "%s" not matching expected list "%s"',
            $store->getCode(), implode(',', $groups), implode(',', $expected->getVisibleToGroups())
        );
        $this->assertEquals($expected->getVisibleToGroups(), $groups, $message);
    }

    /**
     * @param string $entityTypeCode
     * @param int|string|Mage_Core_Model_Store $store
     * @dataProvider dataProvider
     */
    public function testGetModeSettingByEntityType($entityTypeCode, $store)
    {
        $store = Mage::app()->getStore($store);
        $expected = $this->expected('%s-%s', $entityTypeCode, $store->getCode())->getMode();
        $mode = $this->helper->getModeSettingByEntityType($entityTypeCode, $store);
        $message = sprintf(
            'Mode setting for %s in store %s is "%s"',
            $entityTypeCode, $store->getCode(), $mode
        );
        $this->assertEquals($expected, $mode, $message);
    }

    /**
     * @param array $groupIds
     * @param string $mode show | hide
     * @dataProvider dataProvider
     */
    public function testApplyConfigModeSetting($groupIds, $mode)
    {
        $expected = $this->expected('%s-%s', $mode, implode('', $groupIds))->getGroupIds();
        $result = $this->helper->applyConfigModeSetting($groupIds, $mode);
        $message = sprintf(
            'Apply mode "%s" to group ids "%s" is expected to result in "%s" but was "%s"',
            $mode, implode(',', $groupIds), implode(',', $expected), implode(',', $result)
        );
        $this->assertEquals($expected, $result, $message);
    }
}
