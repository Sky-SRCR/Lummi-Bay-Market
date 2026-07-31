<?php
require_once 'auth.php';
requireAdmin();
header('Location: admin_panel.php?tab=branding');
exit;
