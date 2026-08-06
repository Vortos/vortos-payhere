<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'PayHere';
    }

    public function id(): string
    {
        return 'payhere.ipn_inbox';
    }

    public function description(): string
    {
        return 'PayHere IPN inbox — verified notifications are persisted here before anything is credited; a worker processes rows with retries. The UNIQUE event_id constraint is what stops a redelivered notification crediting a payment twice.';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('payhere_ipn_inbox'));

        $table->addColumn('id',              'bigint',   ['autoincrement' => true, 'notnull' => true]);
        /**
         * PayHere's payment_id where there is one, otherwise order_id + status.
         * PayHere sends no event identifier of its own, and an inbox keyed on
         * anything random de-duplicates nothing.
         */
        $table->addColumn('event_id',        'string',   ['length' => 191, 'notnull' => true]);
        $table->addColumn('event_type',      'string',   ['length' => 100, 'notnull' => true]);
        $table->addColumn('payload',         'json',     ['notnull' => true]);
        $table->addColumn('status',          'string',   ['length' => 20,  'notnull' => true, 'default' => 'pending']);
        $table->addColumn('attempts',        'smallint', ['notnull' => true, 'default' => 0]);
        $table->addColumn('last_error',      'text',     ['notnull' => false, 'default' => null]);
        $table->addColumn('received_at',     'datetime_immutable', ['notnull' => true]);
        $table->addColumn('processed_at',    'datetime_immutable', ['notnull' => false]);
        $table->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['event_id'], 'uq_payhere_ipn_inbox_event_id');
        $table->addIndex(['status', 'next_attempt_at', 'received_at'], 'idx_payhere_ipn_inbox_worker');
    }
};
