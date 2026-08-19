<div class="wrap bokeauto-wrap">
	<h1 class="bokeauto-title">
		<span class="dashicons dashicons-superhero"></span>
		波克wpAI自动化插件
		<span class="bokeauto-ver">v<?php echo esc_html( BOKEAUTO_VERSION ); ?> · 免费开源</span>
	</h1>

	<div class="bokeauto-sub">
		<p class="bokeauto-desc">内置 AI 智能体助手：连接外部大模型 API 与内置向量记忆库，用自然语言完成 WordPress 全站管理、开发与维护任务。自学习，越用越聪明。</p>
		<p class="bokeauto-open-note">
			🆓 本插件免费开源，当前版本可能存在一些问题，有能力的朋友可自行修复，也欢迎联系作者一起改进。
			期待与你共同探讨、拓展 WP 智能体能力，让它更好用、适配更多用户需求场景。
		</p>
	</div>

	<div class="bokeauto-chat-layout">
		<!-- 侧栏 -->
		<aside class="bokeauto-sidebar">
			<button type="button" class="button button-primary bokeauto-btn-new" id="bokeauto-new-chat">新对话</button>

			<div class="bokeauto-conv-block">
				<div class="bokeauto-conv-head">
					<h3>会话记录</h3>
					<button type="button" class="bokeauto-btn-clear" id="bokeauto-btn-clear" title="清空聊天记录">清空</button>
				</div>
				<ul class="bokeauto-conv-list" id="bokeauto-conv-list">
					<li class="empty">加载中…</li>
				</ul>
			</div>

			<!-- 清空聊天菜单 -->
			<div class="bokeauto-clear-menu" id="bokeauto-clear-menu" style="display:none;">
				<button type="button" data-act="current">清空当前会话</button>
				<button type="button" data-act="all">清空全部会话</button>
			</div>

			<div class="bokeauto-side-block">
				<h3>记忆库</h3>
				<p class="bokeauto-side-desc">任务经验自动沉淀，越用越聪明</p>
				<button type="button" class="button bokeauto-btn-memories" id="bokeauto-btn-memories">查看记忆（<span id="bokeauto-memory-count">0</span>）</button>
			</div>

			<div class="bokeauto-side-block">
				<h3>Token 消耗</h3>
				<ul class="bokeauto-usage" id="bokeauto-usage">
					<li>当前会话：<strong id="usage-conv">-</strong></li>
					<li>今日：<strong id="usage-today">-</strong></li>
					<li>累计：<strong id="usage-total">-</strong></li>
					<li class="desc">调用 <span id="usage-calls">0</span> 次 · 最近 <span id="usage-last">-</span></li>
				</ul>
			</div>

			<div class="bokeauto-side-block bokeauto-side-foot">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bokeauto-settings' ) ); ?>">模型设置</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bokeauto-roles' ) ); ?>">角色与技能</a>
			</div>
		</aside>

		<!-- 对话区 -->
		<main class="bokeauto-chat-main">
			<div class="bokeauto-model-bar">
				<span class="model-bar-label">角色</span>
				<select id="bokeauto-bar-role" title="切换当前对话角色（总调度可协调所有角色协作）"><option value="0">总调度（波克wpAI）</option></select>
				<span class="model-bar-label">模型</span>
				<select id="bokeauto-bar-provider" title="切换模型服务商"></select>
				<select id="bokeauto-bar-model" title="切换模型"></select>
				<button type="button" class="button button-small" id="bokeauto-bar-switch">切换</button>
				<span class="model-bar-current" id="bokeauto-bar-current"></span>
				<label class="bokeauto-auto-confirm" title="开启后：删除、覆盖、改库等高危操作将不再逐次确认，直接执行（操作仍会记录审计日志）">
					<input type="checkbox" id="bokeauto-auto-confirm">
					<span class="auto-confirm-switch"></span>
					免授权执行<span class="auto-confirm-tip"></span>
				</label>
				<span class="bokeauto-run-opts" title="后台异步进程的执行参数：0 = 不限制。即使工具失败也不会中断任务。">
					<label>最大工具步数 <input type="number" id="bokeauto-max-steps" min="0" max="999" step="1" value="0"></label>
					<label>PHP超时(秒) <input type="number" id="bokeauto-php-timeout" min="0" max="86400" step="10" value="0"></label>
				</span>
			</div>
			<div class="bokeauto-messages" id="bokeauto-messages"></div>
			<div class="bokeauto-composer">
				<textarea id="bokeauto-input" rows="1" placeholder="<?php echo esc_attr( '例如：帮我发布一篇介绍网站的文章，并创建分类「新闻」…' ); ?>"></textarea>
				<button type="button" class="button button-primary" id="bokeauto-send">发送</button>
			</div>
		</main>
	</div>

	<!-- 可视化文件编辑器（AI 修改文件后出现的可编辑卡片） -->
	<div class="bokeauto-modal" id="bokeauto-editor-modal" style="display:none;">
		<div class="bokeauto-modal-inner bokeauto-editor-inner">
			<h3 style="margin-top:0;">文件编辑器 <code id="bokeauto-editor-path" class="bokeauto-editor-path"></code></h3>
			<p class="description" style="margin-top:-6px;">直接修改内容后点「保存」写回文件；保存前自动备份原文件。</p>
			<textarea id="bokeauto-editor-content" class="bokeauto-editor-ta" spellcheck="false" placeholder="文件内容…"></textarea>
			<p class="bokeauto-editor-msg" id="bokeauto-editor-msg"></p>
			<p>
				<button type="button" class="button button-primary" id="bokeauto-editor-save">保存</button>
				<button type="button" class="button" id="bokeauto-editor-close">取消</button>
			</p>
		</div>
	</div>
</div>
