<div class="wrap bokeauto-wrap">
	<div class="bokeauto-page-head bokeauto-workspace-head">
		<div class="bokeauto-workspace-title">
			<span class="bokeauto-workspace-mark dashicons dashicons-superhero"></span>
			<div>
				<h1>AI 工作台</h1>
				<p>管理站点、内容与代码任务</p>
			</div>
		</div>
		<div class="bokeauto-workspace-status">
			<span class="bokeauto-status-dot"></span>
			<span id="bokeauto-page-model-status">正在读取模型配置</span>
			<span class="bokeauto-ver">v<?php echo esc_html( BOKEAUTO_VERSION ); ?></span>
		</div>
	</div>

	<div class="bokeauto-chat-layout">
		<!-- 侧栏 -->
		<aside class="bokeauto-sidebar">
			<button type="button" class="button button-primary bokeauto-btn-new" id="bokeauto-new-chat"><span class="dashicons dashicons-plus-alt2"></span>新对话</button>

			<div class="bokeauto-conv-block">
				<div class="bokeauto-conv-head">
					<h3>会话记录</h3>
					<button type="button" class="bokeauto-btn-clear" id="bokeauto-btn-clear" title="清空聊天记录" aria-label="清空聊天记录"><span class="dashicons dashicons-trash"></span></button>
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
				<div class="bokeauto-model-bar__row">
					<span class="bokeauto-model-icon dashicons dashicons-admin-users" title="执行角色"></span>
					<select id="bokeauto-bar-role" title="切换当前对话角色（总调度可协调所有角色协作）"><option value="0">总调度（波克wpAI）</option></select>
					<span class="bokeauto-model-divider"></span>
					<span class="bokeauto-model-icon dashicons dashicons-cloud" title="模型服务"></span>
					<select id="bokeauto-bar-provider" title="切换模型服务商"></select>
					<select id="bokeauto-bar-model" title="切换模型"></select>
					<button type="button" class="button button-small" id="bokeauto-bar-switch"><span class="dashicons dashicons-update"></span>应用</button>
					<span class="model-bar-current" id="bokeauto-bar-current"></span>
				</div>
				<div class="bokeauto-model-bar__opts">
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
			</div>
			<div class="bokeauto-messages" id="bokeauto-messages">
				<div class="bokeauto-chat-empty">
					<span class="dashicons dashicons-superhero"></span>
					<h2>从一个站点任务开始</h2>
					<p>描述目标，波克wpAI会规划步骤并调用 WordPress 工具执行。</p>
					<div class="bokeauto-starter-list">
						<button type="button" data-prompt="检查网站最近发布的文章并给出内容优化建议">分析近期内容</button>
						<button type="button" data-prompt="帮我起草一篇新文章，先询问主题和目标读者">起草新文章</button>
						<button type="button" data-prompt="检查当前 WordPress 站点的基础运行状态">检查站点状态</button>
					</div>
				</div>
			</div>
			<div class="bokeauto-composer">
				<textarea id="bokeauto-input" rows="1" placeholder="<?php echo esc_attr( '例如：帮我发布一篇介绍网站的文章，并创建分类「新闻」…' ); ?>"></textarea>
				<button type="button" class="button button-primary" id="bokeauto-send"><span class="dashicons dashicons-arrow-up-alt2"></span><span>发送</span></button>
			</div>
		</main>
	</div>

	<!-- 可视化文件编辑器（AI 修改文件后出现的可编辑卡片） -->
	<div class="bokeauto-modal" id="bokeauto-editor-modal" style="display:none;">
		<div class="bokeauto-modal-box bokeauto-editor-inner">
			<div class="bokeauto-modal-head">
				<h3>文件编辑器</h3>
				<code id="bokeauto-editor-path" class="bokeauto-editor-path"></code>
				<button type="button" class="bokeauto-modal-close" id="bokeauto-editor-close-x" title="关闭">×</button>
			</div>
			<div class="bokeauto-modal-body">
				<p class="description">直接修改内容后点「保存」写回文件；保存前自动备份原文件。</p>
				<textarea id="bokeauto-editor-content" class="bokeauto-editor-ta" spellcheck="false" placeholder="文件内容…"></textarea>
				<p class="bokeauto-editor-msg" id="bokeauto-editor-msg"></p>
			</div>
			<div class="bokeauto-modal-foot">
				<button type="button" class="button button-primary" id="bokeauto-editor-save">保存</button>
				<button type="button" class="button" id="bokeauto-editor-close">取消</button>
			</div>
		</div>
	</div>
</div>
