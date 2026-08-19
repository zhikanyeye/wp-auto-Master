<?php
/**
 * 采集工具组：网页内容采集与自动发布
 *
 * 面向 AI 智能体的采集能力封装。规则由模型按目标站点结构生成，
 * 引擎实现见 class-collector.php。
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

class Bokeauto_Tools_Collect {

	/** 规则表名 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bokeauto_collect_rules';
	}

	/* ---------------------------------------------------------------------
	 * 探测与提取（只读）
	 * ------------------------------------------------------------------- */

	/** 提取列表页中的文章链接 */
	public static function collect_links( $args ) {
		$url = esc_url_raw( isset( $args['url'] ) ? $args['url'] : '' );
		$fetched = Bokeauto_Collector::fetch( $url, array(
			'cookie' => isset( $args['cookie'] ) ? $args['cookie'] : '',
		) );
		if ( ! $fetched['ok'] ) {
			return array( 'ok' => false, 'message' => $fetched['message'] );
		}

		$selector = isset( $args['link_selector'] ) ? (string) $args['link_selector'] : 'a';
		$limit    = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 30;
		$links    = Bokeauto_Collector::extract_links( $fetched['html'], $url, $selector, $limit );

		if ( ! $links ) {
			return array(
				'ok'      => false,
				'message' => '选择器「' . $selector . '」没有匹配到链接。可先用 collect_inspect 查看页面结构，再调整选择器',
			);
		}

		$items = array();
		foreach ( $links as $link ) {
			$items[] = array(
				'链接'   => $link['url'],
				'标题'   => $link['text'],
				'已采集' => Bokeauto_Collector::find_post_by_source( $link['url'] ) ? '是' : '否',
			);
		}

		return array(
			'ok'      => true,
			'message' => '共提取到 ' . count( $items ) . ' 条链接',
			'data'    => array( 'count' => count( $items ), 'items' => $items ),
		);
	}

	/** 探测页面结构：列出候选容器与其 class/id，辅助模型写选择器 */
	public static function collect_inspect( $args ) {
		$url     = esc_url_raw( isset( $args['url'] ) ? $args['url'] : '' );
		$fetched = Bokeauto_Collector::fetch( $url, array(
			'cookie' => isset( $args['cookie'] ) ? $args['cookie'] : '',
		) );
		if ( ! $fetched['ok'] ) {
			return array( 'ok' => false, 'message' => $fetched['message'] );
		}

		$dom = Bokeauto_Collector::dom( $fetched['html'] );
		if ( ! $dom ) {
			return array( 'ok' => false, 'message' => '页面解析失败（主机可能缺少 DOM 扩展）' );
		}

		$selector = isset( $args['selector'] ) ? trim( (string) $args['selector'] ) : '';
		if ( '' !== $selector ) {
			$nodes = Bokeauto_Collector::query( $dom, $selector );
			$preview = array();
			foreach ( array_slice( $nodes, 0, 5 ) as $node ) {
				$preview[] = array(
					'标签'     => $node->nodeName,
					'文本预览' => Bokeauto_Collector::squeeze( $node->textContent, 200 ),
				);
			}
			return array(
				'ok'      => true,
				'message' => '选择器「' . $selector . '」匹配到 ' . count( $nodes ) . ' 个节点',
				'data'    => array( 'count' => count( $nodes ), 'items' => $preview ),
			);
		}

		// 未指定选择器：给出正文候选与链接密集容器
		$xpath      = new DOMXPath( $dom );
		$candidates = array();
		foreach ( $xpath->query( '//article|//main|//div[@class or @id]' ) as $node ) {
			$text = Bokeauto_Collector::squeeze( $node->textContent, 0 );
			$len  = mb_strlen( $text );
			if ( $len < 200 ) {
				continue;
			}
			$links = $xpath->query( './/a', $node )->length;
			$sel   = strtolower( $node->nodeName );
			if ( $node->getAttribute( 'id' ) ) {
				$sel .= '#' . $node->getAttribute( 'id' );
			} elseif ( $node->getAttribute( 'class' ) ) {
				$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
				$sel    .= '.' . $classes[0];
			}
			$candidates[] = array(
				'选择器'   => $sel,
				'文本长度' => $len,
				'链接数'   => $links,
				'文本预览' => mb_substr( $text, 0, 120 ),
			);
		}
		// 文本最长的优先，取前 12 个
		usort( $candidates, function ( $a, $b ) {
			return $b['文本长度'] - $a['文本长度'];
		} );
		$candidates = array_slice( $candidates, 0, 12 );

		$title_node = $xpath->query( '//h1' )->item( 0 );

		return array(
			'ok'      => true,
			'message' => '已探测页面结构，据此编写选择器：链接密集的容器适合做列表规则，文本长且链接少的容器适合做正文规则',
			'data'    => array(
				'页面标题'   => $title_node ? Bokeauto_Collector::squeeze( $title_node->textContent, 200 ) : '',
				'候选容器'   => $candidates,
			),
		);
	}

	/** 按规则提取详情页字段（不入库，用于验证规则） */
	public static function collect_article( $args ) {
		$url   = esc_url_raw( isset( $args['url'] ) ? $args['url'] : '' );
		$rules = self::normalize_rules( isset( $args['rules'] ) ? $args['rules'] : array() );
		if ( ! $rules ) {
			return array( 'ok' => false, 'message' => 'rules 不能为空，至少提供 title 与 content 的选择器' );
		}

		$fetched = Bokeauto_Collector::fetch( $url, array(
			'cookie' => isset( $args['cookie'] ) ? $args['cookie'] : '',
		) );
		if ( ! $fetched['ok'] ) {
			return array( 'ok' => false, 'message' => $fetched['message'] );
		}

		$fields = Bokeauto_Collector::extract_fields( $fetched['html'], $url, $rules );
		$empty  = array();
		foreach ( $rules as $field => $rule ) {
			if ( '' === trim( (string) ( isset( $fields[ $field ] ) ? $fields[ $field ] : '' ) ) ) {
				$empty[] = $field;
			}
		}

		$data = array( 'url' => $url );
		foreach ( $fields as $field => $value ) {
			$data[ $field ] = ( 'content' === $field ) ? mb_substr( (string) $value, 0, 2000 ) : $value;
		}
		if ( isset( $fields['content'] ) ) {
			$data['content_length'] = mb_strlen( (string) $fields['content'] );
		}

		return array(
			'ok'      => empty( $empty ),
			'message' => $empty
				? '以下字段未匹配到内容：' . implode( '、', $empty ) . '。请用 collect_inspect 核对选择器'
				: '字段提取成功，可用 collect_to_post 入库',
			'data'    => $data,
		);
	}

	/* ---------------------------------------------------------------------
	 * 采集入库
	 * ------------------------------------------------------------------- */

	/** 采集单个 URL 并创建文章 */
	public static function collect_to_post( $args ) {
		$url   = esc_url_raw( isset( $args['url'] ) ? $args['url'] : '' );
		$rules = self::normalize_rules( isset( $args['rules'] ) ? $args['rules'] : array() );
		if ( ! $rules ) {
			return array( 'ok' => false, 'message' => 'rules 不能为空，至少提供 title 与 content 的选择器' );
		}

		$existing = Bokeauto_Collector::find_post_by_source( $url );
		if ( $existing && empty( $args['allow_duplicate'] ) ) {
			return array(
				'ok'      => false,
				'message' => '该来源已采集过（文章 ID ' . $existing . '），已跳过。需要重复采集请传 allow_duplicate=true',
				'data'    => array( 'post_id' => $existing ),
			);
		}

		$result = self::collect_single( $url, $rules, self::normalize_options( $args ) );
		if ( ! $result['ok'] ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'message' => $result['message'],
			'data'    => $result['data'],
		);
	}

	/** 把远程图片下载进媒体库并替换某篇已有文章的正文图片地址 */
	public static function collect_localize_images( $args ) {
		$post_id = (int) ( isset( $args['post_id'] ) ? $args['post_id'] : 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array( 'ok' => false, 'message' => '文章不存在：' . $post_id );
		}

		$base   = (string) get_post_meta( $post_id, Bokeauto_Collector::META_SOURCE, true );
		$limit  = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$result = Bokeauto_Collector::localize_images( $post->post_content, $base, $post_id, $limit );

		if ( 0 === $result['downloaded'] ) {
			return array(
				'ok'      => false,
				'message' => 0 === $result['failed'] ? '正文中没有需要本地化的远程图片' : '图片下载全部失败（共 ' . $result['failed'] . ' 张），可能是目标站点限制了外链访问',
			);
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['html'] ) );
		$thumb = '';
		if ( ! empty( $args['set_thumbnail'] ) && $result['first_id'] && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $result['first_id'] );
			$thumb = '，首图已设为特色图片';
		}

		return array(
			'ok'      => true,
			'message' => '已本地化 ' . $result['downloaded'] . ' 张图片' . ( $result['failed'] ? '（失败 ' . $result['failed'] . ' 张）' : '' ) . $thumb,
			'data'    => array( 'post_id' => $post_id, 'downloaded' => $result['downloaded'], 'failed' => $result['failed'], '链接' => get_permalink( $post_id ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * 采集规则管理
	 * ------------------------------------------------------------------- */

	/** 保存（新建或更新）采集规则 */
	public static function collect_rule_save( $args ) {
		global $wpdb;

		$name = sanitize_text_field( isset( $args['name'] ) ? $args['name'] : '' );
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => '规则名称不能为空' );
		}
		$rules = self::normalize_rules( isset( $args['rules'] ) ? $args['rules'] : array() );
		if ( ! $rules ) {
			return array( 'ok' => false, 'message' => 'rules 不能为空，至少提供 title 与 content 的选择器' );
		}

		$data = array(
			'name'          => $name,
			'list_url'      => esc_url_raw( isset( $args['list_url'] ) ? $args['list_url'] : '' ),
			'link_selector' => sanitize_text_field( isset( $args['link_selector'] ) ? $args['link_selector'] : 'a' ),
			'article_rules' => wp_json_encode( $rules ),
			'options'       => wp_json_encode( self::normalize_options( $args ) ),
			'status'        => 'active',
			'updated_at'    => current_time( 'mysql' ),
		);

		$rule_id = (int) ( isset( $args['rule_id'] ) ? $args['rule_id'] : 0 );
		if ( $rule_id ) {
			$updated = $wpdb->update( self::table(), $data, array( 'id' => $rule_id ) );
			if ( false === $updated ) {
				return array( 'ok' => false, 'message' => '规则更新失败：' . $wpdb->last_error );
			}
			return array( 'ok' => true, 'message' => '采集规则「' . $name . '」已更新', 'data' => array( 'rule_id' => $rule_id ) );
		}

		$data['created_at'] = current_time( 'mysql' );
		$inserted = $wpdb->insert( self::table(), $data );
		if ( ! $inserted ) {
			return array( 'ok' => false, 'message' => '规则保存失败：' . $wpdb->last_error );
		}

		return array(
			'ok'      => true,
			'message' => '采集规则「' . $name . '」已保存，可用 collect_run_rule 执行，或用 schedule_create 配合本规则做定时采集',
			'data'    => array( 'rule_id' => (int) $wpdb->insert_id ),
		);
	}

	/** 列出采集规则 */
	public static function collect_rule_list( $args ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			return array( 'ok' => true, 'message' => '还没有采集规则，可用 collect_rule_save 创建', 'data' => array( 'items' => array() ) );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$options = json_decode( (string) $row->options, true );
			$items[] = array(
				'规则ID'   => (int) $row->id,
				'名称'     => $row->name,
				'列表页'   => $row->list_url,
				'链接选择器' => $row->link_selector,
				'字段'     => implode( '、', array_keys( (array) json_decode( (string) $row->article_rules, true ) ) ),
				'发布状态' => isset( $options['post_status'] ) ? $options['post_status'] : 'draft',
				'已采集'   => (int) $row->collected_count,
				'上次执行' => $row->last_run_at ? $row->last_run_at : '未执行',
				'状态'     => $row->status,
			);
		}

		return array( 'ok' => true, 'message' => '共 ' . count( $items ) . ' 条采集规则', 'data' => array( 'items' => $items ) );
	}

	/** 删除采集规则 */
	public static function collect_rule_delete( $args ) {
		global $wpdb;
		$rule_id = (int) ( isset( $args['rule_id'] ) ? $args['rule_id'] : 0 );
		$rule    = self::get_rule( $rule_id );
		if ( ! $rule ) {
			return array( 'ok' => false, 'message' => '规则不存在：' . $rule_id );
		}
		$wpdb->delete( self::table(), array( 'id' => $rule_id ) );
		return array( 'ok' => true, 'message' => '采集规则「' . $rule->name . '」已删除（已采集的文章不受影响）' );
	}

	/** 按规则执行一轮采集 */
	public static function collect_run_rule( $args ) {
		global $wpdb;

		$rule_id = (int) ( isset( $args['rule_id'] ) ? $args['rule_id'] : 0 );
		$rule    = self::get_rule( $rule_id );
		if ( ! $rule ) {
			return array( 'ok' => false, 'message' => '规则不存在：' . $rule_id . '。请先用 collect_rule_list 查看规则 ID' );
		}

		$options = json_decode( (string) $rule->options, true );
		$options = is_array( $options ) ? $options : array();
		$rules   = json_decode( (string) $rule->article_rules, true );
		$rules   = self::normalize_rules( is_array( $rules ) ? $rules : array() );
		if ( ! $rules ) {
			return array( 'ok' => false, 'message' => '规则「' . $rule->name . '」的字段配置无效，请用 collect_rule_save 重新保存' );
		}

		$limit    = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 5;
		$list_url = isset( $args['list_url'] ) && '' !== $args['list_url'] ? esc_url_raw( $args['list_url'] ) : $rule->list_url;
		if ( '' === $list_url ) {
			return array( 'ok' => false, 'message' => '规则未配置列表页地址，请传 list_url 或更新规则' );
		}
		$page = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$list_url = str_replace( array( '{page}', '{PAGE}' ), (string) $page, $list_url );

		$fetched = Bokeauto_Collector::fetch( $list_url, array( 'cookie' => isset( $options['cookie'] ) ? $options['cookie'] : '' ) );
		if ( ! $fetched['ok'] ) {
			return array( 'ok' => false, 'message' => '列表页抓取失败：' . $fetched['message'] );
		}

		$links = Bokeauto_Collector::extract_links( $fetched['html'], $list_url, $rule->link_selector, 100 );
		if ( ! $links ) {
			return array( 'ok' => false, 'message' => '列表页没有匹配到链接，请核对 link_selector（当前为「' . $rule->link_selector . '」）' );
		}

		$created = array();
		$skipped = 0;
		$failed  = array();

		foreach ( $links as $link ) {
			if ( count( $created ) >= $limit ) {
				break;
			}
			if ( Bokeauto_Collector::find_post_by_source( $link['url'] ) ) {
				$skipped++;
				continue;
			}
			$single = self::collect_single( $link['url'], $rules, $options );
			if ( $single['ok'] ) {
				$created[] = array(
					'文章ID' => $single['data']['post_id'],
					'标题'   => $single['data']['title'],
					'来源'   => $link['url'],
				);
			} else {
				$failed[] = $link['url'] . '（' . $single['message'] . '）';
			}
		}

		$wpdb->update(
			self::table(),
			array(
				'last_run_at'     => current_time( 'mysql' ),
				'collected_count' => (int) $rule->collected_count + count( $created ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $rule_id )
		);

		$summary = '新采集 ' . count( $created ) . ' 篇';
		if ( $skipped ) {
			$summary .= '，跳过已采集 ' . $skipped . ' 条';
		}
		if ( $failed ) {
			$summary .= '，失败 ' . count( $failed ) . ' 条';
		}

		return array(
			'ok'      => ! empty( $created ) || 0 === count( $failed ),
			'message' => '规则「' . $rule->name . '」执行完成：' . $summary,
			'data'    => array(
				'新增文章' => $created,
				'跳过'     => $skipped,
				'失败'     => array_slice( $failed, 0, 5 ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * 内部实现
	 * ------------------------------------------------------------------- */

	/** 采集单篇并入库，$options 支持 post_status / category_id / tags / image_mode / replace / set_thumbnail */
	private static function collect_single( $url, $rules, $options ) {
		$fetched = Bokeauto_Collector::fetch( $url, array( 'cookie' => isset( $options['cookie'] ) ? $options['cookie'] : '' ) );
		if ( ! $fetched['ok'] ) {
			return array( 'ok' => false, 'message' => $fetched['message'] );
		}

		$fields  = Bokeauto_Collector::extract_fields( $fetched['html'], $url, $rules );
		$title   = Bokeauto_Collector::squeeze( isset( $fields['title'] ) ? $fields['title'] : '', 200 );
		$content = isset( $fields['content'] ) ? (string) $fields['content'] : '';

		if ( '' === $title ) {
			return array( 'ok' => false, 'message' => '标题未匹配到内容，请核对 title 选择器' );
		}
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return array( 'ok' => false, 'message' => '正文未匹配到内容，请核对 content 选择器' );
		}

		$replace = isset( $options['replace'] ) && is_array( $options['replace'] ) ? $options['replace'] : array();
		$content = Bokeauto_Collector::clean_content( $content, $replace );

		$status = isset( $options['post_status'] ) && 'publish' === $options['post_status'] ? 'publish' : 'draft';
		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
		);
		if ( ! empty( $options['category_id'] ) ) {
			$post_data['post_category'] = array( (int) $options['category_id'] );
		}
		if ( ! empty( $options['tags'] ) ) {
			$post_data['tags_input'] = sanitize_text_field( $options['tags'] );
		}
		if ( ! empty( $fields['date'] ) ) {
			$stamp = strtotime( (string) $fields['date'] );
			if ( $stamp && $stamp < time() ) {
				// post_date 需为站点本地时间，gmt_offset 可能是 5.5 这类小数时区
				$offset                     = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
				$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', (int) round( $stamp + $offset ) );
				$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $stamp );
			}
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return array( 'ok' => false, 'message' => '入库失败：' . $post_id->get_error_message() );
		}
		Bokeauto_Collector::mark_source( $post_id, $url );

		// 图片处理：local 下载入库（默认），remote 保留外链
		$image_note = '';
		$mode       = isset( $options['image_mode'] ) ? $options['image_mode'] : 'local';
		if ( 'local' === $mode ) {
			$localized = Bokeauto_Collector::localize_images( $content, $url, $post_id, 20 );
			if ( $localized['downloaded'] > 0 ) {
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $localized['html'] ) );
				$image_note = '，本地化图片 ' . $localized['downloaded'] . ' 张';
				$want_thumb = ! isset( $options['set_thumbnail'] ) || $options['set_thumbnail'];
				if ( $want_thumb && $localized['first_id'] && ! has_post_thumbnail( $post_id ) ) {
					set_post_thumbnail( $post_id, $localized['first_id'] );
					$image_note .= '（首图已设为特色图片）';
				}
			} elseif ( $localized['failed'] > 0 ) {
				$image_note = '，' . $localized['failed'] . ' 张图片下载失败，正文仍指向原站地址';
			}
		}

		return array(
			'ok'      => true,
			'message' => '已采集「' . $title . '」并创建' . ( 'publish' === $status ? '并发布' : '草稿' ) . $image_note,
			'data'    => array(
				'post_id' => (int) $post_id,
				'title'   => $title,
				'status'  => $status,
				'来源'    => $url,
				'链接'    => get_permalink( $post_id ),
			),
		);
	}

	private static function get_rule( $rule_id ) {
		global $wpdb;
		if ( ! $rule_id ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $rule_id ) );
	}

	/**
	 * 规则归一：兼容 JSON 字符串、简写（字段=>选择器字符串）与完整数组。
	 *
	 * @return array array( field => array( selector, attr, remove ) )
	 */
	private static function normalize_rules( $rules ) {
		if ( is_string( $rules ) ) {
			$decoded = json_decode( $rules, true );
			$rules   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$out = array();
		foreach ( $rules as $field => $rule ) {
			$field = sanitize_key( $field );
			if ( '' === $field ) {
				continue;
			}
			if ( is_string( $rule ) ) {
				$rule = array( 'selector' => $rule );
			}
			if ( ! is_array( $rule ) || empty( $rule['selector'] ) ) {
				continue;
			}
			$out[ $field ] = array(
				'selector' => (string) $rule['selector'],
				'attr'     => isset( $rule['attr'] ) ? (string) $rule['attr'] : '',
				'remove'   => isset( $rule['remove'] ) ? (string) $rule['remove'] : '',
			);
		}
		return $out;
	}

	/** 采集选项归一 */
	private static function normalize_options( $args ) {
		$replace = isset( $args['replace'] ) ? $args['replace'] : array();
		if ( is_string( $replace ) ) {
			$decoded = json_decode( $replace, true );
			$replace = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'post_status'   => isset( $args['post_status'] ) && 'publish' === $args['post_status'] ? 'publish' : 'draft',
			'category_id'   => isset( $args['category_id'] ) ? (int) $args['category_id'] : 0,
			'tags'          => isset( $args['tags'] ) ? sanitize_text_field( $args['tags'] ) : '',
			'image_mode'    => isset( $args['image_mode'] ) && 'remote' === $args['image_mode'] ? 'remote' : 'local',
			'set_thumbnail' => isset( $args['set_thumbnail'] ) ? (bool) $args['set_thumbnail'] : true,
			'cookie'        => isset( $args['cookie'] ) ? (string) $args['cookie'] : '',
			'replace'       => is_array( $replace ) ? $replace : array(),
		);
	}
}
