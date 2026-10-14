<?php
require_once __DIR__ . '/_init.php';
unset($_SESSION['admin_id'], $_SESSION['admin_user']);
session_regenerate_id(true);
redirect('login.php');
