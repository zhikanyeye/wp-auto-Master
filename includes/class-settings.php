<?php
/**
 * 设置管理与存取
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

class Bokeauto_Settings {

	const OPTION = 'bokeauto_settings';

	/**
	 * 支持的接口协议。
	 *
	 * openai-chat      OpenAI Chat Completions（/chat/completions，绝大多数国内外服务商兼容此协议）
	 * openai-responses OpenAI Responses API（/responses，字段为 input/instructions/output）
	 * anthropic        Anthropic Messages（/v1/messages，x-api-key 鉴权，tool_use/tool_result 结构）
	 */
	const PROTOCOLS = array( 'openai-chat', 'openai-responses', 'anthropic' );

	/**
	 * 记忆向量化固定使用的嵌入模型。
	 *
	 * 选定 BAAI/bge-m3 的原因：中文语义检索效果处于开源模型第一梯队，
	 * 且硅基流动对其提供免费额度、接口为 OpenAI 兼容的 /v1/embeddings，
	 * 用户只需填一个 Key 即可启用，无需再纠结模型名怎么写。
	 * 该模型不随对话服务商变化，因此固定在代码里而不做成可填项。
	 */
	const EMBEDDING_MODEL = 'BAAI/bge-m3';

	/** 嵌入服务默认地址（硅基流动） */
	const EMBEDDING_BASE_URL = 'https://api.siliconflow.cn/v1';

	/** 协议中文标签（前端下拉展示用） */
	public static function protocol_labels() {
		return array(
			'openai-chat'      => 'OpenAI Chat Completions（/chat/completions）',
			'openai-responses' => 'OpenAI Responses（/responses）',
			'anthropic'        => 'Anthropic Messages（/v1/messages）',
		);
	}

	/** 默认设置 */
	public static function defaults() {
		return array(
			'provider'       => 'deepseek',
			'base_url'       => 'https://api.deepseek.com/v1',
			'api_key'        => '',
			'model'          => 'deepseek-chat',
			// 接口协议：空串表示跟随 provider 预设自动判定
			'protocol'       => '',
			'temperature'    => 0.7,
			'max_steps'      => 15,
			'max_tokens'     => 4096,
			'confirm_mode'   => 'high',   // auto | high | all
			// 嵌入（记忆向量化）配置：完全独立于对话模型，互不影响。
			// 模型固定为 self::EMBEDDING_MODEL，只需用户填自己的硅基流动 Key。
			'embedding_base_url' => self::EMBEDDING_BASE_URL,
			'embedding_api_key'  => '',
			'memory_enabled' => 1,
			'mock_mode'      => 0,
			// 各服务商独立保存的配置（provider => base_url/api_key/model/protocol），切换服务商时自动加载
			'providers'      => array(),
			// 动态拉取到的模型列表缓存（provider => array of model id）
			'fetched_models' => array(),
			// 生图配置（generate_image 工具使用，可独立于对话模型）
			'image_provider' => 'zhipu',
			'image_base_url' => 'https://open.bigmodel.cn/api/paas/v4',
			'image_api_key'  => '',
			'image_model'    => 'cogview-3-flash',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$out   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		// 嵌入模型固定：忽略历史遗留值（老版本可能存着 text-embedding-v1），
		// 保证升级后的站点自动切到 bge-m3，不需要用户手动改设置。
		$out['embedding_model'] = self::EMBEDDING_MODEL;

		// 地址被清空时回落到默认，避免拼出无效端点导致嵌入静默失效
		if ( empty( $out['embedding_base_url'] ) ) {
			$out['embedding_base_url'] = self::EMBEDDING_BASE_URL;
		}

		return $out;
	}

	/**
	 * 更新设置（部分更新：只覆盖传入字段，未传字段保留原值；api_key 传空串表示清空）
	 *
	 * 核心设计：每个服务商（provider）独立保存自己的 base_url / api_key / model（providers 索引）。
	 * - 切换 provider 时：先把当前服务商的配置归档到 providers，再加载新服务商已保存的配置（无则用预设填充）
	 * - 保存时：显式传入的 base_url/model/api_key 覆盖当前服务商的配置并同步进 providers
	 * - 因此「切到哪个服务商，实际调用的就是哪个服务商自己已保存的配置」
	 */
	public static function update( $data ) {
		$current   = self::get();
		$clean     = array();
		$providers = isset( $current['providers'] ) && is_array( $current['providers'] ) ? $current['providers'] : array();

		$old_provider = isset( $current['provider'] ) ? $current['provider'] : '';
		$new_provider = isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : $old_provider;
		$switching    = ( '' !== $new_provider && $new_provider !== $old_provider );

		// 1) 切换前：归档当前服务商的配置
		if ( $switching && '' !== $old_provider ) {
			$providers[ $old_provider ] = array(
				'base_url' => $current['base_url'],
				'api_key'  => $current['api_key'],
				'model'    => $current['model'],
				'protocol' => isset( $current['protocol'] ) ? $current['protocol'] : '',
			);
		}

		// 2) 显式传入字段
		if ( isset( $data['provider'] ) ) {
			$clean['provider'] = $new_provider;
		}
		if ( isset( $data['base_url'] ) ) {
			$clean['base_url'] = esc_url_raw( $data['base_url'] );
		}
		if ( isset( $data['protocol'] ) ) {
			// 空串表示「自动」（跟随 provider 预设判定）；非法值一律回退为自动
			$p = sanitize_text_field( $data['protocol'] );
			$clean['protocol'] = in_array( $p, self::PROTOCOLS, true ) ? $p : '';
		}
		if ( isset( $data['api_key'] ) ) {
			// 掩码（含 •）不覆盖真实 Key；空串表示清空；其余正常更新
			if ( false !== strpos( (string) $data['api_key'], '•' ) ) {
				// 保留原值
			} elseif ( '' === trim( (string) $data['api_key'] ) ) {
				$clean['api_key'] = '';
			} else {
				$clean['api_key'] = sanitize_text_field( $data['api_key'] );
			}
		}
		if ( isset( $data['model'] ) ) {
			$clean['model'] = sanitize_text_field( $data['model'] );
		}
		if ( isset( $data['temperature'] ) ) {
			$clean['temperature'] = (float) $data['temperature'];
		}
		if ( isset( $data['max_steps'] ) ) {
			$clean['max_steps'] = min( 40, max( 3, (int) $data['max_steps'] ) );
		}
		if ( isset( $data['max_tokens'] ) ) {
			$clean['max_tokens'] = min( 128000, max( 256, (int) $data['max_tokens'] ) );
		}
		if ( isset( $data['confirm_mode'] ) && in_array( $data['confirm_mode'], array( 'auto', 'high', 'all' ), true ) ) {
			$clean['confirm_mode'] = $data['confirm_mode'];
		}
		if ( isset( $data['embedding_base_url'] ) ) {
			$u = esc_url_raw( $data['embedding_base_url'] );
			$clean['embedding_base_url'] = '' === $u ? self::EMBEDDING_BASE_URL : $u;
		}
		if ( isset( $data['embedding_api_key'] ) ) {
			// 与主 Key 同规则：掩码不覆盖真实值，空串表示清空（清空即回到关键词检索）
			if ( false !== strpos( (string) $data['embedding_api_key'], '•' ) ) {
				// 保留原值
			} elseif ( '' === trim( (string) $data['embedding_api_key'] ) ) {
				$clean['embedding_api_key'] = '';
			} else {
				$clean['embedding_api_key'] = sanitize_text_field( $data['embedding_api_key'] );
			}
		}
		if ( isset( $data['memory_enabled'] ) ) {
			$clean['memory_enabled'] = empty( $data['memory_enabled'] ) ? 0 : 1;
		}
		if ( isset( $data['mock_mode'] ) ) {
			$clean['mock_mode'] = empty( $data['mock_mode'] ) ? 0 : 1;
		}
		// 生图配置（generate_image 工具）
		if ( isset( $data['image_provider'] ) ) {
			$clean['image_provider'] = sanitize_key( $data['image_provider'] );
		}
		if ( isset( $data['image_base_url'] ) ) {
			$clean['image_base_url'] = esc_url_raw( $data['image_base_url'] );
		}
		if ( isset( $data['image_api_key'] ) && '' !== trim( (string) $data['image_api_key'] ) && false === strpos( (string) $data['image_api_key'], '•' ) ) {
			$clean['image_api_key'] = sanitize_text_field( $data['image_api_key'] );
		}
		if ( isset( $data['image_model'] ) ) {
			$clean['image_model'] = sanitize_text_field( $data['image_model'] );
		}

		// 3) 切换时：加载新服务商已保存的配置（对未显式传入的字段）；无已保存配置则用预设填充
		if ( $switching ) {
			if ( isset( $providers[ $new_provider ] ) && is_array( $providers[ $new_provider ] ) ) {
				$saved = $providers[ $new_provider ];
				if ( ! isset( $data['base_url'] ) && ! empty( $saved['base_url'] ) ) {
					$clean['base_url'] = $saved['base_url'];
				}
				if ( ! isset( $data['model'] ) && ! empty( $saved['model'] ) ) {
					$clean['model'] = $saved['model'];
				}
				if ( ! isset( $data['api_key'] ) && ! empty( $saved['api_key'] ) ) {
					$clean['api_key'] = $saved['api_key'];
				}
				if ( ! isset( $data['protocol'] ) ) {
					$clean['protocol'] = isset( $saved['protocol'] ) ? $saved['protocol'] : '';
				}
			} else {
				$presets = self::presets();
				if ( isset( $presets[ $new_provider ] ) ) {
					if ( ! isset( $data['base_url'] ) && ! empty( $presets[ $new_provider ]['base_url'] ) ) {
						$clean['base_url'] = $presets[ $new_provider ]['base_url'];
					}
					if ( ! isset( $data['model'] ) && ! empty( $presets[ $new_provider ]['model'] ) ) {
						$clean['model'] = $presets[ $new_provider ]['model'];
					}
				}
				// 目标服务商从未保存过 → 协议回到「自动」，由预设判定
				if ( ! isset( $data['protocol'] ) ) {
					$clean['protocol'] = '';
				}
				// 目标服务商从未保存过 → 清空 Key，避免把上一个服务商的 Key 带过去调错 API
				if ( ! isset( $data['api_key'] ) ) {
					$clean['api_key'] = '';
				}
			}
		}

		$merged = array_merge( $current, $clean );

		// 4) 把当前生效配置同步进 providers[当前服务商]（显式保存或切换后都同步）
		$merged_prov = $merged['provider'];
		$providers[ $merged_prov ] = array(
			'base_url' => isset( $merged['base_url'] ) ? $merged['base_url'] : '',
			'api_key'  => isset( $merged['api_key'] ) ? $merged['api_key'] : '',
			'model'    => isset( $merged['model'] ) ? $merged['model'] : '',
			'protocol' => isset( $merged['protocol'] ) ? $merged['protocol'] : '',
		);
		$merged['providers'] = $providers;

		update_option( self::OPTION, $merged );
		return $merged;
	}

	/**
	 * 判定实际生效的接口协议。
	 *
	 * 优先级：显式配置 > provider 预设 > base_url 域名启发 > OpenAI Chat Completions。
	 * 这样「自定义站点」既能自动识别常见地址，也允许用户手动指定协议覆盖判定结果。
	 *
	 * @param string $provider 服务商键名。
	 * @param string $base_url API 基础地址。
	 * @param string $explicit 用户显式选择的协议，空串表示自动。
	 * @return string PROTOCOLS 中的一项。
	 */
	public static function resolve_protocol( $provider, $base_url = '', $explicit = '' ) {
		if ( in_array( $explicit, self::PROTOCOLS, true ) ) {
			return $explicit;
		}

		$presets = self::presets();
		if ( isset( $presets[ $provider ]['protocol'] ) && in_array( $presets[ $provider ]['protocol'], self::PROTOCOLS, true ) ) {
			return $presets[ $provider ]['protocol'];
		}

		$url = strtolower( (string) $base_url );
		if ( false !== strpos( $url, 'api.anthropic.com' ) ) {
			return 'anthropic';
		}
		// 地址直接写到 /responses 端点时按 Responses 协议处理
		if ( '/responses' === substr( rtrim( $url, '/' ), -10 ) ) {
			return 'openai-responses';
		}

		return 'openai-chat';
	}

	/** 存放动态拉取到的模型列表（按 provider 归档，供前端下拉复用） */
	public static function save_fetched_models( $provider, $models ) {
		$provider = sanitize_key( $provider );
		if ( '' === $provider ) {
			return;
		}
		$settings = self::get();
		$all      = isset( $settings['fetched_models'] ) && is_array( $settings['fetched_models'] ) ? $settings['fetched_models'] : array();

		$clean = array();
		foreach ( (array) $models as $m ) {
			$m = sanitize_text_field( (string) $m );
			if ( '' !== $m ) {
				$clean[] = $m;
			}
		}
		// 保留服务商返回的完整模型列表，避免刷新设置页后模型被静默截断。
		$all[ $provider ] = array_values( array_unique( $clean ) );

		$settings['fetched_models'] = $all;
		update_option( self::OPTION, $settings );
	}

	/** 两个 URL 是否同域名（用于判断 base_url 是否属于某个 provider 的预设地址） */
	public static function same_host( $url1, $url2 ) {
		$h1 = parse_url( (string) $url1, PHP_URL_HOST );
		$h2 = parse_url( (string) $url2, PHP_URL_HOST );
		return $h1 && $h2 && strtolower( $h1 ) === strtolower( $h2 );
	}

	/** base_url 规范化：与 provider 预设同域名 → 对齐为预设基础地址；否则视为自定义保留原样 */
	public static function normalize_base_url( $provider, $base_url ) {
		$presets = self::presets();
		if ( isset( $presets[ $provider ]['base_url'] ) && $presets[ $provider ]['base_url'] ) {
			$preset = rtrim( (string) $presets[ $provider ]['base_url'], '/' );
			if ( self::same_host( $base_url, $preset ) ) {
				return $preset;
			}
		}
		return $base_url;
	}

	/** 预设 Provider 模板（含各家 API 地址与可用模型） */
	public static function presets() {
		return array(
			'deepseek' => array(
				'label'    => 'DeepSeek 深度求索',
				'protocol' => 'openai-chat',
				'base_url' => 'https://api.deepseek.com/v1',
				'model'    => 'deepseek-chat',
				'models'   => array( 'deepseek-chat', 'deepseek-reasoner' ),
			),
			'zhipu' => array(
				'label'    => '智谱 GLM',
				'protocol' => 'openai-chat',
				'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
				'model'    => 'glm-4.5-flash',
				'models'   => array( 'glm-4.5-flash', 'glm-4.5-air', 'glm-4.5-airx', 'glm-4.5', 'glm-4.5-x', 'glm-4-plus', 'glm-4-air', 'glm-4-flash', 'embedding-2' ),
			),
			'qwen' => array(
				'label'    => '通义千问（阿里云）',
				'protocol' => 'openai-chat',
				'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
				'model'    => 'qwen-plus',
				'models'   => array( 'qwen-max', 'qwen-plus', 'qwen-turbo', 'qwen-long', 'qwen2.5-72b-instruct', 'qwen2.5-32b-instruct', 'text-embedding-v3', 'text-embedding-v1' ),
			),
			'hunyuan' => array(
				'label'    => '腾讯云混元',
				'protocol' => 'openai-chat',
				'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
				'model'    => 'hunyuan-turbos',
				'models'   => array( 'hunyuan-turbos', 'hunyuan-turbo', 'hunyuan-turbo-latest', 'hunyuan-standard', 'hunyuan-lite', 'hunyuan-pro' ),
			),
			'kimi' => array(
				'label'    => 'KIMI（月之暗面）',
				'protocol' => 'openai-chat',
				'base_url' => 'https://api.moonshot.cn/v1',
				'model'    => 'kimi-latest',
				'models'   => array( 'kimi-latest', 'kimi-k2-0711-preview', 'moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k' ),
			),
			'wenxin' => array(
				'label'    => '文心一言（百度千帆）',
				'protocol' => 'openai-chat',
				'base_url' => 'https://qianfan.baidubce.com/v2',
				'model'    => 'ernie-4.0-8k',
				'models'   => array( 'ernie-4.0-8k', 'ernie-4.0-turbo-8k', 'ernie-3.5-8k', 'ernie-speed-8k', 'ernie-lite-8k' ),
			),
			'openai' => array(
				'label'    => 'OpenAI',
				'protocol' => 'openai-chat',
				'base_url' => 'https://api.openai.com/v1',
				'model'    => 'gpt-4o-mini',
				'models'   => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo', 'o3-mini', 'text-embedding-3-small' ),
			),
			'gemini' => array(
				'label'    => 'Gemini（Google，OpenAI 兼容端点）',
				'protocol' => 'openai-chat',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'    => 'gemini-2.0-flash',
				'models'   => array( 'gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-pro', 'gemini-1.5-flash' ),
			),
			'claude' => array(
				'label'    => 'Claude（Anthropic，原生适配）',
				'protocol' => 'anthropic',
				'base_url' => 'https://api.anthropic.com',
				'model'    => 'claude-sonnet-4-20250514',
				'models'   => array( 'claude-sonnet-4-20250514', 'claude-opus-4-20250514', 'claude-3-7-sonnet-20250219', 'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022' ),
			),
			'custom' => array(
				'label'    => '自定义（OpenAI 兼容）',
				'protocol' => '', // 留空 → 由 base_url 自动判定
				'base_url' => '',
				'model'    => '',
				'models'   => array(),
			),
			'mock' => array(
				'label'    => '本地演示模式（无需 Key）',
				'protocol' => '', // 留空 → 由 base_url 自动判定
				'base_url' => '',
				'model'    => 'mock',
				'models'   => array(),
			),
		);
	}
}
