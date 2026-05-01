<?php
define('WP_USE_THEMES', false);
require_once('../../../../wp-load.php');

$plugin = Salawat_Counter_Plugin::instance();
$stats = $plugin->get_stats();

echo "Salawat Stats Debug Output:\n";
echo "---------------------------\n";
print_r($stats);

echo "\nDatabase Check:\n";
global $wpdb;
$table_name = $wpdb->prefix . 'salawat_pledges';
$row_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
$sum_amount = $wpdb->get_var("SELECT SUM(salawat_amount) FROM $table_name");

echo "Table: $table_name\n";
echo "Row Count: $row_count\n";
echo "Sum Amount: $sum_amount\n";

$last_5 = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5");
echo "\nLast 5 Pledges:\n";
print_r($last_5);
