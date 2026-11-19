<?php
require __DIR__ . '/config.php';
if ($u = current_user()) log_event('logout', $u, $u['role'] === 'admin' ? 'admin:' . $u['username'] : '');
$_SESSION = [];
session_destroy();
session_start();
flash('ออกจากระบบแล้ว', 'info');
redirect('index.php');
