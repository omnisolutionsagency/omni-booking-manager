<?php
class OBM_Role {
    public static function setup() {
        $cap = 'obm_manage_bookings';
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap($cap)) {
            $admin->add_cap($cap);
        }
        if (!get_role('booking_manager')) {
            add_role('booking_manager', 'Booking Manager', [
                'read' => true,
                'obm_manage_bookings' => true,
                'upload_files' => true,
            ]);
        }
    }

    public static function teardown() {
        remove_role('booking_manager');
        $admin = get_role('administrator');
        if ($admin) $admin->remove_cap('obm_manage_bookings');
    }
}
