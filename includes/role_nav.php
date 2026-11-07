<?php
$rn = currentUser();
if ($rn && (int) $rn['is_admin'] !== 1) {
    if ($rn['role'] === 'P') {
        $providerNavActive = $roleNavActive ?? '';
        require BASE_PATH . '/includes/provider_nav.php';
    } else {
        $customerNavActive = $roleNavActive ?? '';
        require BASE_PATH . '/includes/customer_nav.php';
    }
}
