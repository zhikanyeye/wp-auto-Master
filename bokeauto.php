<?php
/**
 * Plugin Name: 波克wpAI自动化插件
 * Description: 内置 AI 智能体助手：连接外部大模型 API 与内置向量记忆库，用自然语言完成 WordPress 全站管理、开发与维护任务。自学习，越用越聪明。
 * Version:     2.1.1
 * Author:      zhikanyeye
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bokeauto
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'BOKEAUTO_VERSION', '2.1.1' );
define( 'BOKEAUTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOKEAUTO_URL', plugin_dir_url( __FILE__ ) );
define( 'BOKEAUTO_DB_VERSION', '1.6.0' );

/** 项目主页（后台底部推广位与插件列表链接使用） */
define( 'BOKEAUTO_HOMEPAGE', 'https://github.com/zhikanyeye/wp-auto-Master' );

require_once BOKEAUTO_PATH . 'includes/class-bokeauto.php';
require_once BOKEAUTO_PATH . 'includes/class-settings.php';
require_once BOKEAUTO_PATH . 'includes/class-llm.php';
require_once BOKEAUTO_PATH . 'includes/class-collector.php';
require_once BOKEAUTO_PATH . 'includes/class-tools.php';
require_once BOKEAUTO_PATH . 'includes/class-memory.php';
require_once BOKEAUTO_PATH . 'includes/class-skill.php';
require_once BOKEAUTO_PATH . 'includes/class-role.php';
require_once BOKEAUTO_PATH . 'includes/class-collab.php';
require_once BOKEAUTO_PATH . 'includes/class-conversation.php';
require_once BOKEAUTO_PATH . 'includes/class-usage.php';
require_once BOKEAUTO_PATH . 'includes/class-audit.php';
require_once BOKEAUTO_PATH . 'includes/class-confirm.php';
require_once BOKEAUTO_PATH . 'includes/class-agent.php';
require_once BOKEAUTO_PATH . 'includes/class-schedule.php';
require_once BOKEAUTO_PATH . 'includes/class-worklog.php';

register_activation_hook( __FILE__, array( 'Bokeauto_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Bokeauto_Core', 'deactivate' ) );

function bokeauto() {
	return Bokeauto_Core::instance();
}

bokeauto();
