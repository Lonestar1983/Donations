<?php
defined("ADMIN_FILE") || exit;
if (isset($op) && $op === "evo_donations") { require_once NUKE_MODULES_DIR . "Donations/admin/index.php"; }
