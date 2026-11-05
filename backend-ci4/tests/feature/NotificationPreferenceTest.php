<?php

use Tests\Support\DemoTestCase;

/**
 * Settings > Notifications: "Reorder At Alerts" (rop_alerts) is the only preference with a feature
 * behind it, so it is the only one the owner can change.
 *
 * @internal
 */
final class NotificationPreferenceTest extends DemoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('notification_preferences', true);
        $forge->addField([
            'id'                    => ['type' => 'INTEGER', 'auto_increment' => true],
            'rop_alerts'            => ['type' => 'INTEGER', 'default' => 1],
            'low_stock_warnings'    => ['type' => 'INTEGER', 'default' => 1],
            'daily_sales_summary'   => ['type' => 'INTEGER', 'default' => 1],
            'procurement_reminders' => ['type' => 'INTEGER', 'default' => 1],
            'weekly_trend_report'   => ['type' => 'INTEGER', 'default' => 0],
            'updated_by'            => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('notification_preferences');
        $this->db->table('notification_preferences')->insert(['rop_alerts' => 1]);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('notification_preferences', true);
        parent::tearDown();
    }

    public static function otherKeys(): array
    {
        return [
            'low stock warnings'  => ['low_stock_warnings'],
            'daily sales summary' => ['daily_sales_summary'],
            'order schedule'      => ['procurement_reminders'],
            'weekly trend report' => ['weekly_trend_report'],
            'made-up key'         => ['something_else'],
            'database column'     => ['updated_by'],
        ];
    }

    /**
     * @dataProvider otherKeys
     */
    public function testAnyKeyOtherThanRopAlertsIsRefused(string $key): void
    {
        $before = (array) $this->db->table('notification_preferences')->get()->getRow();

        $this->actingAs('owner')->post('admin/settings/notification-preference', ['pref_key' => $key, 'enabled' => '0'])
            ->assertRedirectTo(site_url('admin/settings?tab=notif'));

        $this->assertSame('Unknown notification preference.', $this->flash('error'));
        $this->assertSame($before, (array) $this->db->table('notification_preferences')->get()->getRow());
    }

    public function testRopAlertsCanStillBeTurnedOffAndOn(): void
    {
        $this->actingAs('owner')->post('admin/settings/notification-preference', ['pref_key' => 'rop_alerts', 'enabled' => '0']);
        $this->assertSame(0, (int) $this->db->table('notification_preferences')->get()->getRow()->rop_alerts);
        $this->assertNull($this->flash('error'));

        $this->actingAs('owner')->post('admin/settings/notification-preference', ['pref_key' => 'rop_alerts', 'enabled' => '1']);
        $this->assertSame(1, (int) $this->db->table('notification_preferences')->get()->getRow()->rop_alerts);
    }
}
