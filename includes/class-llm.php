<?php
/**
 * LLM 客户端（多协议）
 *
 * 支持三种接口协议，由设置中的 protocol 或 provider 预设自动判定：
 * - openai-chat      OpenAI Chat Completions（/chat/completions）
 * - openai-responses OpenAI Responses API（/responses）
 * - anthropic        Anthropic Messages（/v1/messages）
 *
 * 能力：对话 + function calling + 流式 + embeddings + 模型列表 + 本地演示模式
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

class Bokeauto_LLM {

	private $settings;

	/** 当前生效的接口协议（Bokeauto_Settings::PROTOCOLS 之一） */
	private $protocol;

	/** 是否使用了角色级模型覆盖（用于调用失败时降级全局模型重试） */
	public $using_override = false;

	/** 调用失败后被降级的角色模型名（仅提示用，不做任何规则判断） */
	public $rejected_override_model = '';

	public function __construct( $override = array() ) {
		$settings = Bokeauto_Settings::get();
		if ( is_array( $override ) && ! empty( $override['api_key'] ) ) {
			$this->using_override = true;
			// 角色级覆盖：仅覆盖明确提供的字段，其余沿用全局。
			// 不做「非对话型模型」名字判断——模型能不能聊天由调用结果说了算（失败自动降级）。
			$settings = array_merge( $settings, array(
				'provider' => ! empty( $override['provider'] ) ? $override['provider'] : $settings['provider'],
				'base_url' => ! empty( $override['base_url'] ) ? $override['base_url'] : $settings['base_url'],
				'api_key'  => $override['api_key'],
				'model'    => ! empty( $override['model'] ) ? $override['model'] : $settings['model'],
				// 角色可单独指定协议；未指定时按其 provider/base_url 重新判定，不沿用全局显式协议，
				// 否则「全局 Claude + 角色用 DeepSeek」会拿 Anthropic 格式去请求 OpenAI 端点。
				'protocol' => isset( $override['protocol'] ) ? $override['protocol'] : '',
			) );
		}
		$this->settings = $settings;
		$this->protocol = Bokeauto_Settings::resolve_protocol(
			$settings['provider'],
			$settings['base_url'],
			isset( $settings['protocol'] ) ? $settings['protocol'] : ''
		);
	}

	/** 当前生效协议 */
	public function protocol() {
		return $this->protocol;
	}

	/** 输出上限（各协议共用；Chat Completions 不强制传，避免部分兼容服务不识别） */
	private function max_tokens() {
		$n = isset( $this->settings['max_tokens'] ) ? (int) $this->settings['max_tokens'] : 4096;
		return min( 128000, max( 256, $n ) );
	}

	/** 是否演示模式 */
	private function is_mock() {
		return ! empty( $this->settings['mock_mode'] ) || 'mock' === $this->settings['provider'];
	}

	/* ---------------------------------------------------------------------
	 * 流式 Chat：逐块实时回调内容（SSE），完成后返回完整结果
	 * $on_delta( $text ) 每收到一段文本调用一次
	 * $on_reasoning( $text ) 每收到一段深度思考内容调用一次（模型支持时）
	 * 返回同 chat()：array{ content, tool_calls, usage } 或 WP_Error
	 * ------------------------------------------------------------------- */

	public function stream_chat( $messages, $tools = array(), $on_delta = null, $on_reasoning = null ) {
		$settings = $this->settings;

		if ( $this->is_mock() ) {
			$resp = $this->mock_chat( $messages );
			if ( $on_delta && ! empty( $resp['content'] ) ) {
				$on_delta( $resp['content'] );
			}
			return $resp;
		}

		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'bokeauto_no_key', '尚未配置模型 API Key，请在「波克wpAI → 模型设置」中完成配置' );
		}

		if ( 'anthropic' === $this->protocol ) {
			return $this->claude_stream( $messages, $tools, $on_delta, $on_reasoning );
		}

		if ( 'openai-responses' === $this->protocol ) {
			return $this->responses_stream( $messages, $tools, $on_delta, $on_reasoning );
		}

		$body = array(
			'model'       => $settings['model'],
			'messages'    => $messages,
			'temperature' => (float) $settings['temperature'],
			'stream'      => true,
		);
		if ( ! empty( $tools ) ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'auto';
		}

		$content = '';
		$tool_calls = array();
		$usage   = array();

		$ch = curl_init( $this->endpoint( 'chat/completions' ) );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $settings['api_key'],
			),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$tool_calls, &$usage, $on_delta, $on_reasoning ) {
				$lines = preg_split( "/\r?\n/", $chunk );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line || 'data:' !== substr( $line, 0, 5 ) ) {
						continue;
					}
					$payload = trim( substr( $line, 5 ) );
					if ( '[DONE]' === $payload ) {
						continue;
					}
					$json = json_decode( $payload, true );
					if ( ! is_array( $json ) ) {
						continue;
					}
					// 流式末尾的 usage（多数兼容模型会返回）
					if ( ! empty( $json['usage'] ) && is_array( $json['usage'] ) ) {
						$usage = $json['usage'];
					}
					if ( empty( $json['choices'][0] ) ) {
						continue;
					}
					$delta = isset( $json['choices'][0]['delta'] ) ? $json['choices'][0]['delta'] : array();
					// 深度思考内容（glm-4.5 等模型返回 reasoning_content）
					if ( is_callable( $on_reasoning ) && isset( $delta['reasoning_content'] ) && is_string( $delta['reasoning_content'] ) && '' !== $delta['reasoning_content'] ) {
						$on_reasoning( $delta['reasoning_content'] );
					}
					if ( isset( $delta['content'] ) && is_string( $delta['content'] ) && '' !== $delta['content'] ) {
						$content .= $delta['content'];
						if ( is_callable( $on_delta ) ) {
							$on_delta( $delta['content'] );
						}
					}
					if ( ! empty( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
						foreach ( $delta['tool_calls'] as $tc ) {
							$idx = isset( $tc['index'] ) ? (int) $tc['index'] : 0;
							if ( ! isset( $tool_calls[ $idx ] ) ) {
								$tool_calls[ $idx ] = array( 'id' => '', 'name' => '', 'arguments' => '' );
							}
							if ( ! empty( $tc['id'] ) ) {
								$tool_calls[ $idx ]['id'] = $tc['id'];
							}
							if ( ! empty( $tc['function']['name'] ) ) {
								$tool_calls[ $idx ]['name'] = $tc['function']['name'];
							}
							if ( isset( $tc['function']['arguments'] ) ) {
								$tool_calls[ $idx ]['arguments'] .= $tc['function']['arguments'];
							}
						}
					}
				}
				return strlen( $chunk );
			},
		) );

		$resp_body = curl_exec( $ch );
		$err       = curl_error( $ch );
		$code      = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( '' !== $err ) {
			return new WP_Error( 'bokeauto_http', $err );
		}
		if ( $code >= 400 ) {
			// 通用降级（不依赖模型名规则）：角色覆盖模型流式调用失败 → 自动用全局模型重试一次
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Bokeauto_LLM();
				$retry  = $global->stream_chat( $messages, $tools, $on_delta, $on_reasoning );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'bokeauto_api', '模型服务返回错误（HTTP ' . $code . '），请稍后重试或更换模型' );
		}

		// 汇总 tool_calls（流式增量已拼好）
		$out_calls = array();
		foreach ( $tool_calls as $tc ) {
			$out_calls[] = array(
				'id'        => $tc['id'],
				'name'      => $tc['name'],
				'arguments' => '' === $tc['arguments'] ? '{}' : $tc['arguments'],
			);
		}

		return array(
			'content'    => $content,
			'tool_calls' => $out_calls,
			'usage'      => $this->extract_usage( $usage ),
		);
	}

	/* ---------------------------------------------------------------------
	 * URL 规范化：兼容「基础地址」与「已写到具体端点」两种填法
	 * ------------------------------------------------------------------- */

	private function endpoint( $type, $base_url = '' ) {
		$base = rtrim( $base_url ? $base_url : $this->settings['base_url'], '/' );
		// 用户填的地址若已包含目标端点 → 原样使用，绝不重复拼接/截断重拼
		$suffix = '/' . $type;
		if ( substr( $base, -strlen( $suffix ) ) === $suffix ) {
			return $base;
		}
		// 地址写到了别的端点（如填 /chat/completions 却要请求 embeddings）→ 先剥离再拼，
		// 避免拼出 /chat/completions/embeddings 这类无效地址
		foreach ( array( '/chat/completions', '/responses', '/embeddings' ) as $known ) {
			if ( substr( $base, -strlen( $known ) ) === $known ) {
				$base = rtrim( substr( $base, 0, -strlen( $known ) ), '/' );
				break;
			}
		}
		return $base . $suffix;
	}

	/* ---------------------------------------------------------------------
	 * Chat（含工具调用）
	 * 返回 array{ content: string, tool_calls: array }
	 * 失败返回 WP_Error
	 * ------------------------------------------------------------------- */

	public function chat( $messages, $tools = array(), $temperature = null ) {
		$settings = $this->settings;

		if ( $this->is_mock() ) {
			return $this->mock_chat( $messages );
		}

		if ( 'anthropic' === $this->protocol ) {
			return $this->claude_chat( $messages, $tools, $temperature );
		}

		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'bokeauto_no_key', '尚未配置模型 API Key，请在「波克wpAI → 模型设置」中完成配置' );
		}

		if ( 'openai-responses' === $this->protocol ) {
			return $this->responses_chat( $messages, $tools, $temperature );
		}

		$temperature = null === $temperature ? $settings['temperature'] : (float) $temperature;

		$body = array(
			'model'       => $settings['model'],
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		if ( ! empty( $tools ) ) {
			$body['tools']     = $tools;
			$body['tool_choice'] = 'auto';
		}

		$resp = wp_remote_post(
			$this->endpoint( 'chat/completions' ),
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['api_key'],
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'bokeauto_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || empty( $data['choices'][0] ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			// 通用降级（不依赖模型名规则）：角色覆盖模型调用失败 → 自动用全局模型重试一次。
			// 例如把生图模型当对话模型用时接口会 404，重试即可正常完成。
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Bokeauto_LLM();
				$retry  = $global->chat( $messages, $tools, $temperature );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'bokeauto_api', $msg );
		}

		$choice  = $data['choices'][0]['message'];
		$content = isset( $choice['content'] ) && is_string( $choice['content'] ) ? $choice['content'] : '';

		$tool_calls = array();
		if ( ! empty( $choice['tool_calls'] ) && is_array( $choice['tool_calls'] ) ) {
			foreach ( $choice['tool_calls'] as $tc ) {
				if ( 'function' !== $tc['type'] ) {
					continue;
				}
				$tool_calls[] = array(
					'id'       => isset( $tc['id'] ) ? $tc['id'] : '',
					'name'     => isset( $tc['function']['name'] ) ? $tc['function']['name'] : '',
					'arguments'=> isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '{}',
				);
			}
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( isset( $data['usage'] ) ? $data['usage'] : array() ),
		);
	}

	/** 从 API usage 提取 token 统计（缺省时估算） */
	private function extract_usage( $usage ) {
		$p = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$c = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
		return array(
			'prompt_tokens'     => $p,
			'completion_tokens' => $c,
			'total_tokens'      => $p + $c,
		);
	}

	/* ---------------------------------------------------------------------
	 * OpenAI Responses API 适配
	 *
	 * 与 Chat Completions 的差异：
	 * - 端点 /responses，system 提示走 instructions，对话历史走 input 数组
	 * - 工具定义是扁平结构（name/description/parameters 直接在对象上，无 function 包裹）
	 * - 工具调用与结果是 input 里的 function_call / function_call_output 条目，
	 *   通过 call_id 关联，不再是 assistant.tool_calls + role=tool 消息
	 * - 输出在 output 数组里，文本为 output_text，上限字段是 max_output_tokens
	 * ------------------------------------------------------------------- */

	private function responses_url() {
		return $this->endpoint( 'responses' );
	}

	/**
	 * OpenAI Chat 消息数组 → Responses 的 instructions + input。
	 *
	 * @param array $messages Chat Completions 格式消息。
	 * @return array array{0: string 系统指令, 1: array input 条目}
	 */
	private function to_responses_input( $messages ) {
		$instructions = '';
		$input        = array();

		foreach ( (array) $messages as $m ) {
			$role    = isset( $m['role'] ) ? $m['role'] : 'user';
			$content = isset( $m['content'] ) ? $m['content'] : '';

			if ( 'system' === $role ) {
				$instructions .= ( '' !== $instructions ? "\n" : '' ) . ( is_string( $content ) ? $content : '' );
				continue;
			}

			if ( 'tool' === $role ) {
				$input[] = array(
					'type'    => 'function_call_output',
					'call_id' => isset( $m['tool_call_id'] ) ? $m['tool_call_id'] : '',
					'output'  => mb_substr( (string) $content, 0, 4000 ),
				);
				continue;
			}

			if ( 'assistant' === $role ) {
				if ( is_string( $content ) && '' !== $content ) {
					$input[] = array(
						'role'    => 'assistant',
						'content' => array( array( 'type' => 'output_text', 'text' => $content ) ),
					);
				}
				// 历史工具调用：转成独立的 function_call 条目，call_id 与后续结果对应
				if ( ! empty( $m['tool_calls'] ) && is_array( $m['tool_calls'] ) ) {
					foreach ( $m['tool_calls'] as $tc ) {
						$args = isset( $tc['function']['arguments'] ) ? $tc['function']['arguments'] : '{}';
						$input[] = array(
							'type'      => 'function_call',
							'call_id'   => isset( $tc['id'] ) ? $tc['id'] : '',
							'name'      => isset( $tc['function']['name'] ) ? $tc['function']['name'] : '',
							'arguments' => is_string( $args ) ? $args : wp_json_encode( $args ),
						);
					}
				}
				continue;
			}

			$input[] = array(
				'role'    => 'user',
				'content' => array( array( 'type' => 'input_text', 'text' => is_string( $content ) ? $content : '' ) ),
			);
		}

		return array( $instructions, $input );
	}

	/** OpenAI Chat 工具定义 → Responses 扁平工具定义 */
	private function to_responses_tools( $tools ) {
		$out = array();
		foreach ( (array) $tools as $t ) {
			$f = isset( $t['function'] ) ? $t['function'] : $t;
			$out[] = array(
				'type'        => 'function',
				'name'        => isset( $f['name'] ) ? $f['name'] : '',
				'description' => isset( $f['description'] ) ? $f['description'] : '',
				'parameters'  => isset( $f['parameters'] ) ? $f['parameters'] : (object) array(),
			);
		}
		return $out;
	}

	private function responses_body( $messages, $tools, $temperature, $stream ) {
		list( $instructions, $input ) = $this->to_responses_input( $messages );

		$body = array(
			'model'             => $this->settings['model'],
			'input'             => $input,
			'max_output_tokens' => $this->max_tokens(),
		);
		if ( '' !== $instructions ) {
			$body['instructions'] = $instructions;
		}
		if ( null !== $temperature ) {
			$body['temperature'] = (float) $temperature;
		}
		$rtools = $this->to_responses_tools( $tools );
		if ( $rtools ) {
			$body['tools']       = $rtools;
			$body['tool_choice'] = 'auto';
		}
		if ( $stream ) {
			$body['stream'] = true;
		}
		return $body;
	}

	/**
	 * 解析 Responses 的 output 数组 → 统一的 content + tool_calls。
	 *
	 * @param array $output response.output 数组。
	 * @return array array{0: string, 1: array}
	 */
	private function parse_responses_output( $output ) {
		$content    = '';
		$tool_calls = array();

		foreach ( (array) $output as $item ) {
			$type = isset( $item['type'] ) ? $item['type'] : '';

			if ( 'function_call' === $type ) {
				$args = isset( $item['arguments'] ) ? $item['arguments'] : '{}';
				$tool_calls[] = array(
					// call_id 是回传结果时的关联键，缺失才退回 id
					'id'        => ! empty( $item['call_id'] ) ? $item['call_id'] : ( isset( $item['id'] ) ? $item['id'] : '' ),
					'name'      => isset( $item['name'] ) ? $item['name'] : '',
					'arguments' => ( is_string( $args ) && '' !== $args ) ? $args : '{}',
				);
				continue;
			}

			// 助手文本消息：content 里 output_text 块拼接
			if ( ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
				foreach ( $item['content'] as $block ) {
					$btype = isset( $block['type'] ) ? $block['type'] : '';
					if ( ( 'output_text' === $btype || 'text' === $btype ) && isset( $block['text'] ) ) {
						$content .= $block['text'];
					}
				}
			}
		}

		return array( $content, $tool_calls );
	}

	/** Responses usage（input_tokens/output_tokens）→ 统一 usage */
	private function responses_usage( $usage ) {
		return $this->extract_usage( array(
			'prompt_tokens'     => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
			'completion_tokens' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
		) );
	}

	private function responses_chat( $messages, $tools, $temperature = null ) {
		$body = $this->responses_body( $messages, $tools, $temperature, false );

		$resp = wp_remote_post( $this->responses_url(), array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->settings['api_key'],
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'bokeauto_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			// 与 Chat Completions 一致：角色覆盖模型失败 → 降级全局模型重试一次
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Bokeauto_LLM();
				$retry  = $global->chat( $messages, $tools, $temperature );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'bokeauto_api', $msg );
		}

		list( $content, $tool_calls ) = $this->parse_responses_output( isset( $data['output'] ) ? $data['output'] : array() );

		// 少数实现只给便捷字段 output_text
		if ( '' === $content && ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			$content = $data['output_text'];
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->responses_usage( isset( $data['usage'] ) ? $data['usage'] : array() ),
		);
	}

	/**
	 * Responses 流式：解析 SSE 语义事件。
	 *
	 * 事件名在不同实现间可能有出入，这里按后缀宽松匹配增量事件，
	 * 并在 response.completed 时用完整 output 覆盖工具调用结果做兜底，
	 * 因此即使某些增量事件未被识别，最终结果依然完整。
	 */
	private function responses_stream( $messages, $tools, $on_delta, $on_reasoning ) {
		$body = $this->responses_body( $messages, $tools, $this->settings['temperature'], true );

		$content     = '';
		$fn_items    = array(); // output_index => array{id,name,args}
		$usage       = array();
		$final_calls = null;    // response.completed 解析出的权威 tool_calls
		$final_text  = null;

		$ch = curl_init( $this->responses_url() );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $this->settings['api_key'],
			),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$fn_items, &$usage, &$final_calls, &$final_text, $on_delta, $on_reasoning ) {
				$lines = preg_split( "/\r?\n/", $chunk );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( 'event:' === substr( $line, 0, 6 ) ) {
						continue; // 事件名也在 data 的 type 字段里，无需单独处理
					}
					if ( 'data:' !== substr( $line, 0, 5 ) ) {
						continue;
					}
					$payload = trim( substr( $line, 5 ) );
					if ( '[DONE]' === $payload ) {
						continue;
					}
					$json = json_decode( $payload, true );
					if ( ! is_array( $json ) || empty( $json['type'] ) ) {
						continue;
					}
					$type = $json['type'];
					$idx  = isset( $json['output_index'] ) ? (int) $json['output_index'] : 0;

					// 新增输出条目：登记 function_call 的 call_id 与名称
					if ( 'response.output_item.added' === $type || 'response.output_item.done' === $type ) {
						$item = isset( $json['item'] ) ? $json['item'] : array();
						if ( isset( $item['type'] ) && 'function_call' === $item['type'] ) {
							if ( ! isset( $fn_items[ $idx ] ) ) {
								$fn_items[ $idx ] = array( 'id' => '', 'name' => '', 'args' => '' );
							}
							if ( ! empty( $item['call_id'] ) ) {
								$fn_items[ $idx ]['id'] = $item['call_id'];
							} elseif ( ! empty( $item['id'] ) && '' === $fn_items[ $idx ]['id'] ) {
								$fn_items[ $idx ]['id'] = $item['id'];
							}
							if ( ! empty( $item['name'] ) ) {
								$fn_items[ $idx ]['name'] = $item['name'];
							}
							// done 事件通常带完整 arguments，可直接采用
							if ( isset( $item['arguments'] ) && is_string( $item['arguments'] ) && '' !== $item['arguments'] ) {
								$fn_items[ $idx ]['args'] = $item['arguments'];
							}
						}
						continue;
					}

					// 工具参数增量
					if ( false !== strpos( $type, 'function_call_arguments' ) ) {
						if ( isset( $json['delta'] ) && is_string( $json['delta'] ) ) {
							if ( ! isset( $fn_items[ $idx ] ) ) {
								$fn_items[ $idx ] = array( 'id' => '', 'name' => '', 'args' => '' );
							}
							$fn_items[ $idx ]['args'] .= $json['delta'];
						} elseif ( isset( $json['arguments'] ) && is_string( $json['arguments'] ) && '' !== $json['arguments'] ) {
							if ( ! isset( $fn_items[ $idx ] ) ) {
								$fn_items[ $idx ] = array( 'id' => '', 'name' => '', 'args' => '' );
							}
							$fn_items[ $idx ]['args'] = $json['arguments'];
						}
						continue;
					}

					// 思考摘要增量
					if ( false !== strpos( $type, 'reasoning' ) && '.delta' === substr( $type, -6 ) ) {
						if ( is_callable( $on_reasoning ) && isset( $json['delta'] ) && is_string( $json['delta'] ) && '' !== $json['delta'] ) {
							$on_reasoning( $json['delta'] );
						}
						continue;
					}

					// 正文增量（response.output_text.delta 及等价实现）
					if ( '.delta' === substr( $type, -6 ) && isset( $json['delta'] ) && is_string( $json['delta'] ) && '' !== $json['delta'] ) {
						$content .= $json['delta'];
						if ( is_callable( $on_delta ) ) {
							$on_delta( $json['delta'] );
						}
						continue;
					}

					// 收尾事件：完整响应体（用于兜底校正与 usage）
					if ( 'response.completed' === $type || 'response.incomplete' === $type || 'response.failed' === $type ) {
						$r = isset( $json['response'] ) ? $json['response'] : array();
						if ( ! empty( $r['usage'] ) ) {
							$usage = $r['usage'];
						}
						if ( ! empty( $r['output'] ) && is_array( $r['output'] ) ) {
							list( $ftext, $fcalls ) = $this->parse_responses_output( $r['output'] );
							$final_calls = $fcalls;
							$final_text  = $ftext;
						}
					}
				}
				return strlen( $chunk );
			},
		) );

		curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( '' !== $err ) {
			return new WP_Error( 'bokeauto_http', $err );
		}
		if ( $code >= 400 ) {
			if ( $this->using_override ) {
				$this->rejected_override_model = $this->settings['model'];
				$global = new Bokeauto_LLM();
				$retry  = $global->stream_chat( $messages, $tools, $on_delta, $on_reasoning );
				if ( ! is_wp_error( $retry ) ) {
					return $retry;
				}
			}
			return new WP_Error( 'bokeauto_api', '模型服务返回错误（HTTP ' . $code . '），请稍后重试或更换模型' );
		}

		// 工具调用以收尾事件的完整 output 为准，增量结果仅作为回退
		$tool_calls = array();
		if ( is_array( $final_calls ) && $final_calls ) {
			$tool_calls = $final_calls;
		} else {
			ksort( $fn_items );
			foreach ( $fn_items as $it ) {
				if ( '' === $it['name'] ) {
					continue;
				}
				$tool_calls[] = array(
					'id'        => $it['id'],
					'name'      => $it['name'],
					'arguments' => '' === $it['args'] ? '{}' : $it['args'],
				);
			}
		}

		// 增量事件未识别时，用收尾事件里的完整文本兜底
		if ( '' === $content && is_string( $final_text ) && '' !== $final_text ) {
			$content = $final_text;
			if ( is_callable( $on_delta ) ) {
				$on_delta( $content );
			}
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->responses_usage( $usage ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Claude（Anthropic）原生适配：API 格式与 OpenAI 不同，需单独转换
	 * ------------------------------------------------------------------- */

	private function claude_url() {
		$base = rtrim( $this->settings['base_url'], '/' );
		if ( substr( $base, -3 ) === '/v1' ) {
			$base = substr( $base, 0, -3 );
		}
		return $base . '/v1/messages';
	}

	private function claude_headers() {
		return array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->settings['api_key'],
			'anthropic-version' => '2023-06-01',
		);
	}

	/** OpenAI 消息 → Claude 消息（system 提出，tool 结果合并到上一条 assistant） */
	private function to_claude_messages( $messages ) {
		$system = '';
		$out    = array();
		foreach ( (array) $messages as $m ) {
			$role = isset( $m['role'] ) ? $m['role'] : 'user';
			if ( 'system' === $role ) {
				$system .= ( $system ? "\n" : '' ) . ( is_string( $m['content'] ) ? $m['content'] : '' );
				continue;
			}
			if ( 'tool' === $role ) {
				$block = array(
					'type'        => 'tool_result',
					'tool_use_id' => isset( $m['tool_call_id'] ) ? $m['tool_call_id'] : '',
					'content'     => mb_substr( (string) $m['content'], 0, 4000 ),
				);
				$last = count( $out ) - 1;
				if ( $last >= 0 && 'assistant' === $out[ $last ]['role'] && isset( $out[ $last ]['content'] ) && is_array( $out[ $last ]['content'] ) ) {
					$out[ $last ]['content'][] = $block;
				} else {
					$out[] = array( 'role' => 'user', 'content' => array( $block ) );
				}
				continue;
			}
			if ( 'assistant' === $role ) {
				$content = array();
				if ( isset( $m['content'] ) && '' !== $m['content'] ) {
					$content[] = array( 'type' => 'text', 'text' => $m['content'] );
				}
				if ( ! empty( $m['tool_calls'] ) ) {
					foreach ( $m['tool_calls'] as $tc ) {
						$input = json_decode( $tc['function']['arguments'], true );
						// Claude 要求 input 为对象；空参数用空对象
						if ( ! is_array( $input ) || empty( $input ) ) {
							$input = new stdClass();
						}
						$content[] = array(
							'type'  => 'tool_use',
							'id'    => $tc['id'],
							'name'  => $tc['function']['name'],
							'input' => $input,
						);
					}
				}
				if ( ! $content ) {
					$content[] = array( 'type' => 'text', 'text' => '' );
				}
				$out[] = array( 'role' => 'assistant', 'content' => $content );
				continue;
			}
			$out[] = array( 'role' => 'user', 'content' => is_string( $m['content'] ) ? $m['content'] : '' );
		}
		return array( $system, $out );
	}

	private function to_claude_tools( $tools ) {
		$out = array();
		foreach ( (array) $tools as $t ) {
			$f = isset( $t['function'] ) ? $t['function'] : array();
			$out[] = array(
				'name'         => isset( $f['name'] ) ? $f['name'] : '',
				'description'  => isset( $f['description'] ) ? $f['description'] : '',
				'input_schema' => isset( $f['parameters'] ) ? $f['parameters'] : (object) array(),
			);
		}
		return $out;
	}

	private function parse_claude_response( $data ) {
		$content    = '';
		$tool_calls = array();
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( 'text' === $block['type'] ) {
					$content .= $block['text'];
				} elseif ( 'tool_use' === $block['type'] ) {
					$tool_calls[] = array(
						'id'        => isset( $block['id'] ) ? $block['id'] : '',
						'name'      => isset( $block['name'] ) ? $block['name'] : '',
						'arguments' => wp_json_encode( isset( $block['input'] ) ? $block['input'] : new stdClass(), JSON_UNESCAPED_UNICODE ),
					);
				}
			}
		}
		$usage = isset( $data['usage'] ) ? $data['usage'] : array();
		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
				'completion_tokens' => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
			) ),
		);
	}

	private function claude_chat( $messages, $tools, $temperature = null ) {
		list( $system, $conv ) = $this->to_claude_messages( $messages );

		$body = array(
			'model'      => $this->settings['model'],
			'messages'   => $conv,
			'max_tokens' => $this->max_tokens(),
		);
		if ( $system ) {
			$body['system'] = $system;
		}
		if ( null !== $temperature ) {
			$body['temperature'] = (float) $temperature;
		}
		$claude_tools = $this->to_claude_tools( $tools );
		if ( $claude_tools ) {
			$body['tools'] = $claude_tools;
		}

		$resp = wp_remote_post( $this->claude_url(), array(
			'timeout' => 120,
			'headers' => $this->claude_headers(),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'bokeauto_http', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'bokeauto_api', $msg );
		}
		return $this->parse_claude_response( $data );
	}

	/** Claude 流式：解析 Anthropic SSE（text_delta / input_json_delta） */
	private function claude_stream( $messages, $tools, $on_delta, $on_reasoning ) {
		list( $system, $conv ) = $this->to_claude_messages( $messages );

		$body = array(
			'model'      => $this->settings['model'],
			'messages'   => $conv,
			'max_tokens' => $this->max_tokens(),
			'temperature' => (float) $this->settings['temperature'],
			'stream'     => true,
		);
		if ( $system ) {
			$body['system'] = $system;
		}
		$claude_tools = $this->to_claude_tools( $tools );
		if ( $claude_tools ) {
			$body['tools'] = $claude_tools;
		}

		$content = '';
		$tool_blocks = array(); // index → {id,name,args}
		$usage = array();

		$ch = curl_init( $this->claude_url() );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 150,
			CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'x-api-key: ' . $this->settings['api_key'],
				'anthropic-version: 2023-06-01',
			),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$tool_blocks, &$usage, $on_delta, $on_reasoning ) {
				$lines = preg_split( "/\r?\n/", $chunk );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( 'event:' === substr( $line, 0, 6 ) ) {
						continue; // event 行，无需处理
					}
					if ( 'data:' !== substr( $line, 0, 5 ) ) {
						continue;
					}
					$json = json_decode( trim( substr( $line, 5 ) ), true );
					if ( ! is_array( $json ) ) {
						continue;
					}
					// message_start / message_delta 携带 usage
					if ( ! empty( $json['message']['usage'] ) ) {
						$usage = $json['message']['usage'];
					}
					if ( ! empty( $json['usage'] ) ) {
						$usage = $json['usage'];
					}
					if ( 'content_block_start' === $json['type'] && ! empty( $json['content_block'] ) ) {
						$cb = $json['content_block'];
						if ( 'tool_use' === $cb['type'] ) {
							$tool_blocks[ (int) $json['index'] ] = array(
								'id'   => isset( $cb['id'] ) ? $cb['id'] : '',
								'name' => isset( $cb['name'] ) ? $cb['name'] : '',
								'args' => '',
							);
						}
						continue;
					}
					if ( 'content_block_delta' === $json['type'] && ! empty( $json['delta'] ) ) {
						$d = $json['delta'];
						if ( 'text_delta' === $d['type'] && isset( $d['text'] ) ) {
							$content .= $d['text'];
							if ( is_callable( $on_delta ) ) {
								$on_delta( $d['text'] );
							}
						} elseif ( 'input_json_delta' === $d['type'] && isset( $d['partial_json'] ) ) {
							$idx = (int) $json['index'];
							if ( isset( $tool_blocks[ $idx ] ) ) {
								$tool_blocks[ $idx ]['args'] .= $d['partial_json'];
							}
						} elseif ( 'thinking_delta' === $d['type'] && isset( $d['thinking'] ) && is_callable( $on_reasoning ) ) {
							$on_reasoning( $d['thinking'] );
						}
					}
				}
				return strlen( $chunk );
			},
		) );

		curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( '' !== $err ) {
			return new WP_Error( 'bokeauto_http', $err );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'bokeauto_api', 'Claude 服务返回错误（HTTP ' . $code . '）' );
		}

		$tool_calls = array();
		foreach ( $tool_blocks as $tb ) {
			$tool_calls[] = array(
				'id'        => $tb['id'],
				'name'      => $tb['name'],
				'arguments' => '' === $tb['args'] ? '{}' : $tb['args'],
			);
		}

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
				'completion_tokens' => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
			) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Embeddings：文本 → 向量
	 *
	 * 嵌入服务完全独立于对话模型：走自己的地址与 Key，模型固定为
	 * Bokeauto_Settings::EMBEDDING_MODEL（BAAI/bge-m3）。因此对话用任意
	 * 服务商（含无嵌入接口的 DeepSeek、协议不同的 Anthropic）都不影响记忆检索。
	 *
	 * 返回 array( int index => array( float... ) )，失败返回 WP_Error
	 *
	 * @param array $texts     待向量化文本
	 * @param bool  $use_cache 是否走单条向量缓存。连通性测试必须传 false，
	 *                         否则换了 Key 也会命中上次的缓存而报出假「可用」。
	 * ------------------------------------------------------------------- */

	public function embed( $texts, $use_cache = true ) {
		$settings = $this->settings;

		if ( $this->is_mock() ) {
			return new WP_Error( 'bokeauto_no_embed', '演示模式不支持向量嵌入，记忆将使用关键词检索' );
		}
		if ( '' === trim( (string) $settings['embedding_api_key'] ) ) {
			return new WP_Error( 'bokeauto_no_embed', '尚未配置嵌入 API Key，记忆将使用关键词检索' );
		}

		// 嵌入接口熔断：地址或 Key 有问题时，60 分钟内直接快速降级，
		// 不再每次对话都白调一次失败请求
		if ( get_transient( 'bokeauto_embed_down' ) ) {
			return new WP_Error( 'bokeauto_no_embed', '嵌入服务暂不可用（已临时停用，记忆使用关键词检索）' );
		}

		$texts = array_map( 'strval', (array) $texts );
		if ( ! $texts ) {
			return array();
		}

		// 单条查询向量缓存（1 小时），避免重复问题重复调用嵌入 API
		$cache_key = null;
		if ( $use_cache && 1 === count( $texts ) ) {
			$cache_key = 'bokeauto_embed_' . md5( $texts[0] );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return array( 0 => $cached );
			}
		}

		$resp = wp_remote_post(
			$this->endpoint( 'embeddings', $settings['embedding_base_url'] ),
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['embedding_api_key'],
				),
				'body'    => wp_json_encode( array(
					'model' => Bokeauto_Settings::EMBEDDING_MODEL,
					'input' => $texts,
				) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			set_transient( 'bokeauto_embed_down', 1, HOUR_IN_SECONDS );
			return new WP_Error( 'bokeauto_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || empty( $data['data'] ) ) {
			// 4xx/5xx（Key 无效、地址写错、额度耗尽等）→ 熔断 1 小时，避免每次对话白调
			if ( $code >= 400 ) {
				set_transient( 'bokeauto_embed_down', 1, HOUR_IN_SECONDS );
			}
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'bokeauto_api', $msg );
		}

		$out = array();
		foreach ( $data['data'] as $item ) {
			if ( isset( $item['index'], $item['embedding'] ) ) {
				$out[ (int) $item['index'] ] = $item['embedding'];
			}
		}
		ksort( $out );

		if ( $cache_key && isset( $out[0] ) ) {
			set_transient( $cache_key, $out[0], HOUR_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * 测试嵌入服务连通性。
	 *
	 * 与对话模型的测试完全分开：只验证嵌入地址与 Key 能否取回向量。
	 * 会先清掉熔断标记，避免上一次失败导致这次测试被跳过而误报。
	 *
	 * @param string $base_url 留空则用已保存配置
	 * @param string $api_key  留空则用已保存配置
	 * @return array{ ok: bool, message: string, dim: int }
	 */
	public function test_embedding( $base_url = '', $api_key = '' ) {
		$saved    = Bokeauto_Settings::get();
		$base_url = '' !== trim( (string) $base_url ) ? $base_url : $saved['embedding_base_url'];
		$api_key  = '' !== trim( (string) $api_key ) ? $api_key : $saved['embedding_api_key'];

		if ( '' === trim( (string) $api_key ) ) {
			return array(
				'ok'      => false,
				'message' => '尚未填写嵌入 API Key。留空时记忆将使用关键词检索，功能可用但没有同义召回。',
				'dim'     => 0,
			);
		}

		// 临时用传入的配置覆盖，复用 embed() 的完整请求逻辑
		$origin_base = $this->settings['embedding_base_url'];
		$origin_key  = $this->settings['embedding_api_key'];
		$origin_mock = $this->settings['mock_mode'];

		$this->settings['embedding_base_url'] = $base_url;
		$this->settings['embedding_api_key']  = $api_key;
		$this->settings['mock_mode']          = 0; // 演示模式下也允许测试真实嵌入服务

		delete_transient( 'bokeauto_embed_down' );
		$res = $this->embed( array( '波克wpAI 嵌入连通性测试' ), false );

		$this->settings['embedding_base_url'] = $origin_base;
		$this->settings['embedding_api_key']  = $origin_key;
		$this->settings['mock_mode']          = $origin_mock;

		if ( is_wp_error( $res ) ) {
			// 测试失败不该留下熔断标记影响后续正常调用的重试机会
			delete_transient( 'bokeauto_embed_down' );
			return array( 'ok' => false, 'message' => '嵌入服务连接失败：' . $res->get_error_message(), 'dim' => 0 );
		}

		$dim = isset( $res[0] ) && is_array( $res[0] ) ? count( $res[0] ) : 0;
		if ( $dim < 1 ) {
			return array( 'ok' => false, 'message' => '嵌入服务返回了空向量，请检查模型是否可用', 'dim' => 0 );
		}

		return array(
			'ok'      => true,
			'message' => '嵌入服务可用（' . Bokeauto_Settings::EMBEDDING_MODEL . '，向量维度 ' . $dim . '）',
			'dim'     => $dim,
		);
	}

	/* ---------------------------------------------------------------------
	 * 测试连接
	 * ------------------------------------------------------------------- */

	public function test_connection( $provider = '', $base_url = '', $api_key = '', $model = '', $protocol = '' ) {
		if ( 'mock' === $provider ) {
			return true;
		}
		if ( '' === $api_key || '' === $base_url ) {
			return false;
		}

		$proto = Bokeauto_Settings::resolve_protocol( $provider, $base_url, $protocol );
		$base  = rtrim( $base_url, '/' );

		// Anthropic Messages
		if ( 'anthropic' === $proto ) {
			if ( substr( $base, -3 ) === '/v1' ) {
				$base = substr( $base, 0, -3 );
			}
			$resp = wp_remote_post(
				$base . '/v1/messages',
				array(
					'timeout' => 30,
					'headers' => array(
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					),
					'body' => wp_json_encode( array(
						'model'      => $model,
						'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
						'max_tokens' => 5,
					) ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return false;
			}
			return (int) wp_remote_retrieve_response_code( $resp ) < 400;
		}

		// OpenAI Responses
		if ( 'openai-responses' === $proto ) {
			$endpoint = ( substr( $base, -10 ) === '/responses' ) ? $base : $base . '/responses';
			$resp = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 30,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body' => wp_json_encode( array(
						'model'             => $model,
						'input'             => 'ping',
						'max_output_tokens' => 16,
					) ),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return false;
			}
			return (int) wp_remote_retrieve_response_code( $resp ) < 400;
		}

		// OpenAI Chat Completions：已含完整端点则直接用，否则补上（绝不重复拼接）
		$endpoint = ( substr( $base, -17 ) === '/chat/completions' ) ? $base : $base . '/chat/completions';

		$resp = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body' => wp_json_encode( array(
					'model'       => $model,
					'messages'    => array( array( 'role' => 'user', 'content' => 'ping' ) ),
					'max_tokens'  => 5,
				) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code < 400;
	}

	/* ---------------------------------------------------------------------
	 * 拉取服务商可用模型列表（设置页「获取模型」用）
	 * 返回 array of string，失败返回 WP_Error
	 * ------------------------------------------------------------------- */

	public static function fetch_models( $provider = '', $base_url = '', $api_key = '', $protocol = '' ) {
		if ( 'mock' === $provider ) {
			return array( 'mock' );
		}
		if ( '' === $api_key || '' === $base_url ) {
			return new WP_Error( 'bokeauto_no_key', '请先填写 API 地址与 API Key' );
		}

		$proto = Bokeauto_Settings::resolve_protocol( $provider, $base_url, $protocol );
		$base  = rtrim( $base_url, '/' );

		if ( 'anthropic' === $proto ) {
			if ( substr( $base, -3 ) === '/v1' ) {
				$base = substr( $base, 0, -3 );
			}
			$url  = $base . '/v1/models?limit=100';
			$args = array(
				'timeout' => 30,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
			);
		} else {
			// Chat Completions 与 Responses 共用 /models 列表接口；
			// 地址填到具体端点时先剥掉端点段，避免拼出 /chat/completions/models
			foreach ( array( '/chat/completions', '/responses' ) as $suffix ) {
				if ( substr( $base, -strlen( $suffix ) ) === $suffix ) {
					$base = substr( $base, 0, -strlen( $suffix ) );
					break;
				}
			}
			$url  = rtrim( $base, '/' ) . '/models';
			$args = array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			);
		}

		$resp = wp_remote_get( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'bokeauto_http', $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'bokeauto_api', $msg );
		}

		// OpenAI: {data:[{id:...}]}；Anthropic: {data:[{id:...}]}，字段一致
		$list = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$out  = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$out[] = (string) $item['id'];
			} elseif ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}

		if ( ! $out ) {
			return new WP_Error( 'bokeauto_api', '该服务商未返回模型列表，请手动填写模型名' );
		}

		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 演示模式：模拟模型行为（无 Key 时验证全链路）
	 * 根据用户消息关键词触发演示工具调用，以便验证 Agent 循环
	 * ------------------------------------------------------------------- */

	private function mock_chat( $messages ) {
		$last_user = '';
		$total_in  = 0;
		foreach ( $messages as $m ) {
			$len = is_string( $m['content'] ) ? mb_strlen( $m['content'] ) : 0;
			$total_in += $len;
			if ( 'user' === $m['role'] && is_string( $m['content'] ) ) {
				$last_user = $m['content'];
			}
		}
		$est_prompt = (int) ceil( $total_in / 2 );

		// 如果上一条是工具结果，则直接总结
		$last = end( $messages );
		if ( $last && 'tool' === $last['role'] ) {
			$reply = '已为你完成操作。演示模式返回：' . substr( $last['content'], 0, 200 ) . '（配置真实模型 API Key 后可获得完整能力）';
			return array(
				'content'    => $reply,
				'tool_calls' => array(),
				'usage'      => $this->extract_usage( array(
					'prompt_tokens'     => $est_prompt,
					'completion_tokens' => (int) ceil( mb_strlen( $reply ) / 2 ),
				) ),
			);
		}

		$tool_call = $this->mock_match( $last_user );

		if ( $tool_call ) {
			return array(
				'content'    => '',
				'tool_calls' => array( $tool_call ),
				'usage'      => $this->extract_usage( array(
					'prompt_tokens'     => $est_prompt,
					'completion_tokens' => 8,
				) ),
			);
		}

		$reply = $this->mock_reply( $last_user );
		return array(
			'content'    => $reply,
			'tool_calls' => array(),
			'usage'      => $this->extract_usage( array(
				'prompt_tokens'     => $est_prompt,
				'completion_tokens' => (int) ceil( mb_strlen( $reply ) / 2 ),
			) ),
		);
	}

	private function mock_match( $text ) {
		$t = mb_strtolower( $text );

		$has_create = ( false !== mb_strpos( $t, '发布' ) || false !== mb_strpos( $t, '创建' ) || false !== mb_strpos( $t, '写一' ) || false !== mb_strpos( $t, '写篇' ) );
		$has_list   = ( false !== mb_strpos( $t, '列表' ) || false !== mb_strpos( $t, '查看' ) || false !== mb_strpos( $t, '查询' ) || false !== mb_strpos( $t, '有什么' ) );
		$has_delete = ( false !== mb_strpos( $t, '删除' ) );
		$has_post   = ( false !== mb_strpos( $t, '文章' ) || false !== mb_strpos( $t, 'post' ) );
		$has_page   = ( false !== mb_strpos( $t, '页面' ) );

		if ( $has_create && $has_post ) {
			return array(
				'id'        => 'call_mock_1',
				'name'      => 'create_post',
				'arguments' => wp_json_encode( array(
					'title'   => '演示文章：波克wpAI生成的标题',
					'content' => '这是一篇由波克wpAI在演示模式下生成的文章内容，用于验证工具调用链路是否正常。',
					'status'  => 'draft',
				) ),
			);
		}
		if ( $has_create && $has_page ) {
			return array(
				'id'        => 'call_mock_2',
				'name'      => 'create_page',
				'arguments' => wp_json_encode( array( 'title' => '关于我们（演示）', 'content' => '示例页面内容' ) ),
			);
		}
		if ( $has_list ) {
			return array(
				'id'        => 'call_mock_3',
				'name'      => 'list_posts',
				'arguments' => wp_json_encode( array( 'number' => 5 ) ),
			);
		}
		if ( $has_delete ) {
			return array(
				'id'        => 'call_mock_4',
				'name'      => 'delete_post',
				'arguments' => wp_json_encode( array( 'post_id' => 1 ) ),
			);
		}
		return null;
	}

	private function mock_reply( $text ) {
		$t = mb_strtolower( $text );
		if ( false !== mb_strpos( $t, '你好' ) || false !== mb_strpos( $t, 'hello' ) ) {
			return '你好，我是波克wpAI，内置在这个 WordPress 站点的 AI 智能体。你可以让我发布文章、管理插件主题、编辑文件代码、维护网站等。试试对我说「帮我发布一篇测试文章」或「查看最近的文章列表」。';
		}
		if ( false !== mb_strpos( $t, '能力' ) || false !== mb_strpos( $t, '会什么' ) || false !== mb_strpos( $t, '帮助' ) ) {
			return '我可以：1) 发布/编辑/删除文章与页面；2) 管理主题与插件（安装、启用、改代码）；3) 读写站点文件；4) 系统设置、备份、日志分析。配置模型 API Key 后即可获得完整智能体能力。';
		}
		return '收到你的消息。演示模式会自动识别一些关键词（如「发布文章」「查看列表」）来演示工具调用。建议前往「模型设置」配置真实的模型 API Key，解锁完整能力。';
	}
}
