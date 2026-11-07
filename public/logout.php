<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Logout only works via POST with a valid CSRF token (the navbar button does this).
if (!isPost()) {
    redirect('/');
}
verifyCsrf();

logoutUser();
session_start();                       // fresh session just to carry the message
flash('success', 'You have been logged out.');
redirect('/login.php');
