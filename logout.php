<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

unset($_SESSION['admin_user']);
session_regenerate_id(true);

header('Location: admin.php');
exit;
