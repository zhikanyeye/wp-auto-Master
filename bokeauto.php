<?php
/**
 * Plugin Name: 波克wpAI自动化插件
 * Description: 内置 AI 智能体助手：连接外部大模型 API 与内置向量记忆库，用自然语言完成 WordPress 全站管理、开发与维护任务。自学习，越用越聪明。
 * Version:     1.7.0
 * Author:      波克
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bokeauto
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'BOKEAUTO_VERSION', '1.7.0' );
define( 'BOKEAUTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOKEAUTO_URL', plugin_dir_url( __FILE__ ) );
define( 'BOKEAUTO_DB_VERSION', '1.5.1' );

require_once BOKEAUTO_PATH . 'includes/class-bokeauto.php';
require_once BOKEAUTO_PATH . 'includes/class-settings.php';
require_once BOKEAUTO_PATH . 'includes/class-llm.php';
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
