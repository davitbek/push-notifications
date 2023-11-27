<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class Notifications extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notifications')
            ->addColumn('country_id', 'integer', ['null' => true])
            ->addColumn('status', 'integer', ['default' => 0, 'limit' => MysqlAdapter::INT_TINY])
            ->addColumn('in_progress', 'integer', ['default' => 0])
            ->addColumn('in_queue', 'integer', ['default' => 0])
            ->addColumn('sent', 'integer', ['default' => 0])
            ->addColumn('failed', 'integer', ['default' => 0])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('message', 'string', ['limit' => 255])
            ->addIndex(['status'])
            ->addIndex(['country_id'])
            ->addIndex(['in_queue'])
            ->addForeignKey(
                'country_id',
                'countries',
                'id',
                [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                    'constraint' => 'notifications_country_id',
                ]
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('notifications')
            ->drop();
    }
}
