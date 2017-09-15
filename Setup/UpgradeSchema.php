<?php

namespace Dotdigitalgroup\Email\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{

    /**
     * @var \Dotdigitalgroup\Email\Model\Config\Json
     */
    public $json;

    /**
     * UpgradeSchema constructor.
     * @param \Dotdigitalgroup\Email\Model\Config\Json $json
     */
    public function __construct(
        \Dotdigitalgroup\Email\Model\Config\Json $json
    ) {
        $this->json = $json;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();
        if (version_compare($context->getVersion(), '1.1.0', '<')) {
            //remove quote table
            $connection->dropTable($setup->getTable('email_quote'));
        }
        if (version_compare($context->getVersion(), '2.0.6', '<')) {
            $this->upgradeTwoOSix($connection, $setup);
        }
        if (version_compare($context->getVersion(), '2.1.0', '<')) {
            $couponTable = $setup->getTable('salesrule_coupon');
            $connection->addColumn(
                $couponTable,
                'generated_by_dotmailer',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                    'nullable' => true,
                    'default' => null,
                    'comment' => '1 = Generated by dotmailer'
                ]
            );
        }

        //replace serialize with json_encode
        if (version_compare($context->getVersion(), '2.2.1', '<')) {
            //modify the condition column name for the email_rules table - reserved name for mysql
            $rulesTable = $setup->getTable('email_rules');

            if ($connection->tableColumnExists($rulesTable, 'condition')) {
                $connection->changeColumn(
                    $rulesTable,
                    'condition',
                    'conditions',
                    [
                        'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BLOB,
                        'nullable' => false,
                        'comment' => 'Rule Conditions'
                    ]
                );
            }
            /**
             * Core config data.
             */
            $this->convertDataForConfig($setup, $connection);
            /**
             * Importer data.
             */
            $this->convertDataForImporter($setup, $connection);
            /**
             * Rules conditions.
             */
            $this->convertDataForRules($setup, $connection);
            /**
             * Index foreign key for email catalog.
             */
            $this->addIndexKeyForCatalog($setup, $connection);

            /**
             * Add index foreign key for email order.
             */
            $this->addIndexKeyForOrder($setup, $connection);
        }

        if (version_compare($context->getVersion(), '2.3.4', '<')) {
            $abandonedCartTable = $connection->newTable(
                $setup->getTable('email_abandoned_cart')
            );

            $abandonedCartTable = $this->addColumnForAbandonedCartTable($abandonedCartTable);
            $abandonedCartTable = $this->addIndexKeyForAbandonedCarts($setup, $abandonedCartTable);

            $abandonedCartTable->setComment('Abandoned Carts Table');
            $setup->getConnection()->createTable($abandonedCartTable);
        }

