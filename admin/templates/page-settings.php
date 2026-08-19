<div class="wrap">
	<h1>波克wpAI自动化插件 — 模型设置</h1>

	<?php
	$settings = Bokeauto_Settings::get();
	$presets  = Bokeauto_Settings::presets();
	$protos   = Bokeauto_Settings::protocol_labels();

	if ( isset( $_POST['bokeauto_save_settings'] ) && check_admin_referer( 'bokeauto_settings' ) ) {
		$saved = Bokeauto_Settings::update( $_POST );
		echo '<div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>';
		$settings = $saved;
	}

	// 当前实际生效的协议（用于「自动」选项的提示文案）
	$active_proto = Bokeauto_Settings::resolve_protocol(
		$settings['provider'],
		$settings['base_url'],
		$settings['protocol']
	);

	// 各服务商已保存的配置（供前端切换时动态加载；Key 打码）
	$provider_configs = array();
	foreach ( (array) ( isset( $settings['providers'] ) ? $settings['providers'] : array() ) as $pk => $pv ) {
		$pv   = is_array( $pv ) ? $pv : array();
		$key  = isset( $pv['api_key'] ) ? (string) $pv['api_key'] : '';
		$provider_configs[ $pk ] = array(
			'base_url' => isset( $pv['base_url'] ) ? $pv['base_url'] : '',
			'model'    => isset( $pv['model'] ) ? $pv['model'] : '',
			'protocol' => isset( $pv['protocol'] ) ? $pv['protocol'] : '',
			'api_key'  => '' === $key ? '' : substr( $key, 0, 4 ) . '••••••••' . substr( $key, -4 ),
		);
	}

	// 已拉取缓存的模型列表（与预设模型合并展示）
	$fetched_models = isset( $settings['fetched_models'] ) && is_array( $settings['fetched_models'] ) ? $settings['fetched_models'] : array();
	?>

	<form method="post">
		<?php wp_nonce_field( 'bokeauto_settings' ); ?>
		<input type="hidden" name="bokeauto_save_settings" value="1">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bokeauto-provider">模型服务商</label></th>
				<td>
					<select id="bokeauto-provider" name="provider">
						<?php foreach ( $presets as $key => $p ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>>
								<?php echo esc_html( $p['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">选择后会自动填充对应的 API 地址与推荐模型。选择「本地演示模式」无需 Key，可用于验证功能链路。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-base-url">API 地址（Base URL）</label></th>
				<td>
					<input type="url" class="regular-text" id="bokeauto-base-url" name="base_url"
						value="<?php echo esc_attr( $settings['base_url'] ); ?>" placeholder="https://api.deepseek.com/v1">
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-protocol">接口协议</label></th>
				<td>
					<select id="bokeauto-protocol" name="protocol">
						<option value="" <?php selected( $settings['protocol'], '' ); ?>>
							自动识别（当前：<?php echo esc_html( isset( $protos[ $active_proto ] ) ? $protos[ $active_proto ] : $active_proto ); ?>）
						</option>
						<?php foreach ( $protos as $pkey => $plabel ) : ?>
							<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $settings['protocol'], $pkey ); ?>>
								<?php echo esc_html( $plabel ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						默认按服务商与 API 地址自动判断，绝大多数服务商用 Chat Completions。
						接入只支持 Responses API 的端点，或用中转站代理 Claude 时，可在此手动指定。
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-api-key">API Key</label></th>
				<td>
					<input type="password" class="regular-text" id="bokeauto-api-key" name="api_key"
						value="<?php echo esc_attr( '' === $settings['api_key'] ? '' : substr( $settings['api_key'], 0, 4 ) . '••••••••' . substr( $settings['api_key'], -4 ) ); ?>" autocomplete="off" placeholder="填写 API Key（留空则保持原 Key；含掩码提交不会覆盖）">
					<button type="button" class="button" id="bokeauto-test-llm">测试连接</button>
					<span id="bokeauto-test-result" class="bokeauto-test-result"></span>
					<p class="description">Key 已打码显示，直接保存不会丢失原 Key；输入新 Key 即替换。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-model">模型名称</label></th>
				<td>
					<input type="text" class="regular-text" id="bokeauto-model" name="model"
						value="<?php echo esc_attr( $settings['model'] ); ?>" list="bokeauto-models">
					<datalist id="bokeauto-models"></datalist>
					<button type="button" class="button" id="bokeauto-fetch-models">获取模型列表</button>
					<span id="bokeauto-models-result" class="bokeauto-test-result"></span>
					<p class="description">可直接输入，或从下拉候选中选择。点「获取模型列表」会用当前地址与 Key 拉取该服务商的真实可用模型。</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-temperature">温度（0-2）</label></th>
				<td>
					<input type="number" min="0" max="2" step="0.1" id="bokeauto-temperature" name="temperature"
						value="<?php echo esc_attr( $settings['temperature'] ); ?>">
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-max-tokens">单次回复上限（tokens）</label></th>
				<td>
					<input type="number" min="256" max="128000" step="256" id="bokeauto-max-tokens" name="max_tokens"
						value="<?php echo esc_attr( $settings['max_tokens'] ); ?>">
					<p class="description">
						Claude 与 Responses 协议必须携带该上限，取值 256-128000。
						生成长文时可调高，但需不超过所选模型自身的输出上限。
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bokeauto-max-steps">最大执行步数</label></th>
				<td>
					<input type="number" min="3" max="40" id="bokeauto-max-steps" name="max_steps"
						value="<?php echo esc_attr( $settings['max_steps'] ); ?>">
					<p class="description">Agent 单次任务最多可执行的工具调用步数（3-40）。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">操作确认策略</th>
				<td>
					<label><input type="radio" name="confirm_mode" value="high" <?php checked( $settings['confirm_mode'], 'high' ); ?>> 仅高危操作需确认（推荐）</label><br>
					<label><input type="radio" name="confirm_mode" value="auto" <?php checked( $settings['confirm_mode'], 'auto' ); ?>> 全自动执行（不留确认）</label><br>
					<label><input type="radio" name="confirm_mode" value="all" <?php checked( $settings['confirm_mode'], 'all' ); ?>> 所有操作都需确认</label>
					<p class="description">高危操作包括：删除文章/页面、删除文件、删除插件主题、安装插件、创建用户、修改站点设置、SQL 查询等。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">记忆向量化</th>
				<td>
					<p class="description" style="margin: 0 0 8px;">
						固定使用 <code><?php echo esc_html( Bokeauto_Settings::EMBEDDING_MODEL ); ?></code>（中文语义检索效果好、有免费额度）。
						这套配置<strong>完全独立于上面的对话模型</strong>，互不影响：对话用 DeepSeek、Claude 或任意服务商都不妨碍这里。
						<a href="https://cloud.siliconflow.cn/account/ak" target="_blank" rel="noopener">前往硅基流动获取 API Key</a>
					</p>

					<p style="margin: 0 0 6px;">
						<label for="bokeauto-embedding-key" style="display:inline-block; min-width:70px;">API Key</label>
						<input type="password" class="regular-text" id="bokeauto-embedding-key" name="embedding_api_key"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( '' === $settings['embedding_api_key'] ? 'sk-…（留空则使用关键词检索）' : '已保存，留空不修改' ); ?>"
							value="">
						<button type="button" class="button" id="bokeauto-test-embedding">测试嵌入服务</button>
						<span id="bokeauto-embedding-result" class="bokeauto-test-result"></span>
					</p>

					<p style="margin: 0 0 6px;">
						<label for="bokeauto-embedding-base" style="display:inline-block; min-width:70px;">接口地址</label>
						<input type="text" class="regular-text" id="bokeauto-embedding-base" name="embedding_base_url"
							value="<?php echo esc_attr( $settings['embedding_base_url'] ); ?>">
					</p>

					<p class="description">
						地址一般无需修改，走代理或私有部署时再改（需为 OpenAI 兼容的 embeddings 接口）。
						Key 留空时记忆自动降级为中文关键词检索，插件其余功能不受影响。
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">记忆系统</th>
				<td>
					<input type="hidden" name="memory_enabled" value="0">
					<label><input type="checkbox" name="memory_enabled" value="1" <?php checked( $settings['memory_enabled'], 1 ); ?>> 启用自学习记忆</label>
					<p class="description">关闭后 Agent 不再沉淀与检索历史经验。</p>
				</td>
			</tr>

			<tr>
				<th scope="row">演示模式</th>
				<td>
					<input type="hidden" name="mock_mode" value="0">
					<label><input type="checkbox" name="mock_mode" value="1" <?php checked( $settings['mock_mode'], 1 ); ?>> 启用本地演示模式</label>
					<p class="description">不调用真实模型，用规则模拟对话与工具调用，方便先跑通链路。真实使用时请关闭。</p>
				</td>
			</tr>
		</table>

		<?php submit_button( '保存设置' ); ?>
	</form>

	<hr style="margin: 28px 0;">

	<h2>工作日志</h2>
	<p class="description" style="margin-bottom: 12px;">
		插件全程的关键进展按天记录在这里，跨对话不会丢失。任务结束后系统会自动追加一行摘要；你也可以在此手动编辑、补充或删除。
		AI 可通过 worklog_read / worklog_append / worklog_update 查看与调整这份日志。
	</p>

	<div id="bokeauto-worklog">
		<div class="bokeauto-wl-toolbar">
			<button type="button" class="button" id="bokeauto-wl-new">＋ 新增日志</button>
			<span class="bokeauto-wl-status"></span>
		</div>

		<div id="bokeauto-wl-editor" class="bokeauto-wl-editor" style="display:none;">
			<p>
				<label>日期：<input type="date" id="bokeauto-wl-day" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
			</p>
			<textarea id="bokeauto-wl-content" rows="8" placeholder="记录这一天的关键进展、决策、结果…"></textarea>
			<p>
				<button type="button" class="button button-primary" id="bokeauto-wl-save">保存</button>
				<button type="button" class="button" id="bokeauto-wl-cancel">取消</button>
			</p>
		</div>

		<div id="bokeauto-wl-list"></div>
	</div>

	<hr style="margin: 28px 0;">

	<h2>关于波克wpAI自动化插件</h2>
	<div class="bokeauto-about">
		<p>WordPress AI 智能体与自动化管理工具。</p>
	</div>

	<style>
		#bokeauto-worklog { max-width: 860px; }
		.bokeauto-wl-toolbar { margin: 8px 0 12px; display: flex; align-items: center; gap: 12px; }
		.bokeauto-wl-status { color: #2271b1; font-size: 13px; }
		.bokeauto-wl-status.err { color: #a32d2d; }
		.bokeauto-wl-editor { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; padding: 14px 16px; margin-bottom: 14px; }
		.bokeauto-wl-editor textarea { width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; }
		.bokeauto-wl-item { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #c3c4c7; margin-bottom: 10px; }
		.bokeauto-wl-item.active { border-left-color: #2271b1; }
		.bokeauto-wl-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; }
		.bokeauto-wl-head .bokeauto-wl-date { font-weight: 600; min-width: 110px; }
		.bokeauto-wl-head .bokeauto-wl-meta { color: #646970; font-size: 12px; }
		.bokeauto-wl-head .bokeauto-wl-actions { margin-left: auto; }
		.bokeauto-wl-head .bokeauto-wl-actions .button { margin-left: 6px; }
		.bokeauto-wl-preview { color: #50575e; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 420px; }
		.bokeauto-wl-body { display: none; padding: 0 14px 14px; }
		.bokeauto-wl-item.active .bokeauto-wl-body { display: block; }
		.bokeauto-wl-body textarea { width: 100%; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; }
		.bokeauto-wl-body .bokeauto-wl-actions-row { margin-top: 8px; display: flex; gap: 8px; }
		.bokeauto-wl-empty { color: #646970; padding: 18px 0; }

		/* 关于插件 */
		.bokeauto-about { max-width: 620px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #c3c4c7; border-radius: 8px; padding: 14px 18px; }
		.bokeauto-about p { margin: 6px 0; }
	</style>

	<script>
	(function () {
		var presets = <?php echo wp_json_encode( $presets ); ?>;
		var providerConfigs = <?php echo wp_json_encode( $provider_configs ); ?>;
		var fetchedModels = <?php echo wp_json_encode( $fetched_models ); ?>;

		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}
		var sel = document.getElementById( 'bokeauto-provider' );
		var base = document.getElementById( 'bokeauto-base-url' );
		var model = document.getElementById( 'bokeauto-model' );
		var modelsList = document.getElementById( 'bokeauto-models' );
		var keyInput = document.getElementById( 'bokeauto-api-key' );
		var protoSel = document.getElementById( 'bokeauto-protocol' );

		/**
		 * 渲染模型候选：预设内置 + 已拉取缓存合并去重。
		 *
		 * @param {string} provider 服务商键名。
		 * @param {Array}  extra    本次新拉取到的模型（可选）。
		 */
		function renderModels( provider, extra ) {
			var p = presets[ provider ] || {};
			var list = [];
			[ p.models || [], fetchedModels[ provider ] || [], extra || [] ].forEach( function ( arr ) {
				arr.forEach( function ( m ) {
					if ( m && list.indexOf( m ) === -1 ) { list.push( m ); }
				} );
			} );
			modelsList.innerHTML = list.map( function ( m ) {
				return '<option value="' + esc( m ) + '">';
			} ).join( '' );
		}

		function applyPreset() {
			var p = presets[ sel.value ];
			if ( ! p ) { return; }
			var saved = providerConfigs[ sel.value ];
			if ( saved ) {
				// 该服务商保存过配置 → 动态加载（切换过来自动恢复自己保存的值）
				if ( saved.base_url ) { base.value = saved.base_url; }
				if ( saved.model ) { model.value = saved.model; }
				if ( saved.api_key ) { keyInput.value = saved.api_key; }
				protoSel.value = saved.protocol || '';
			} else {
				// 从未保存过 → 填充该服务商预设，Key 与协议清空（避免残留上一个服务商的值）
				if ( p.base_url ) { base.value = p.base_url; }
				if ( p.model ) { model.value = p.model; }
				keyInput.value = '';
				protoSel.value = '';
			}
			renderModels( sel.value );
			if ( sel.value === 'mock' ) {
				keyInput.value = '';
			}
		}
		sel.addEventListener( 'change', applyPreset );
		// 仅填充模型下拉列表；不执行 applyPreset()，避免页面加载时把已保存的 base_url/模型覆盖成预设值
		renderModels( sel.value );

		/**
		 * 渲染操作结果，颜色由 CSS 状态类控制。
		 *
		 * @param {HTMLElement} out   结果容器。
		 * @param {string}      text  提示文案。
		 * @param {string}      state 状态类名：is-ok / is-err / is-busy。
		 */
		function setResult( out, text, state ) {
			out.textContent = text || '';
			out.className = 'bokeauto-test-result' + ( text && state ? ' ' + state : '' );
		}

		function apiPost( path, body ) {
			return fetch( BOKEAUTO.api + path, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': BOKEAUTO.nonce
				},
				body: JSON.stringify( body )
			} ).then( function ( r ) { return r.json(); } );
		}

		document.getElementById( 'bokeauto-test-llm' ).addEventListener( 'click', function () {
			var btn = this;
			var out = document.getElementById( 'bokeauto-test-result' );

			btn.disabled = true;
			setResult( out, '测试中…', 'is-busy' );

			apiPost( 'test-llm', {
				provider: sel.value,
				base_url: base.value,
				api_key: keyInput.value,
				model: model.value,
				protocol: protoSel.value
			} )
			.then( function ( d ) {
				setResult( out, d.message, d.ok ? 'is-ok' : 'is-err' );
			} )
			.catch( function () {
				setResult( out, '请求失败', 'is-err' );
			} )
			.finally( function () { btn.disabled = false; } );
		} );

		document.getElementById( 'bokeauto-test-embedding' ).addEventListener( 'click', function () {
			var btn = this;
			var out = document.getElementById( 'bokeauto-embedding-result' );

			btn.disabled = true;
			setResult( out, '测试中…', 'is-busy' );

			apiPost( 'test-embedding', {
				embedding_base_url: document.getElementById( 'bokeauto-embedding-base' ).value,
				embedding_api_key: document.getElementById( 'bokeauto-embedding-key' ).value
			} )
			.then( function ( d ) {
				setResult( out, d.message, d.ok ? 'is-ok' : 'is-err' );
			} )
			.catch( function () {
				setResult( out, '请求失败', 'is-err' );
			} )
			.finally( function () { btn.disabled = false; } );
		} );

		document.getElementById( 'bokeauto-fetch-models' ).addEventListener( 'click', function () {

			var btn = this;
			var out = document.getElementById( 'bokeauto-models-result' );

			btn.disabled = true;
			setResult( out, '获取中…', 'is-busy' );

			apiPost( 'models', {
				provider: sel.value,
				base_url: base.value,
				api_key: keyInput.value,
				protocol: protoSel.value
			} )
			.then( function ( d ) {
				setResult( out, d.message, d.ok ? 'is-ok' : 'is-err' );
				if ( d.ok && d.models && d.models.length ) {
					fetchedModels[ sel.value ] = d.models;
					renderModels( sel.value );
					// 点开输入框即可看到候选，无需再次刷新页面
					model.focus();
				}
			} )
			.catch( function () {
				setResult( out, '请求失败', 'is-err' );
			} )
			.finally( function () { btn.disabled = false; } );
		} );
	})();
	</script>
</div>
