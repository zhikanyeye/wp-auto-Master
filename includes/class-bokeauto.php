<?php
/**
 * 波克wpAI自动化插件 - 核心类
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

class Bokeauto_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		// 定时任务：wp-cron 回调 + 启动时同步调度
		add_action( 'bokeauto_schedule_run', array( 'Bokeauto_Schedule', 'cron_hook' ), 10, 1 );
		add_action( 'init', array( 'Bokeauto_Schedule', 'sync_cron' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * 安装 / 卸载
	 * ------------------------------------------------------------------- */

	public static function activate() {
		self::create_tables();
		update_option( 'bokeauto_db_version', BOKEAUTO_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'bokeauto_daily_distill' );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$tables = array();

		$tables[] = "CREATE TABLE {$prefix}bokeauto_tasks (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			summary TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'done',
			step_count INT NOT NULL DEFAULT 0,
			tool_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_memories (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			m_type VARCHAR(20) NOT NULL DEFAULT 'semantic',
			title VARCHAR(255) NOT NULL,
			content LONGTEXT NOT NULL,
			embedding LONGTEXT NULL,
			meta LONGTEXT NULL,
			weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
			hit_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY m_type (m_type)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_feedback (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			rating INT NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_confirmations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			confirm_hash VARCHAR(64) NOT NULL,
			payload LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY confirm_hash (confirm_hash)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_audit (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(100) NOT NULL,
			detail LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_skills (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			description TEXT NULL,
			tools TEXT NULL,
			trigger_text VARCHAR(200) NOT NULL DEFAULT '',
			source VARCHAR(10) NOT NULL DEFAULT 'manual',
			usage_count INT NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_roles (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(60) NOT NULL,
			description TEXT NULL,
			system_prompt TEXT NULL,
			tools TEXT NULL,
			role_type VARCHAR(10) NOT NULL DEFAULT 'chat',
			bind_tool VARCHAR(60) NOT NULL DEFAULT '',
			llm_provider VARCHAR(20) NOT NULL DEFAULT '',
			llm_base_url VARCHAR(255) NOT NULL DEFAULT '',
			llm_api_key VARCHAR(255) NOT NULL DEFAULT '',
			llm_model VARCHAR(100) NOT NULL DEFAULT '',
			is_builtin TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_conversations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(120) NOT NULL DEFAULT '新对话',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_messages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			role VARCHAR(20) NOT NULL DEFAULT 'user',
			content LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_usage (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			conversation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			prompt_tokens INT NOT NULL DEFAULT 0,
			completion_tokens INT NOT NULL DEFAULT 0,
			total_tokens INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY conversation_id (conversation_id)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_schedules (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			prompt TEXT NOT NULL,
			interval_type VARCHAR(20) NOT NULL DEFAULT 'daily',
			interval_minutes INT NOT NULL DEFAULT 60,
			at_time VARCHAR(5) NOT NULL DEFAULT '09:00',
			day_of_week INT NOT NULL DEFAULT 1,
			auto_high TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			next_run DATETIME NULL,
			last_run DATETIME NULL,
			last_result LONGTEXT NULL,
			run_count INT NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_custom_tools (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(60) NOT NULL,
			description TEXT NULL,
			params LONGTEXT NULL,
			php_code LONGTEXT NOT NULL,
			risk VARCHAR(10) NOT NULL DEFAULT 'low',
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_worklogs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			log_date DATE NOT NULL,
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY log_date (log_date)
		) $charset;";

		$tables[] = "CREATE TABLE {$prefix}bokeauto_collect_rules (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			list_url VARCHAR(500) NOT NULL DEFAULT '',
			link_selector VARCHAR(255) NOT NULL DEFAULT 'a',
			article_rules LONGTEXT NULL,
			options LONGTEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'active',
			collected_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			last_run_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset;";

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		// 内置角色种子
		if ( class_exists( 'Bokeauto_Role' ) ) {
			Bokeauto_Role::seed_builtins();
		}
	}

	/** 版本升级：补建新表 / 新增列 / 种子数据（dbDelta 幂等） */
	public function maybe_upgrade() {
		if ( get_option( 'bokeauto_db_version' ) === BOKEAUTO_DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::upgrade_columns();
		update_option( 'bokeauto_db_version', BOKEAUTO_DB_VERSION );
	}

	/** 为已存在的表补充新增列（dbDelta 不支持加列，需手动 ALTER） */
	private static function upgrade_columns() {
		global $wpdb;

		$cols = $wpdb->get_col( "DESC {$wpdb->prefix}bokeauto_roles" );
		if ( $cols && ! in_array( 'llm_provider', $cols, true ) ) {
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}bokeauto_roles
				 ADD llm_provider VARCHAR(20) NOT NULL DEFAULT '' AFTER tools,
				 ADD llm_base_url VARCHAR(255) NOT NULL DEFAULT '' AFTER llm_provider,
				 ADD llm_api_key VARCHAR(255) NOT NULL DEFAULT '' AFTER llm_base_url,
				 ADD llm_model VARCHAR(100) NOT NULL DEFAULT '' AFTER llm_api_key"
			);
		}
		// 角色类型（chat 聊天型 / functional 功能性）与绑定输出工具
		$cols = $wpdb->get_col( "DESC {$wpdb->prefix}bokeauto_roles" );
		if ( $cols && ! in_array( 'role_type', $cols, true ) ) {
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}bokeauto_roles
				 ADD role_type VARCHAR(10) NOT NULL DEFAULT 'chat' AFTER tools,
				 ADD bind_tool VARCHAR(60) NOT NULL DEFAULT '' AFTER role_type"
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * 初始化
	 * ------------------------------------------------------------------- */

	public function init() {
		load_plugin_textdomain( 'bokeauto', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * 后台菜单
	 * ------------------------------------------------------------------- */

	public function admin_menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( '波克wpAI自动化插件', 'bokeauto' ),
			__( '波克wpAI', 'bokeauto' ),
			$cap,
			'bokeauto-chat',
			array( $this, 'render_chat_page' ),
			'dashicons-superhero',
			3
		);

		add_submenu_page(
			'bokeauto-chat',
			__( '智能体对话', 'bokeauto' ),
			__( '对话', 'bokeauto' ),
			$cap,
			'bokeauto-chat',
			array( $this, 'render_chat_page' )
		);

		add_submenu_page(
			'bokeauto-chat',
			__( '模型设置', 'bokeauto' ),
			__( '模型设置', 'bokeauto' ),
			$cap,
			'bokeauto-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'bokeauto-chat',
			__( '角色与技能', 'bokeauto' ),
			__( '角色与技能', 'bokeauto' ),
			$cap,
			'bokeauto-roles',
			array( $this, 'render_roles_page' )
		);

		add_submenu_page(
			'bokeauto-chat',
			__( '定时任务', 'bokeauto' ),
			__( '定时任务', 'bokeauto' ),
			$cap,
			'bokeauto-schedules',
			array( $this, 'render_schedules_page' )
		);
	}

	public function render_chat_page() {
		$this->render_page( 'page-chat' );
	}

	public function render_settings_page() {
		$this->render_page( 'page-settings' );
	}

	public function render_roles_page() {
		$this->render_page( 'page-roles' );
	}

	public function render_schedules_page() {
		$this->render_page( 'page-schedules' );
	}

	/** 统一渲染后台页面：模板 + 底部推广位 */
	private function render_page( $template ) {
		$file = BOKEAUTO_PATH . 'admin/templates/' . $template . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
		include BOKEAUTO_PATH . 'admin/templates/partial-promo.php';
	}

	/** 插件列表页补充项目主页链接 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( BOKEAUTO_PATH . 'bokeauto.php' ) !== $file || ! defined( 'BOKEAUTO_HOMEPAGE' ) ) {
			return $links;
		}
		$home     = trailingslashit( BOKEAUTO_HOMEPAGE );
		$links[] = '<a href="' . esc_url( BOKEAUTO_HOMEPAGE ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '项目主页', 'bokeauto' ) . '</a>';
		$links[] = '<a href="' . esc_url( $home . 'issues' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '问题反馈', 'bokeauto' ) . '</a>';
		return $links;
	}

	/* ---------------------------------------------------------------------
	 * 资源
	 * ------------------------------------------------------------------- */

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'bokeauto' ) ) {
			return;
		}
		$screen = get_current_screen();
		$page   = $screen ? $screen->id : '';

		// 界面图标依赖 dashicons，显式声明依赖避免图标缺失。
		wp_enqueue_style( 'bokeauto-chat', BOKEAUTO_URL . 'admin/css/chat.css', array( 'dashicons' ), BOKEAUTO_VERSION );

		if ( false !== strpos( $page, 'bokeauto-roles' ) ) {
			wp_enqueue_script( 'bokeauto-roles', BOKEAUTO_URL . 'admin/js/roles.js', array(), BOKEAUTO_VERSION, true );
			wp_localize_script( 'bokeauto-roles', 'BOKEAUTO', array(
				'api'        => esc_url_raw( rest_url( 'bokeauto/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'all_tools'  => Bokeauto_Tools::names(),
				'presets'    => Bokeauto_Settings::presets(),
				'i18n'       => array(
					'confirm_delete_role'   => __( '确定删除该角色？', 'bokeauto' ),
					'confirm_delete_skill'  => __( '确定删除该技能？', 'bokeauto' ),
					'saved'                 => __( '已保存', 'bokeauto' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'bokeauto-schedules' ) ) {
			wp_enqueue_script( 'bokeauto-schedules', BOKEAUTO_URL . 'admin/js/schedules.js', array(), BOKEAUTO_VERSION, true );
			wp_localize_script( 'bokeauto-schedules', 'BOKEAUTO', array(
				'api'   => esc_url_raw( rest_url( 'bokeauto/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'confirm_delete' => __( '确定删除该定时任务？删除后不可恢复。', 'bokeauto' ),
					'confirm_run'    => __( '立即执行该任务？将真实执行任务指令（可能产生实际效果）。', 'bokeauto' ),
					'saved'          => __( '已保存', 'bokeauto' ),
					'running'        => __( '执行中，请稍候…', 'bokeauto' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'bokeauto-settings' ) ) {
			wp_enqueue_script( 'bokeauto-settings', BOKEAUTO_URL . 'admin/js/settings.js', array(), BOKEAUTO_VERSION, true );
			wp_localize_script( 'bokeauto-settings', 'BOKEAUTO', array(
				'api'   => esc_url_raw( rest_url( 'bokeauto/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'today' => current_time( 'Y-m-d' ),
				'i18n'  => array(
					'confirm_delete' => __( '确定删除这一天的工作日志？删除后不可恢复。', 'bokeauto' ),
					'saved'          => __( '已保存', 'bokeauto' ),
					'load_failed'    => __( '加载工作日志失败', 'bokeauto' ),
					'save_failed'    => __( '保存失败，请重试', 'bokeauto' ),
					'empty_confirm'  => __( '内容为空将清空该天日志，确定保存？', 'bokeauto' ),
				),
			) );
		}

		if ( false !== strpos( $page, 'bokeauto-chat' ) ) {
			wp_enqueue_script( 'bokeauto-chat', BOKEAUTO_URL . 'admin/js/chat.js', array(), BOKEAUTO_VERSION, true );
			wp_localize_script( 'bokeauto-chat', 'BOKEAUTO', array(
				'api'        => esc_url_raw( rest_url( 'bokeauto/v1/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'thinking'  => __( '波克wpAI思考中…', 'bokeauto' ),
					'error'     => __( '请求失败，请稍后重试', 'bokeauto' ),
					'confirm'   => __( '此操作需要你的确认', 'bokeauto' ),
					'approve'   => __( '允许执行', 'bokeauto' ),
					'reject'    => __( '拒绝', 'bokeauto' ),
					'new_chat'  => __( '新对话', 'bokeauto' ),
					'placeholder' => __( '例如：帮我发布一篇介绍网站的文章，并创建分类「新闻」…', 'bokeauto' ),
				),
			) );
		}
	}

	/* ---------------------------------------------------------------------
	 * REST API
	 * ------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route( 'bokeauto/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_chat' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/confirm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_confirm' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/task-stream', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_task_stream' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/history', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_history' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/feedback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_feedback' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/test-llm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_test_llm' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/test-embedding', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_test_embedding' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/models', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_models' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/memories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_memories' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/memories', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_memories_clear' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/roles', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_roles' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/roles/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_role_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/skills', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_skills' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/skills/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_skill_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/conversations', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_conversations' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/conversations/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_conversation_delete' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/conversations/(?P<id>\d+)/messages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_conversation_messages' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/conversations/(?P<id>\d+)/clear', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_conversation_clear' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/conversations/clear-all', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_conversations_clear_all' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'api_usage' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/file-save', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_file_save' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/settings', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_settings' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/schedules', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_schedules' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/schedules/(?P<id>\d+)', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( $this, 'api_schedule_item' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/schedules/(?P<id>\d+)/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'api_schedule_run' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/worklogs', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'api_worklogs' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );

		register_rest_route( 'bokeauto/v1', '/worklogs/(?P<day>\d{4}-\d{2}-\d{2})', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'api_worklog_delete' ),
			'permission_callback' => array( $this, 'check_permission' ),
		) );
	}

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function api_chat( $request ) {
		$message   = sanitize_text_field( (string) $request->get_param( 'message' ) );
		$history   = $this->clean_history( $request->get_param( 'history' ) );
		$conv_id   = (int) $request->get_param( 'conversation_id' );
		$user_id   = get_current_user_id();

		if ( '' === $message ) {
			return new WP_Error( 'bokeauto_empty', '消息不能为空', array( 'status' => 400 ) );
		}

		// 会话归属校验与消息入库
		$conv = $conv_id ? Bokeauto_Conversation::get( $conv_id, $user_id ) : null;
		if ( $conv_id && ! $conv ) {
			return new WP_Error( 'bokeauto_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		if ( $conv ) {
			$msg_count = $conv_id;
			Bokeauto_Conversation::add_message( $conv_id, 'user', $message );
			// 首条消息自动命名
			$first = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}bokeauto_messages WHERE conversation_id = %d",
				$conv_id
			) );
			if ( 1 === $first ) {
				Bokeauto_Conversation::autotitle( $conv_id, $message );
			}
		}

		$auto_confirm = $this->parse_auto_confirm( $request );
		$role_id      = (int) $request->get_param( 'role_id' );
		$php_timeout  = $request->get_param( 'php_timeout' );
		$max_steps    = $request->get_param( 'max_steps' );

		if ( (bool) $request->get_param( 'stream' ) ) {
			// 优先走后台异步：Web 请求瞬时返回 task_id，智能体在独立进程执行，
			// 前端轮询拉取——彻底避免"AI 回复期间整站卡顿"。拉起失败则降级为同步流式。
			$run = $this->start_async_run( $message, $history, $user_id, $conv, $auto_confirm, $role_id, $php_timeout, $max_steps );
			if ( $run ) {
				return rest_ensure_response( array( 'task_id' => $run['id'], 'async' => true ) );
			}
			$this->stream_chat( $message, $history, $user_id, $conv, $auto_confirm, $role_id, $php_timeout, $max_steps );
			return null; // 流式输出后已终止
		}

		$agent = new Bokeauto_Agent();
		$agent->auto_confirm = $this->parse_auto_confirm( $request );
		if ( $role_id ) {
			$agent->role = Bokeauto_Role::get( $role_id );
		}
		$res   = $agent->run( $message, $history, $user_id );

		// 非流式：保存助手回复 + 记录 token 用量
		if ( $conv && isset( $res['text'] ) && '' !== trim( (string) $res['text'] ) ) {
			Bokeauto_Conversation::add_message( $conv_id, 'assistant', $res['text'] );
		}
		if ( isset( $res['usage'] ) && is_array( $res['usage'] ) ) {
			Bokeauto_Usage::log( $user_id, $conv_id, $res['usage']['prompt_tokens'], $res['usage']['completion_tokens'] );
		}

		return rest_ensure_response( $res );
	}

	/** 解析聊天页「免授权执行」开关：true=直行 / false=严格确认 / null=按全局策略 */
	private function parse_auto_confirm( $request ) {
		$v = $request->get_param( 'auto_confirm' );
		if ( null === $v ) {
			return null;
		}
		return (bool) $v;
	}

	/** 清洗对话历史：history 为 [{role, content}] 对象数组，逐项安全处理 */
	private function clean_history( $history ) {
		if ( ! is_array( $history ) ) {
			return array();
		}
		$clean = array();
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$role    = ( isset( $item['role'] ) && 'assistant' === $item['role'] ) ? 'assistant' : 'user';
			$content = isset( $item['content'] ) ? sanitize_text_field( (string) $item['content'] ) : '';
			if ( '' !== $content ) {
				$clean[] = array( 'role' => $role, 'content' => mb_substr( $content, 0, 4000 ) );
			}
		}
		return $clean;
	}

	/* -------------------------------------------------------------------
	 * 后台异步执行：把智能体主循环挪到独立进程，Web 请求瞬时返回，
	 * 前端轮询 /task-stream 拉取增量事件。拉起失败时由调用方降级为同步流式。
	 * ------------------------------------------------------------------- */

	/** 探测可用于后台执行的 CLI php 路径 */
	private function find_php_cli() {
		// 当前进程本身就是 CLI / 内置服务器 → 直接用
		$sapi = PHP_SAPI;
		if ( 'cli' === $sapi || 'cli-server' === $sapi ) {
			return PHP_BINARY;
		}
		$is_win = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
		// FPM / CGI 等 Web 环境：Linux 用 command -v，Windows 用 where（cmd 无 command -v）
		$test      = $is_win ? 'where' : 'command -v';
		$candidates = $is_win
			? array( 'php.exe', 'php', 'C:/php/php.exe', 'C:/Windows/php.exe' )
			: array( 'php', '/usr/bin/php', '/usr/local/bin/php' );
		foreach ( $candidates as $c ) {
			$out = @shell_exec( $test . ' ' . escapeshellarg( $c ) . ' 2>/dev/null' );
			if ( $out && trim( $out ) ) {
				$line = strtok( trim( $out ), "\n" ); // Windows where 可能多行，取首行
				return $line ? $line : trim( $out );
			}
		}
		// shell_exec 被禁时的绝对路径兜底
		$abs = $is_win
			? array( 'C:/php/php.exe', 'C:/Windows/php.exe' )
			: array( '/usr/bin/php', '/usr/local/bin/php' );
		foreach ( $abs as $p ) {
			if ( @is_executable( $p ) ) {
				return $p;
			}
		}
		return false;
	}

	/**
	 * 创建一次异步运行：写 meta + 空 stream 文件，拉起后台进程，返回 run id。
	 * 失败（目录不可写 / 找不到 php）返回 false，由调用方降级。
	 */
	private function start_async_run( $message, $history, $user_id, $conv, $auto_confirm, $role_id, $php_timeout = 0, $max_steps = null ) {
		$upload = wp_upload_dir();
		$dir    = $upload['basedir'] . '/bokeauto-stream';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// 清理 1 小时前的旧 run 文件，避免无限堆积
		if ( is_dir( $dir ) && $dh = opendir( $dir ) ) {
			while ( false !== ( $f = readdir( $dh ) ) ) {
				if ( '.' === $f || '..' === $f ) { continue; }
				$p = $dir . '/' . $f;
				if ( is_file( $p ) && ( time() - filemtime( $p ) ) > 3600 ) {
					@unlink( $p );
				}
			}
			closedir( $dh );
		}

		$id   = uniqid( '', true );
		$meta = array(
			'status'      => 'queued',
			'message'     => $message,
			'history'     => $history,
			'role_id'     => $role_id,
			'auto_confirm' => $auto_confirm,
			'php_timeout' => (int) $php_timeout,
			'max_steps'   => ( null === $max_steps ) ? null : (int) $max_steps,
			'conv_id'     => $conv ? (int) $conv->id : 0,
			'user_id'     => $user_id,
			'created_at'  => time(),
			'updated_at'  => time(),
		);
		file_put_contents( $dir . '/' . $id . '.meta', wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) );
		file_put_contents( $dir . '/' . $id . '.stream', '' );

		$php    = $this->find_php_cli();
		$script = dirname( __FILE__ ) . '/run-task.php';
		if ( ! $php || ! file_exists( $script ) ) {
			@unlink( $dir . '/' . $id . '.meta' );
			@unlink( $dir . '/' . $id . '.stream' );
			return false;
		}

		$cmd = '"' . $php . '" "' . $script . '" --run=' . escapeshellarg( $id )
			. ' >> ' . escapeshellarg( $dir . '/run.log' ) . ' 2>&1';
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			// start /B 脱离当前进程，"" 占位窗口标题（避免被 start 误判为标题）
			pclose( popen( 'start /B "" ' . $cmd, 'r' ) );
		} else {
			// Linux：nohup + & 让后台任务脱离 Web 请求所在会话，
			// 避免请求进程退出时被 SIGHUP 一起带走（否则异步会退化为同步、卡顿/超时回归）
			exec( 'nohup ' . $cmd . ' &' );
		}
		return array( 'id' => $id );
	}

	/** 轮询端点：按 offset 增量返回 SSE 文本 + 当前状态，Web 请求保持轻量 */
	public function api_task_stream( $request ) {
		$id     = (string) $request->get_param( 'task_id' );
		$offset = (int) $request->get_param( 'offset' );
		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $id ) ) {
			return new WP_Error( 'bokeauto_bad_task', '非法的任务标识', array( 'status' => 400 ) );
		}
		$upload      = wp_upload_dir();
		$dir         = $upload['basedir'] . '/bokeauto-stream';
		$stream_file = $dir . '/' . $id . '.stream';
		$meta_file   = $dir . '/' . $id . '.meta';
		if ( ! file_exists( $stream_file ) ) {
			return new WP_Error( 'bokeauto_no_task', '任务不存在或已过期', array( 'status' => 404 ) );
		}
		$meta   = file_exists( $meta_file ) ? json_decode( file_get_contents( $meta_file ), true ) : array();
		$owner  = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
		if ( $owner && $owner !== get_current_user_id() ) {
			return new WP_Error( 'bokeauto_forbidden', '无权访问该任务', array( 'status' => 403 ) );
		}
		$content = file_get_contents( $stream_file );
		$len     = strlen( $content );
		if ( $offset < 0 || $offset > $len ) {
			$offset = 0;
		}
		$new    = substr( $content, $offset );
		$status = isset( $meta['status'] ) ? $meta['status'] : 'running';
		// 看门狗：若一直停在 queued（后台进程未真正拉起），避免前端无限轮询
		if ( 'queued' === $status && isset( $meta['updated_at'] ) && ( time() - (int) $meta['updated_at'] ) > 15 ) {
			$status = 'error';
			$meta['error'] = '后台进程未能启动（可能是主机禁用了 proc_open/exec 或找不到 PHP CLI）';
			file_put_contents( $meta_file, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) );
		}
		$resp   = array(
			'offset' => $len,
			'data'   => $new,
			'status' => $status,
		);
		if ( in_array( $status, array( 'done', 'error' ), true ) && isset( $meta['final'] ) ) {
			$resp['final'] = $meta['final'];
		}
		return rest_ensure_response( $resp );
	}

	/** SSE 流式对话输出（降级/兜底用：输出后直接终止） */
	private function stream_chat( $message, $history, $user_id, $conv, $auto_confirm = null, $role_id = 0, $php_timeout = 0, $max_steps = null ) {
		// 彻底关闭所有输出缓冲，确保 SSE 实时推送
		ini_set( 'output_buffering', '0' );
		ini_set( 'zlib.output_compression', '0' );
		ini_set( 'implicit_flush', '1' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		// 关键修复：客户端断开（关标签页/网络抖动/前端超时重连）时不再中止 PHP，
		// 智能体在后台继续跑并把最终回复写回会话；取消执行时限，避免长任务被 PHP 杀死。
		ignore_user_abort( true );
		$php_timeout = (int) $php_timeout;
		set_time_limit( $php_timeout > 0 ? $php_timeout : 0 );
		// 生产环境（PHP-FPM）：立即把当前 worker 归还给进程池去服务其他访客，
		// SSE 仍持续推送。彻底消除"AI 回复期间整站卡顿"。php -S 内置服务器无此函数，自动跳过。
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$emitter = function ( $event, $payload ) {
			echo "event: {$event}\n";
			echo 'data: ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) . "\n\n";
			ob_flush();
			flush();
		};

		$agent = new Bokeauto_Agent();
		$agent->auto_confirm = $auto_confirm;
		if ( $role_id ) {
			$agent->role = Bokeauto_Role::get( $role_id );
		}
		if ( null !== $max_steps ) {
			$agent->set_max_steps( (int) $max_steps );
		}
		$done  = $agent->run_stream( $message, $history, $user_id, $emitter );

		// 流式结束：保存助手回复 + 记录 token 用量
		if ( $conv && isset( $done['text'] ) && '' !== trim( (string) $done['text'] ) ) {
			Bokeauto_Conversation::add_message( (int) $conv->id, 'assistant', $done['text'] );
		}
		if ( isset( $done['usage'] ) && is_array( $done['usage'] ) ) {
			Bokeauto_Usage::log( $user_id, (int) ( $conv ? $conv->id : 0 ), $done['usage']['prompt_tokens'], $done['usage']['completion_tokens'] );
		}

		echo "event: end\ndata: {}\n\n";
		flush();
		die();
	}

	public function api_confirm( $request ) {
		// 确认后续跑可能较长：放开执行时限，避免被 PHP 超时打断
		ignore_user_abort( true );
		set_time_limit( 0 );
		$hash   = sanitize_key( (string) $request->get_param( 'confirm_id' ) );
		$approve = (bool) $request->get_param( 'approve' );

		if ( '' === $hash ) {
			return new WP_Error( 'bokeauto_bad_confirm', '缺少确认标识', array( 'status' => 400 ) );
		}

		$confirm = new Bokeauto_Confirm();
		$agent   = new Bokeauto_Agent();
		$res     = $agent->resume_after_confirm( $hash, $approve, get_current_user_id() );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function api_history( $request ) {
		global $wpdb;
		$limit = min( 50, max( 1, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, summary, status, step_count, tool_count, created_at
				 FROM {$wpdb->prefix}bokeauto_tasks
				 WHERE user_id = %d
				 ORDER BY id DESC LIMIT %d",
				get_current_user_id(),
				$limit
			)
		);
		return rest_ensure_response( array( 'items' => $rows ) );
	}

	public function api_feedback( $request ) {
		$task_id = (int) $request->get_param( 'task_id' );
		$rating  = (int) $request->get_param( 'rating' );
		$note    = sanitize_text_field( (string) $request->get_param( 'note' ) );

		if ( ! $task_id || $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'bokeauto_bad_feedback', '参数错误', array( 'status' => 400 ) );
		}

		$memory = new Bokeauto_Memory();
		$memory->apply_feedback( $task_id, get_current_user_id(), $rating, $note );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_test_llm( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$base_url = esc_url_raw( (string) $request->get_param( 'base_url' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		$model    = sanitize_text_field( (string) $request->get_param( 'model' ) );
		$protocol = sanitize_text_field( (string) $request->get_param( 'protocol' ) );

		// 掩码/空 Key → 用当前已保存的 Key 测试（设置页打码回显后也能正常测试连接）
		if ( '' === $api_key || false !== strpos( $api_key, '•' ) ) {
			$saved = Bokeauto_Settings::get();
			$api_key = $saved['api_key'];
			if ( '' === $base_url ) { $base_url = $saved['base_url']; }
			if ( '' === $model ) { $model = $saved['model']; }
			if ( '' === $provider ) { $provider = $saved['provider']; }
		}

		$llm   = new Bokeauto_LLM();
		$ok    = $llm->test_connection( $provider, $base_url, $api_key, $model, $protocol );
		$proto = Bokeauto_Settings::resolve_protocol( $provider, $base_url, $protocol );
		$label = Bokeauto_Settings::protocol_labels();
		$name  = isset( $label[ $proto ] ) ? $label[ $proto ] : $proto;

		return rest_ensure_response( array(
			'ok'       => $ok,
			'protocol' => $proto,
			'message'  => $ok ? '连接成功，模型可用（' . $name . '）' : '连接失败，请检查配置（当前按 ' . $name . ' 请求）',
		) );
	}

	/** 测试嵌入服务（独立于对话模型，只验证嵌入地址与 Key） */
	public function api_test_embedding( $request ) {
		$base_url = esc_url_raw( (string) $request->get_param( 'embedding_base_url' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'embedding_api_key' ) );

		// 掩码回显的 Key 不是真实值 → 用已保存的 Key 测试
		if ( false !== strpos( $api_key, '•' ) ) {
			$api_key = '';
		}

		$llm = new Bokeauto_LLM();
		return rest_ensure_response( $llm->test_embedding( $base_url, $api_key ) );
	}

	/** 拉取服务商模型列表，成功后按 provider 缓存供下拉复用 */

	public function api_models( $request ) {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$base_url = esc_url_raw( (string) $request->get_param( 'base_url' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		$protocol = sanitize_text_field( (string) $request->get_param( 'protocol' ) );

		$saved = Bokeauto_Settings::get();
		// 掩码/空 Key → 复用已保存的 Key（与测试连接一致的行为）
		if ( '' === $api_key || false !== strpos( $api_key, '•' ) ) {
			$api_key = ( $provider === $saved['provider'] || '' === $provider )
				? $saved['api_key']
				: ( isset( $saved['providers'][ $provider ]['api_key'] ) ? $saved['providers'][ $provider ]['api_key'] : '' );
		}
		if ( '' === $base_url ) { $base_url = $saved['base_url']; }
		if ( '' === $provider ) { $provider = $saved['provider']; }

		$models = Bokeauto_LLM::fetch_models( $provider, $base_url, $api_key, $protocol );
		if ( is_wp_error( $models ) ) {
			return rest_ensure_response( array(
				'ok'      => false,
				'models'  => array(),
				'message' => '获取失败：' . $models->get_error_message(),
			) );
		}

		Bokeauto_Settings::save_fetched_models( $provider, $models );

		return rest_ensure_response( array(
			'ok'      => true,
			'models'  => $models,
			'message' => '已获取 ' . count( $models ) . ' 个模型',
		) );
	}

	public function api_memories() {
		$memory = new Bokeauto_Memory();
		$items  = $memory->list_all( 100 );
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public function api_memories_clear() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bokeauto_memories" );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 角色 ---------- */

	public function api_roles( $request ) {
		$method = $request->get_method();

		if ( 'GET' === $method ) {
			return rest_ensure_response( array( 'items' => Bokeauto_Role::list_all( '' ) ) );
		}

		// POST：新建
		$llm = $request->get_param( 'llm' );
		$res = Bokeauto_Role::create(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'system_prompt' ) ),
			(array) $request->get_param( 'tools' ),
			0,
			is_array( $llm ) ? $llm : array(),
			array(
				'role_type' => sanitize_key( (string) $request->get_param( 'role_type' ) ),
				'bind_tool' => sanitize_key( (string) $request->get_param( 'bind_tool' ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'bokeauto_role', $res->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $res ) );
	}

	public function api_role_item( $request ) {
		$id   = (int) $request['id'];
		$role = Bokeauto_Role::get( $id );
		if ( ! $role ) {
			return new WP_Error( 'bokeauto_role', '角色不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			if ( $role->is_builtin ) {
				return new WP_Error( 'bokeauto_role', '内置角色不可删除', array( 'status' => 400 ) );
			}
			Bokeauto_Role::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// POST：更新
		$fields = array();
		if ( null !== $request->get_param( 'name' ) ) { $fields['name'] = $request->get_param( 'name' ); }
		if ( null !== $request->get_param( 'description' ) ) { $fields['description'] = $request->get_param( 'description' ); }
		if ( null !== $request->get_param( 'system_prompt' ) ) { $fields['system_prompt'] = $request->get_param( 'system_prompt' ); }
		if ( null !== $request->get_param( 'tools' ) ) { $fields['tools'] = (array) $request->get_param( 'tools' ); }
		if ( null !== $request->get_param( 'role_type' ) ) { $fields['role_type'] = $request->get_param( 'role_type' ); }
		if ( null !== $request->get_param( 'bind_tool' ) ) { $fields['bind_tool'] = $request->get_param( 'bind_tool' ); }
		if ( null !== $request->get_param( 'status' ) ) { $fields['status'] = $request->get_param( 'status' ); }
		if ( $request->has_param( 'llm' ) ) { $fields['llm'] = $request->get_param( 'llm' ); }
		Bokeauto_Role::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 技能 ---------- */

	public function api_skills( $request ) {
		$method = $request->get_method();

		if ( 'GET' === $method ) {
			return rest_ensure_response( array( 'items' => Bokeauto_Skill::list_all( '' ) ) );
		}

		// POST：新建
		$res = Bokeauto_Skill::create(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			(array) $request->get_param( 'tools' ),
			sanitize_text_field( (string) $request->get_param( 'trigger' ) ),
			'manual'
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'bokeauto_skill', $res->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $res ) );
	}

	public function api_skill_item( $request ) {
		$id    = (int) $request['id'];
		$skill = Bokeauto_Skill::get( $id );
		if ( ! $skill ) {
			return new WP_Error( 'bokeauto_skill', '技能不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			Bokeauto_Skill::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$fields = array();
		if ( null !== $request->get_param( 'status' ) ) { $fields['status'] = $request->get_param( 'status' ); }
		if ( null !== $request->get_param( 'name' ) ) { $fields['name'] = $request->get_param( 'name' ); }
		if ( null !== $request->get_param( 'description' ) ) { $fields['description'] = $request->get_param( 'description' ); }
		if ( null !== $request->get_param( 'tools' ) ) { $fields['tools'] = (array) $request->get_param( 'tools' ); }
		Bokeauto_Skill::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- 会话 ---------- */

	public function api_conversations( $request ) {
		$user_id = get_current_user_id();

		if ( 'GET' === $request->get_method() ) {
			return rest_ensure_response( array( 'items' => Bokeauto_Conversation::list_all( $user_id ) ) );
		}

		// POST：新建会话
		$title = (string) $request->get_param( 'title' );
		$id    = Bokeauto_Conversation::create( $user_id, $title );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'title' => '新对话' ) );
	}

	public function api_conversation_messages( $request ) {
		$msgs = Bokeauto_Conversation::get_messages( (int) $request['id'], get_current_user_id() );
		if ( null === $msgs ) {
			return new WP_Error( 'bokeauto_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'items' => $msgs ) );
	}

	public function api_conversation_delete( $request ) {
		$ok = Bokeauto_Conversation::delete( (int) $request['id'], get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'bokeauto_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_conversation_clear( $request ) {
		$ok = Bokeauto_Conversation::clear_messages( (int) $request['id'], get_current_user_id() );
		if ( ! $ok ) {
			return new WP_Error( 'bokeauto_conv', '会话不存在或无权访问', array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function api_conversations_clear_all( $request ) {
		Bokeauto_Conversation::clear_all( get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------- Token 用量 ---------- */

	public function api_usage( $request ) {
		$user_id = get_current_user_id();
		$conv_id = (int) $request->get_param( 'conversation_id' );
		return rest_ensure_response( Bokeauto_Usage::stats( $user_id, $conv_id ) );
	}

	/** 可视化编辑保存：用户在前端编辑器手动保存文件（站点内路径 + 备份 + 审计） */
	public function api_file_save( $request ) {
		$path    = sanitize_text_field( (string) $request->get_param( 'path' ) );
		$content = (string) $request->get_param( 'content' );
		if ( '' === $path ) {
			return new WP_Error( 'bokeauto_file', '缺少文件路径', array( 'status' => 400 ) );
		}

		$real = Bokeauto_Tools::resolve_safe_path( $path ); // 写路径：必须位于站点根目录内
		if ( false === $real ) {
			return new WP_Error( 'bokeauto_file', '路径非法或超出站点根目录', array( 'status' => 400 ) );
		}
		if ( ! is_file( $real ) ) {
			return new WP_Error( 'bokeauto_file', '文件不存在：' . $real, array( 'status' => 400 ) );
		}
		if ( ! is_writable( $real ) && ! is_writable( dirname( $real ) ) ) {
			return new WP_Error( 'bokeauto_file', '文件不可写（检查权限）', array( 'status' => 400 ) );
		}

		// 备份原文件
		$backup_dir = wp_upload_dir()['basedir'] . '/bokeauto-backups/files';
		wp_mkdir_p( $backup_dir );
		$stamp = date( 'Ymd-His' );
		copy( $real, $backup_dir . '/' . basename( $real ) . '.' . $stamp . '.bak' );

		$ok = file_put_contents( $real, $content );
		if ( false === $ok ) {
			return new WP_Error( 'bokeauto_file', '保存失败（检查文件权限）', array( 'status' => 500 ) );
		}

		Bokeauto_Audit::log( 'file_save_manual', array( 'path' => $real, 'bytes' => $ok ), get_current_user_id() );

		// 若是 PHP 文件，顺手语法检查提示
		$lint = '';
		if ( preg_match( '/\.php$/i', $real ) && class_exists( 'Bokeauto_Tools_File' ) ) {
			$lint_res = Bokeauto_Tools::execute( 'validate_php', array( 'path' => $real ) );
			if ( ! $lint_res['ok'] ) {
				$lint = ' ⚠ PHP 语法检查未通过：' . mb_substr( $lint_res['message'], 0, 200 );
			}
		}

		return rest_ensure_response( array(
			'ok'      => true,
			'message' => '已保存：' . $real . '（' . $ok . ' 字节）' . $lint,
			'backup'  => $backup_dir . '/' . basename( $real ) . '.' . $stamp . '.bak',
		) );
	}

	/* ---------- 设置（聊天页模型切换用） ---------- */

	public function api_settings( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$s = Bokeauto_Settings::get();
			return rest_ensure_response( array(
				'settings' => array(
					'provider'      => $s['provider'],
					'base_url'      => $s['base_url'],
					'model'         => $s['model'],
					'has_key'       => '' !== $s['api_key'],
					'key_masked'    => '' === $s['api_key'] ? '' : substr( $s['api_key'], 0, 4 ) . '••••' . substr( $s['api_key'], -4 ),
					'embedding_model' => $s['embedding_model'],
					'embedding_ready' => '' !== $s['embedding_api_key'],
					'confirm_mode'  => $s['confirm_mode'],
					'mock_mode'     => $s['mock_mode'],
				),
				'presets' => Bokeauto_Settings::presets(),
			) );
		}

		// POST：部分更新（provider/base_url/model 等；不传 api_key 则保留原 Key）
		$data = $request->get_params();

		// 切换 provider 时【不】自动改写 base_url：用户手动设置的地址原样保留（持久化优先），
		// 只有用户显式传 base_url 才会更新，防止预设信息覆盖用户自定义配置
		$saved = Bokeauto_Settings::update( $data );
		return rest_ensure_response( array(
			'ok'       => true,
			'settings' => array(
				'provider'   => $saved['provider'],
				'model'      => $saved['model'],
				'has_key'    => '' !== $saved['api_key'],
				'key_masked' => '' === $saved['api_key'] ? '' : substr( $saved['api_key'], 0, 4 ) . '••••' . substr( $saved['api_key'], -4 ),
			),
		) );
	}

	/* ---------- 定时任务 ---------- */

	public function api_schedules( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$items = array();
			foreach ( Bokeauto_Schedule::list_all() as $t ) {
				$items[] = self::schedule_row( $t );
			}
			return rest_ensure_response( array( 'items' => $items, 'intervals' => Bokeauto_Schedule::interval_labels() ) );
		}

		// POST：创建
		$id = Bokeauto_Schedule::create( $request->get_params(), get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'bokeauto_schedule', $id->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'item' => self::schedule_row( Bokeauto_Schedule::get( $id ) ) ) );
	}

	public function api_schedule_item( $request ) {
		$id = (int) $request['id'];
		$t  = Bokeauto_Schedule::get( $id );
		if ( ! $t ) {
			return new WP_Error( 'bokeauto_schedule', '定时任务不存在', array( 'status' => 404 ) );
		}

		if ( 'DELETE' === $request->get_method() ) {
			Bokeauto_Schedule::delete( $id );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// POST：更新
		$fields = array();
		foreach ( array( 'name', 'prompt', 'interval_type', 'at_time', 'day_of_week', 'interval_minutes', 'auto_high', 'status' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v ) {
				$fields[ $k ] = $v;
			}
		}
		Bokeauto_Schedule::update( $id, $fields );
		return rest_ensure_response( array( 'ok' => true, 'item' => self::schedule_row( Bokeauto_Schedule::get( $id ) ) ) );
	}

	public function api_schedule_run( $request ) {
		$id = (int) $request['id'];
		$t  = Bokeauto_Schedule::get( $id );
		if ( ! $t ) {
			return new WP_Error( 'bokeauto_schedule', '定时任务不存在', array( 'status' => 404 ) );
		}
		$res = Bokeauto_Schedule::run( $id, 'manual' );
		return rest_ensure_response( array( 'ok' => true, 'result' => $res, 'item' => self::schedule_row( Bokeauto_Schedule::get( $id ) ) ) );
	}

	/** 工作日志：GET 列表 / POST 保存（day 日期 YYYY-MM-DD；mode=append 追加 / save 整体覆盖） */
	public function api_worklogs( $request ) {
		if ( 'GET' === $request->get_method() ) {
			$limit = min( 200, max( 1, (int) $request->get_param( 'limit' ) ?: 30 ) );
			return rest_ensure_response( array( 'items' => Bokeauto_Worklog::list( $limit ) ) );
		}

		$day     = Bokeauto_Worklog::normalize_day( (string) $request->get_param( 'day' ) );
		$content = (string) $request->get_param( 'content' );
		$mode    = 'append' === (string) $request->get_param( 'mode' ) ? 'append' : 'save';

		if ( 'append' === $mode ) {
			$res = Bokeauto_Worklog::append( $content, $day );
		} else {
			$res = Bokeauto_Worklog::update( $day, $content );
		}
		$res['item'] = null;
		foreach ( Bokeauto_Worklog::list( 30 ) as $row ) {
			if ( $row['log_date'] === $day ) {
				$res['item'] = $row;
				break;
			}
		}
		return rest_ensure_response( $res );
	}

	public function api_worklog_delete( $request ) {
		$day = Bokeauto_Worklog::normalize_day( (string) $request['day'] );
		return rest_ensure_response( Bokeauto_Worklog::delete( $day ) );
	}
}