        $setup->endSetup();
    }

    /**
     * @param AdapterInterface $connection
     * @param SchemaSetupInterface $setup
     *
     * @return void
     */
    private function upgradeTwoOSix($connection, $setup)
    {
        //modify email_campaign table
        $campaignTable = $setup->getTable('email_campaign');

        //add columns
        $connection->addColumn(
            $campaignTable,
            'send_id',
            [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                'nullable' => false,
                'default' => '',
                'comment' => 'Campaign Send Id'
            ]
        );
        $connection->addColumn(
            $campaignTable,
            'send_status',
            [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                'nullable' => false,
                'default' => 0,
                'comment' => 'Send Status'
            ]
        );

        if ($connection->tableColumnExists($campaignTable, 'is_sent')) {
            //update table with historical send values
            $select = $connection->select();

            //join
            $select->joinLeft(
                ['oc' => $campaignTable],
                "oc.id = nc.id",
                [
                    'send_status' => new \Zend_Db_Expr(\Dotdigitalgroup\Email\Model\Campaign::SENT)
                ]
            )->where('oc.is_sent =?', 1);

            //update query from select
            $updateSql = $select->crossUpdateFromSelect(['nc' => $campaignTable]);

            //run query
            $connection->query($updateSql);

            //remove column
            $connection->dropColumn($campaignTable, 'is_sent');
        }

        //add index
        $connection->addIndex(
            $campaignTable,
            $setup->getIdxName($campaignTable, ['send_status']),
            ['send_status']
        );
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param AdapterInterface $connection
     *
     * @return null
     */
    private function convertDataForConfig(SchemaSetupInterface $setup, $connection)
    {
        $configTable = $setup->getTable('core_config_data');
        //customer and order custom attributes from config
        $select = $connection->select()->from(
            $configTable
        )->where(
            'path IN (?)',
            [
                'connector_automation/order_status_automation/program',
                'connector_data_mapping/customer_data/custom_attributes'
            ]
        );
        $rows = $setup->getConnection()->fetchAssoc($select);

        $serializedRows = array_filter($rows, function ($row) {
            return $this->isSerialized($row['value']);
        });

        foreach ($serializedRows as $id => $serializedRow) {
            $convertedValue = $this->json->serialize($this->unserialize($serializedRow['value']));
            $bind = ['value' => $convertedValue];
            $where = [$connection->quoteIdentifier('config_id') . '=?' => $id];
            $connection->update($configTable, $bind, $where);
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param AdapterInterface $connection
     *
     * @return null
     */
    private function convertDataForRules(SchemaSetupInterface $setup, $connection)
    {
        $rulesTable = $setup->getTable('email_rules');
        //rules data
        $select = $connection->select()->from($rulesTable);
        $rows = $setup->getConnection()->fetchAssoc($select);

        $serializedRows = array_filter($rows, function ($row) {
            return $this->isSerialized($row['conditions']);
        });

        foreach ($serializedRows as $id => $serializedRow) {
            $convertedValue = $this->json->serialize($this->unserialize($serializedRow['conditions']));
            $bind = ['conditions' => $convertedValue];
            $where = [$connection->quoteIdentifier('id') . '=?' => $id];
            $connection->update($rulesTable, $bind, $where);
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param AdapterInterface $connection
     *
     * @return null
     */
    private function convertDataForImporter(SchemaSetupInterface $setup, $connection)
    {
        $importerTable = $setup->getTable('email_importer');
        //imports that are not imported and has TD data
        $select = $connection->select()
            ->from($importerTable)
            ->where('import_status =?', 0)
            ->where('import_type IN (?)', ['Catalog_Default', 'Orders' ])
        ;
        $rows = $setup->getConnection()->fetchAssoc($select);

        $serializedRows = array_filter($rows, function ($row) {
            return $this->isSerialized($row['import_data']);
        });

        foreach ($serializedRows as $id => $serializedRow) {
            $convertedValue = $this->json->serialize($this->unserialize($serializedRow['import_data']));
            $bind = ['import_data' => $convertedValue];
            $where = [$connection->quoteIdentifier('id') . '=?' => $id];
            $connection->update($importerTable, $bind, $where);
        }
    }

    /**
     * Check if value is a serialized string
     *
     * @param string $value
     * @return boolean
     */
    private function isSerialized($value)
    {
        return (boolean) preg_match('/^((s|i|d|b|a|O|C):|N;)/', $value);
    }

    /**
     * @param string $string
     * @return mixed
     */
    private function unserialize($string)
    {
        if (false === $string || null === $string || '' === $string) {
            throw new \InvalidArgumentException('Unable to unserialize value.');
        }
        set_error_handler(
            function () {
                restore_error_handler();
                throw new \InvalidArgumentException('Unable to unserialize value, string is corrupted.');
            },
            E_NOTICE
        );
        $result = unserialize($string, ['allowed_classes' => false]);
        restore_error_handler();

        return $result;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param AdapterInterface $connection
     *
     * @return null
     */
    private function addIndexKeyForCatalog(
        SchemaSetupInterface $setup,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection
    ) {
        $connection->addForeignKey(
            $setup->getFkName(
                'email_catalog',
                'product_id',
                'catalog_product_entity',
                'entity_id'
            ),
            $setup->getTable('email_catalog'),
            'product_id',
            $setup->getTable('catalog_product_entity'),
            'entity_id',
            \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE
        );
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param AdapterInterface $connection
     *
     * @return null
     */
    private function addIndexKeyForOrder(
        SchemaSetupInterface $setup,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection
    ) {
        $connection->addForeignKey(
            $setup->getFkName(
                'email_order',
                'order_id',
                'sales_order',
                'entity_id'
            ),
            $setup->getTable('email_order'),
            'order_id',
            $setup->getTable('sales_order'),
            'entity_id',
            \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE
        );
    }

    /**
     * @param $table
     * @return mixed
     */
    private function addColumnForAbandonedCartTable($table)
    {
        return $table->addColumn(
            'id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            10,
            [
                'primary' => true,
                'identity' => true,
                'unsigned' => true,
                'nullable' => false
            ],
            'Primary Key'
        )
            ->addColumn(
                'quote_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                10,
                ['unsigned' => true, 'nullable' => true],
                'Quote Id'
            )
            ->addColumn(
                'store_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                10,
                ['unsigned' => true, 'nullable' => true],
                'Store Id'
            )
            ->addColumn(
                'customer_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                10,
                ['unsigned' => true, 'nullable' => false],
                'Customer ID'
            )
            ->addColumn(
                'email',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                255,
                ['nullable' => false, 'default' => ''],
                'Email'
            )
            ->addColumn(
                'is_active',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                5,
                ['unsigned' => true, 'nullable' => false, 'default' => '1'],
                'Quote Active'
            )
            ->addColumn(
                'quote_updated_at',
                \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                null,
                [],
                'Quote updated at'
            )
            ->addColumn(
                'abandoned_cart_number',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false, 'default' => 0],
                'Abandoned Cart number'
            )
            ->addColumn(
                'items_count',
                \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => true, 'default' => 0],
                'Quote items count'
            )
            ->addColumn(
                'items_ids',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                255,
                ['unsigned' => true, 'nullable' => false],
                'Quote item ids'
            )
            ->addColumn(
                'created_at',
                \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                null,
                [],
                'Created At'
            )
            ->addColumn(
                'updated_at',
                \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                null,
                [],
                'Updated at'
            );
    }

    /**
     * @param $installer
     * @param $abandonedCartTable
     * @return mixed
     */
    private function addIndexKeyForAbandonedCarts($installer, $abandonedCartTable)
    {
        return $abandonedCartTable->addIndex(
            $installer->getIdxName('email_abandoned_cart', ['quote_id']),
            ['quote_id']
        )
        ->addIndex(
            $installer->getIdxName('email_abandoned_cart', ['store_id']),
            ['store_id']
        )
        ->addIndex(
            $installer->getIdxName('email_abandoned_cart', ['customer_id']),
            ['customer_id']
        )
        ->addIndex(
            $installer->getIdxName('email_abandoned_cart', ['email']),
            ['email']
        );
    }

}
