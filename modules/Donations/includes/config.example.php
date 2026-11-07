<?php
/** Copy to config.php. Keep credentials out of version control and backups shared publicly. */
defined("NUKE_EVO") || exit;
return array(
    "sandbox" => array("client_id" => "", "secret" => "", "merchant_id" => "", "webhook_id" => ""),
    "live" => array("client_id" => "", "secret" => "", "merchant_id" => "", "webhook_id" => ""),
);
