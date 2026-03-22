<?php
require_once 'config.php';

if (userId()) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
