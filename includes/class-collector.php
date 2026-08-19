<?php
/**
 * 网页采集引擎
 *
 * 提供抓取、CSS 选择器解析、字段提取、链接补全、图片本地化、内容清洗与去重能力。
 * 仅依赖 PHP 内置 DOM 扩展与 WordPress HTTP / 媒体 API，不引入第三方 composer 依赖。
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

class Bokeauto_Collector {

	/** 采集来源 URL 的文章 meta 键（用于去重） */
	const META_SOURCE = '_bokeauto_source_url';

	/** 图片原始 URL 的附件 meta 键（用于图片幂等复用） */
	const META_IMAGE_SOURCE = '_bokeauto_source_image';

	/** 单次抓取的响应体上限 */
	const MAX_BODY = 3145728; // 3MB

	/* ---------------------------------------------------------------------
	 * 抓取
	 * ------------------------------------------------------------------- */

	/**
	 * 抓取网页 HTML（自动转 UTF-8）。
	 *
	 * @param string $url  目标地址。
	 * @param array  $opts cookie / referer / timeout / user_agent。
	 * @return array array( ok, html, status, message )
	 */
	public static function fetch( $url, $opts = array() ) {
		$url  = esc_url_raw( trim( (string) $url ) );
		$safe = Bokeauto_Tools::validate_download_url( $url );
		if ( true !== $safe ) {
			return array( 'ok' => false, 'html' => '', 'status' => 0, 'message' => $safe );
		}

		$headers = array(
			'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
			'Referer'         => isset( $opts['referer'] ) && '' !== $opts['referer'] ? esc_url_raw( $opts['referer'] ) : $url,
		);
		if ( ! empty( $opts['cookie'] ) ) {
			$headers['Cookie'] = self::sanitize_header( $opts['cookie'] );
		}

		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 20;
		$timeout = max( 5, min( 60, $timeout ) );

		$response = wp_safe_remote_get( $url, array(
			'timeout'             => $timeout,
			'redirection'         => 3,
			'limit_response_size' => self::MAX_BODY,
			'user-agent'          => ! empty( $opts['user_agent'] ) ? self::sanitize_header( $opts['user_agent'] ) : self::default_ua(),
			'headers'             => $headers,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'html' => '', 'status' => 0, 'message' => '抓取失败：' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return array( 'ok' => false, 'html' => $body, 'status' => $status, 'message' => '目标返回 HTTP ' . $status );
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $type && false === strpos( $type, 'html' ) && false === strpos( $type, 'xml' ) && false === strpos( $type, 'text/plain' ) ) {
			return array( 'ok' => false, 'html' => '', 'status' => $status, 'message' => '内容类型为 ' . $type . '，不是网页' );
		}
		if ( '' === trim( $body ) ) {
			return array( 'ok' => false, 'html' => '', 'status' => $status, 'message' => '网页内容为空' );
		}

		return array(
			'ok'      => true,
			'html'    => self::to_utf8( $body, $type ),
			'status'  => $status,
			'message' => '抓取成功',
		);
	}

	private static function default_ua() {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Boke-wpAI/' . ( defined( 'BOKEAUTO_VERSION' ) ? BOKEAUTO_VERSION : '1.0' );
	}

	/** 去掉请求头里的换行，避免头注入 */
	private static function sanitize_header( $value ) {
		return trim( str_replace( array( "\r", "\n", "\0" ), '', (string) $value ) );
	}

	/** 编码归一：优先 Content-Type，其次 meta charset，最后自动探测 */
	public static function to_utf8( $html, $content_type = '' ) {
		$charset = '';
		if ( preg_match( '/charset\s*=\s*["\']?([a-z0-9_\-]+)/i', (string) $content_type, $m ) ) {
			$charset = strtolower( $m[1] );
		}
		if ( '' === $charset && preg_match( '/<meta[^>]+charset\s*=\s*["\']?([a-z0-9_\-]+)/i', substr( $html, 0, 4096 ), $m ) ) {
			$charset = strtolower( $m[1] );
		}
		if ( '' === $charset && function_exists( 'mb_detect_encoding' ) ) {
			$detected = mb_detect_encoding( $html, array( 'UTF-8', 'GB2312', 'GBK', 'BIG5', 'ISO-8859-1' ), true );
			$charset  = $detected ? strtolower( $detected ) : '';
		}
		if ( '' === $charset || in_array( $charset, array( 'utf-8', 'utf8' ), true ) ) {
			return $html;
		}
		if ( 'gb2312' === $charset || 'gb-2312' === $charset ) {
			$charset = 'GBK'; // GB2312 声明常混用 GBK 字符
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $html, 'UTF-8', $charset );
			if ( '' !== (string) $converted ) {
				return $converted;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $charset, 'UTF-8//IGNORE', $html );
			if ( false !== $converted && '' !== $converted ) {
				return $converted;
			}
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * DOM 与选择器
	 * ------------------------------------------------------------------- */

	/** 载入 HTML 为 DOMDocument，失败返回 null */
	public static function dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded ? $dom : null;
	}

	/**
	 * 按 CSS 选择器查询节点。
	 *
	 * @param DOMDocument $dom      文档。
	 * @param string      $selector CSS 选择器，空表示整篇文档 body。
	 * @param DOMNode     $context  可选上下文节点。
	 * @return array DOMNode 数组
	 */
	public static function query( $dom, $selector, $context = null ) {
		if ( ! $dom instanceof DOMDocument ) {
			return array();
		}
		$xpath    = new DOMXPath( $dom );
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			$body = $xpath->query( '//body' )->item( 0 );
			return $body ? array( $body ) : array( $dom->documentElement );
		}

		$expr = self::css_to_xpath( $selector, null !== $context );
		if ( '' === $expr ) {
			return array();
		}
		$nodes = null === $context ? $xpath->query( $expr ) : $xpath->query( $expr, $context );
		if ( false === $nodes ) {
			return array();
		}
		$out = array();
		foreach ( $nodes as $node ) {
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * CSS 选择器转 XPath。
	 *
	 * 支持：逗号分组、后代与子代组合器、标签、#id、.class、[attr]、[attr=v]、
	 * [attr^=v]、[attr$=v]、[attr*=v]、:first、:last、:eq(n)、:nth-child(n)、:contains(文本)、:not(简单选择器)。
	 *
	 * @param string $selector 选择器。
	 * @param bool   $relative 是否相对当前上下文节点。
	 * @return string XPath 表达式
	 */
	public static function css_to_xpath( $selector, $relative = false ) {
		$groups = array();
		foreach ( self::split_top_level( $selector, ',' ) as $single ) {
			$single = trim( $single );
			if ( '' === $single ) {
				continue;
			}
			$expr = self::compile_sequence( $single, $relative );
			if ( '' !== $expr ) {
				$groups[] = $expr;
			}
		}
		return implode( ' | ', $groups );
	}

	/** 编译单条选择器（无逗号） */
	private static function compile_sequence( $selector, $relative ) {
		$tokens = self::tokenize_sequence( $selector );
		if ( ! $tokens ) {
			return '';
		}

		$expr      = $relative ? '.' : '';
		$axis_next = $relative ? '//' : '//';
		foreach ( $tokens as $token ) {
			if ( '>' === $token ) {
				$axis_next = '/';
				continue;
			}
			$compiled = self::compile_simple( $token );
			if ( null === $compiled ) {
				return '';
			}
			$step = $axis_next . $compiled['tag'] . $compiled['predicates'];
			if ( '' !== $compiled['position'] ) {
				// 位置伪类需要包裹当前完整路径
				$expr = '(' . $expr . $step . ')' . $compiled['position'];
			} else {
				$expr .= $step;
			}
			$axis_next = '//';
		}
		return $expr;
	}

	/** 拆成 简单选择器 与 > 组合器 序列 */
	private static function tokenize_sequence( $selector ) {
		$selector = preg_replace( '/\s*>\s*/', ' > ', trim( $selector ) );
		$selector = preg_replace( '/\s+/', ' ', (string) $selector );
		$parts    = self::split_top_level( $selector, ' ' );
		$tokens   = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$tokens[] = $part;
			}
		}
		return $tokens;
	}

	/**
	 * 编译一个简单选择器（如 div#main.post[data-id="3"]:first）。
	 *
	 * @return array|null array( tag, predicates, position )
	 */
	private static function compile_simple( $token ) {
		$tag        = '*';
		$predicates = '';
		$position   = '';
		$offset     = 0;
		$length     = strlen( $token );

		// 起始标签名
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9_-]*|\*)/', $token, $m ) ) {
			$tag    = '*' === $m[1] ? '*' : strtolower( $m[1] );
			$offset = strlen( $m[1] );
		}

		while ( $offset < $length ) {
			$char = $token[ $offset ];

			if ( '#' === $char && preg_match( '/^#([^\s#.\[:]+)/', substr( $token, $offset ), $m ) ) {
				$predicates .= '[@id=' . self::xpath_literal( $m[1] ) . ']';
				$offset     += strlen( $m[0] );
				continue;
			}

			if ( '.' === $char && preg_match( '/^\.([^\s#.\[:]+)/', substr( $token, $offset ), $m ) ) {
				$predicates .= '[contains(concat(" ",normalize-space(@class)," "),' . self::xpath_literal( ' ' . $m[1] . ' ' ) . ')]';
				$offset     += strlen( $m[0] );
				continue;
			}

			if ( '[' === $char ) {
				$close = strpos( $token, ']', $offset );
				if ( false === $close ) {
					return null;
				}
				$inner       = substr( $token, $offset + 1, $close - $offset - 1 );
				$attr_expr   = self::compile_attribute( $inner );
				if ( null === $attr_expr ) {
					return null;
				}
				$predicates .= $attr_expr;
				$offset      = $close + 1;
				continue;
			}

			if ( ':' === $char ) {
				$pseudo = self::read_pseudo( $token, $offset );
				if ( null === $pseudo ) {
					return null;
				}
				$offset = $pseudo['offset'];
				if ( '' !== $pseudo['predicate'] ) {
					$predicates .= $pseudo['predicate'];
				}
				if ( '' !== $pseudo['position'] ) {
					$position = $pseudo['position'];
				}
				continue;
			}

			// 不识别的字符：整体判为无效选择器
			return null;
		}

		return array( 'tag' => $tag, 'predicates' => $predicates, 'position' => $position );
	}

	/** 属性选择器：[attr] [attr=v] [attr^=v] [attr$=v] [attr*=v] [attr~=v] */
	private static function compile_attribute( $inner ) {
		$inner = trim( $inner );
		if ( '' === $inner ) {
			return null;
		}
		if ( ! preg_match( '/^([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*(?:([~^$*|]?=)\s*(.*))?$/', $inner, $m ) ) {
			return null;
		}
		$attr = '@' . $m[1];
		if ( ! isset( $m[2] ) || '' === $m[2] ) {
			return '[' . $attr . ']';
		}
		$value = trim( $m[3] );
		$value = trim( $value, "\"'" );
		$lit   = self::xpath_literal( $value );

		switch ( $m[2] ) {
			case '=':
				return '[' . $attr . '=' . $lit . ']';
			case '^=':
				return '[starts-with(' . $attr . ',' . $lit . ')]';
			case '$=':
				return '[substring(' . $attr . ',string-length(' . $attr . ')-' . ( strlen( $value ) - 1 ) . ')=' . $lit . ']';
			case '*=':
				return '[contains(' . $attr . ',' . $lit . ')]';
			case '~=':
				return '[contains(concat(" ",normalize-space(' . $attr . ')," "),' . self::xpath_literal( ' ' . $value . ' ' ) . ')]';
			case '|=':
				return '[' . $attr . '=' . $lit . ' or starts-with(' . $attr . ',' . self::xpath_literal( $value . '-' ) . ')]';
		}
		return null;
	}

	/** 伪类解析，返回 array( predicate, position, offset ) */
	private static function read_pseudo( $token, $offset ) {
		$rest = substr( $token, $offset );
		if ( ! preg_match( '/^:([a-zA-Z-]+)/', $rest, $m ) ) {
			return null;
		}
		$name     = strtolower( $m[1] );
		$consumed = strlen( $m[0] );
		$arg      = null;

		if ( isset( $rest[ $consumed ] ) && '(' === $rest[ $consumed ] ) {
			$depth = 0;
			$end   = -1;
			for ( $i = $consumed, $len = strlen( $rest ); $i < $len; $i++ ) {
				if ( '(' === $rest[ $i ] ) {
					$depth++;
				} elseif ( ')' === $rest[ $i ] ) {
					$depth--;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}
			if ( -1 === $end ) {
				return null;
			}
			$arg      = substr( $rest, $consumed + 1, $end - $consumed - 1 );
			$consumed = $end + 1;
		}

		$predicate = '';
		$position  = '';

		switch ( $name ) {
			case 'first':
			case 'first-of-type':
				$position = '[1]';
				break;
			case 'last':
			case 'last-of-type':
				$position = '[last()]';
				break;
			case 'eq':
				$position = '[' . ( max( 0, (int) $arg ) + 1 ) . ']';
				break;
			case 'gt':
				$predicate = '';
				$position  = '[position()>' . ( (int) $arg + 1 ) . ']';
				break;
			case 'lt':
				$position = '[position()<' . ( (int) $arg + 1 ) . ']';
				break;
			case 'nth-child':
				$n         = max( 1, (int) $arg );
				$predicate = '[count(preceding-sibling::*)=' . ( $n - 1 ) . ']';
				break;
			case 'first-child':
				$predicate = '[count(preceding-sibling::*)=0]';
				break;
			case 'last-child':
				$predicate = '[count(following-sibling::*)=0]';
				break;
			case 'contains':
				$text      = trim( (string) $arg, " \"'" );
				$predicate = '[contains(.,' . self::xpath_literal( $text ) . ')]';
				break;
			case 'not':
				$sub = self::compile_simple( trim( (string) $arg ) );
				if ( null === $sub || '' === $sub['predicates'] ) {
					return null;
				}
				$inner     = trim( $sub['predicates'], '[]' );
				$predicate = '[not(' . $inner . ')]';
				break;
			case 'empty':
				$predicate = '[not(node())]';
				break;
			default:
				return null;
		}

		return array( 'predicate' => $predicate, 'position' => $position, 'offset' => $offset + $consumed );
	}

	/** 顶层分隔（忽略括号与引号内的分隔符） */
	private static function split_top_level( $input, $delimiter ) {
		$out    = array();
		$buffer = '';
		$depth  = 0;
		$quote  = '';
		$length = strlen( $input );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $input[ $i ];
			if ( '' !== $quote ) {
				$buffer .= $char;
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}
			if ( '(' === $char || '[' === $char ) {
				$depth++;
			} elseif ( ')' === $char || ']' === $char ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( $char === $delimiter && 0 === $depth ) {
				$out[]  = $buffer;
				$buffer = '';
				continue;
			}
			$buffer .= $char;
		}
		$out[] = $buffer;
		return $out;
	}

	/** XPath 字符串字面量（安全处理引号） */
	public static function xpath_literal( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		$parts = explode( "'", $value );
		return "concat('" . implode( "',\"'\",'", $parts ) . "')";
	}

	/* ---------------------------------------------------------------------
	 * 链接补全
	 * ------------------------------------------------------------------- */

	/** 相对地址转绝对地址（基于路径栈，支持多级 ../） */
	public static function absolutize( $url, $base ) {
		$url  = trim( (string) $url );
		$base = trim( (string) $base );
		if ( '' === $url || '' === $base ) {
			return $url;
		}
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url ) ) {
			return preg_match( '#^https?://#i', $url ) ? $url : '';
		}
		if ( 0 === strpos( $url, '#' ) ) {
			return '';
		}

		$parts = wp_parse_url( $base );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}

		$query = '';
		if ( false !== strpos( $url, '?' ) ) {
			list( $url, $query ) = explode( '?', $url, 2 );
			$query = '?' . $query;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$path = $url;
		} else {
			$base_path = isset( $parts['path'] ) ? $parts['path'] : '/';
			$base_dir  = substr( $base_path, 0, strrpos( $base_path, '/' ) + 1 );
			if ( '' === $base_dir ) {
				$base_dir = '/';
			}
			$path = $base_dir . $url;
		}

		// 归一 . 与 ..
		$segments = explode( '/', $path );
		$stack    = array();
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
		$normalized = '/' . implode( '/', $stack );
		if ( '/' !== substr( $path, -1 ) || '/' === $normalized ) {
			return $scheme . '://' . $host . $normalized . $query;
		}
		return $scheme . '://' . $host . $normalized . '/' . $query;
	}

	/** 把节点树内的 a[href] 与 img[src] 全部转为绝对地址 */
	public static function absolutize_node( $node, $base, $src_attr = 'src' ) {
		if ( ! $node instanceof DOMNode || ! $node->ownerDocument ) {
			return;
		}
		$xpath = new DOMXPath( $node->ownerDocument );

		foreach ( $xpath->query( './/a[@href]', $node ) as $link ) {
			$abs = self::absolutize( $link->getAttribute( 'href' ), $base );
			if ( '' === $abs ) {
				$link->removeAttribute( 'href' );
			} else {
				$link->setAttribute( 'href', $abs );
			}
		}

		foreach ( $xpath->query( './/img', $node ) as $img ) {
			$raw = '';
			foreach ( array_unique( array( $src_attr, 'src', 'data-src', 'data-original', 'data-actualsrc' ) ) as $attr ) {
				if ( $img->hasAttribute( $attr ) && '' !== trim( $img->getAttribute( $attr ) ) ) {
					$raw = trim( $img->getAttribute( $attr ) );
					break;
				}
			}
			if ( '' === $raw ) {
				continue;
			}
			$abs = self::absolutize( $raw, $base );
			if ( '' === $abs ) {
				continue;
			}
			// 清掉懒加载与尺寸属性，只留标准 src 与 alt
			$alt = $img->getAttribute( 'alt' );
			while ( $img->attributes->length > 0 ) {
				$img->removeAttribute( $img->attributes->item( 0 )->nodeName );
			}
			$img->setAttribute( 'src', $abs );
			if ( '' !== $alt ) {
				$img->setAttribute( 'alt', $alt );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * 字段提取
	 * ------------------------------------------------------------------- */

	/**
	 * 提取列表页链接。
	 *
	 * @param string $html     页面 HTML。
	 * @param string $base     页面地址（用于补全）。
	 * @param string $selector 链接选择器，如 .list h2 a。
	 * @param int    $limit    最多返回条数。
	 * @return array 链接数组，每项 array( url, text )
	 */
	public static function extract_links( $html, $base, $selector, $limit = 50 ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			$selector = 'a';
		}
		$nodes = self::query( $dom, $selector );
		$out   = array();
		$seen  = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$anchor = 'a' === strtolower( $node->nodeName ) ? $node : null;
			if ( ! $anchor ) {
				$inner = self::query( $dom, 'a', $node );
				$anchor = $inner ? $inner[0] : null;
			}
			if ( ! $anchor instanceof DOMElement || ! $anchor->hasAttribute( 'href' ) ) {
				continue;
			}
			$url = self::absolutize( $anchor->getAttribute( 'href' ), $base );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = array(
				'url'  => $url,
				'text' => self::squeeze( $anchor->textContent, 200 ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * 按规则提取详情页字段。
	 *
	 * 规则格式：array( 字段名 => array( 'selector' => '.title', 'attr' => 'text|html|attr:href', 'remove' => '.ad, .share' ) )
	 * attr 缺省：content 字段取 html，其余取 text。
	 *
	 * @param string $html  页面 HTML。
	 * @param string $base  页面地址。
	 * @param array  $rules 字段规则。
	 * @return array 字段值
	 */
	public static function extract_fields( $html, $base, $rules ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}
		$out = array();
		foreach ( (array) $rules as $field => $rule ) {
			$field = sanitize_key( $field );
			if ( '' === $field ) {
				continue;
			}
			if ( is_string( $rule ) ) {
				$rule = array( 'selector' => $rule );
			}
			$selector = isset( $rule['selector'] ) ? (string) $rule['selector'] : '';
			$attr     = isset( $rule['attr'] ) && '' !== $rule['attr'] ? (string) $rule['attr'] : ( 'content' === $field ? 'html' : 'text' );
			$remove   = isset( $rule['remove'] ) ? (string) $rule['remove'] : '';

			$nodes = self::query( $dom, $selector );
			if ( ! $nodes ) {
				$out[ $field ] = '';
				continue;
			}
			$node = $nodes[0];

			// 删除噪音节点
			if ( '' !== $remove ) {
				foreach ( self::query( $dom, $remove, $node ) as $trash ) {
					if ( $trash->parentNode ) {
						$trash->parentNode->removeChild( $trash );
					}
				}
			}
			foreach ( self::query( $dom, 'script, style, noscript, iframe', $node ) as $trash ) {
				if ( $trash->parentNode ) {
					$trash->parentNode->removeChild( $trash );
				}
			}

			$out[ $field ] = self::node_value( $node, $attr, $base );
		}
		return $out;
	}

	/** 取节点值：text / html / attr:xxx */
	public static function node_value( $node, $attr, $base ) {
		if ( ! $node instanceof DOMNode ) {
			return '';
		}
		if ( 0 === stripos( $attr, 'attr:' ) || 0 === stripos( $attr, 'attr(' ) ) {
			$name = trim( substr( $attr, 5 ), '():' );
			if ( ! $node instanceof DOMElement || ! $node->hasAttribute( $name ) ) {
				return '';
			}
			$value = $node->getAttribute( $name );
			return in_array( strtolower( $name ), array( 'href', 'src', 'data-src' ), true ) ? self::absolutize( $value, $base ) : trim( $value );
		}
		if ( 'html' === strtolower( $attr ) ) {
			self::absolutize_node( $node, $base );
			return self::inner_html( $node );
		}
		return self::squeeze( $node->textContent, 0 );
	}

	/** 节点内部 HTML */
	public static function inner_html( $node ) {
		if ( ! $node instanceof DOMNode || ! $node->ownerDocument ) {
			return '';
		}
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return trim( $html );
	}

	/** 压缩空白，可选截断 */
	public static function squeeze( $text, $limit = 0 ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		if ( $limit > 0 ) {
			$text = mb_substr( $text, 0, $limit );
		}
		return $text;
	}

	/* ---------------------------------------------------------------------
	 * 内容清洗
	 * ------------------------------------------------------------------- */

	/**
	 * 正文清洗：去脚本与注释、按规则替换关键词、过 wp_kses_post。
	 *
	 * @param string $html     正文 HTML。
	 * @param array  $replace  关键词替换表 array( 原文 => 新文 )。
	 * @return string
	 */
	public static function clean_content( $html, $replace = array() ) {
		$html = (string) $html;
		$html = preg_replace( '#<(script|style|noscript|iframe|form)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '/<!--(?!\[if).*?-->/s', '', (string) $html );
		foreach ( (array) $replace as $from => $to ) {
			$from = (string) $from;
			if ( '' === $from ) {
				continue;
			}
			$html = str_replace( $from, (string) $to, (string) $html );
		}
		$html = preg_replace( '#(<p>\s*(&nbsp;)?\s*</p>\s*)+#i', '', (string) $html );
		return trim( wp_kses_post( (string) $html ) );
	}

	/* ---------------------------------------------------------------------
	 * 图片本地化
	 * ------------------------------------------------------------------- */

	/**
	 * 把正文中的远程图片下载进媒体库并替换地址。
	 *
	 * @param string $html    正文 HTML。
	 * @param string $base    页面地址（补全相对图片路径）。
	 * @param int    $post_id 归属文章 ID，0 表示暂不挂载。
	 * @param int    $limit   最多处理张数。
	 * @return array array( html, first_id, downloaded, failed )
	 */
	public static function localize_images( $html, $base = '', $post_id = 0, $limit = 20 ) {
		$html  = (string) $html;
		$first = 0;
		$done  = 0;
		$fail  = 0;

		if ( ! preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i', $html, $matches ) ) {
			return array( 'html' => $html, 'first_id' => 0, 'downloaded' => 0, 'failed' => 0 );
		}

		$urls = array_unique( $matches[1] );
		foreach ( $urls as $raw ) {
			if ( $done >= $limit ) {
				break;
			}
			$url = '' !== $base ? self::absolutize( $raw, $base ) : $raw;
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			// 已在本站媒体库的图片跳过
			if ( 0 === strpos( $url, (string) wp_upload_dir()['baseurl'] ) ) {
				continue;
			}

			$attach_id = self::sideload_image( $url, $post_id );
			if ( ! $attach_id ) {
				$fail++;
				continue;
			}
			$local = wp_get_attachment_url( $attach_id );
			if ( ! $local ) {
				$fail++;
				continue;
			}
			$html = str_replace( array( '"' . $raw . '"', "'" . $raw . "'" ), array( '"' . esc_url( $local ) . '"', '"' . esc_url( $local ) . '"' ), $html );
			$done++;
			if ( ! $first ) {
				$first = $attach_id;
			}
		}

		return array( 'html' => $html, 'first_id' => $first, 'downloaded' => $done, 'failed' => $fail );
	}

	/**
	 * 下载单张远程图片入媒体库，同一 URL 幂等复用。
	 *
	 * @return int 附件 ID，失败返回 0
	 */
	public static function sideload_image( $url, $post_id = 0 ) {
		$url = esc_url_raw( $url );
		if ( true !== Bokeauto_Tools::validate_download_url( $url ) ) {
			return 0;
		}

		$existing = self::find_attachment_by_source( $url );
		if ( $existing ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$name = sanitize_file_name( $name );
		if ( '' === $name || ! preg_match( '/\.(jpe?g|png|gif|webp|bmp|avif)$/i', $name ) ) {
			$size = @getimagesize( $tmp );
			$ext  = ( $size && ! empty( $size['mime'] ) ) ? self::mime_to_ext( $size['mime'] ) : '';
			if ( '' === $ext ) {
				@unlink( $tmp );
				return 0;
			}
			$name = 'bokeauto-' . md5( $url ) . '.' . $ext;
		}

		$attach_id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), (int) $post_id );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return 0;
		}
		update_post_meta( $attach_id, self::META_IMAGE_SOURCE, $url );
		return (int) $attach_id;
	}

	private static function mime_to_ext( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/bmp'  => 'bmp',
			'image/avif' => 'avif',
		);
		return isset( $map[ strtolower( $mime ) ] ) ? $map[ strtolower( $mime ) ] : '';
	}

	/** 按原图 URL 查已入库附件 */
	public static function find_attachment_by_source( $url ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_IMAGE_SOURCE,
				$url
			)
		);
		return $id ? (int) $id : 0;
	}

	/* ---------------------------------------------------------------------
	 * 去重
	 * ------------------------------------------------------------------- */

	/** 该来源 URL 是否已采集过，返回已存在的文章 ID */
	public static function find_post_by_source( $url ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_SOURCE,
				$url
			)
		);
		return $id ? (int) $id : 0;
	}

	/** 标记文章的采集来源 */
	public static function mark_source( $post_id, $url ) {
		update_post_meta( (int) $post_id, self::META_SOURCE, esc_url_raw( $url ) );
	}
}
