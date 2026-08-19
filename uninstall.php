<?php
/**
 * 卸载时清理数据表
 *
 * @package Bokeauto
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$prefix = $wpdb->prefix;

$tables = array(
	'bokeauto_tasks', 'bokeauto_memories', 'bokeauto_feedback', 'bokeauto_confirmations',
	'bokeauto_audit', 'bokeauto_skills', 'bokeauto_roles', 'bokeauto_conversations',
	'bokeauto_messages', 'bokeauto_usage', 'bokeauto_schedules', 'bokeauto_custom_tools',
	'bokeauto_worklogs', 'bokeauto_collect_rules',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
}

delete_option( 'bokeauto_settings' );
delete_option( 'bokeauto_db_version' );
