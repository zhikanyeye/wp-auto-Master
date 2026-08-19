<div class="wrap bokeauto-wrap">
	<h1 class="bokeauto-title">
		<span class="dashicons dashicons-superhero"></span>
		波克wpAI自动化插件 — 角色与技能
	</h1>

	<div class="notice notice-info">
		<p>
			<strong>能力角色</strong>：为智能体定义不同专业分工（职责、行为风格、工具权限），复杂任务可发起
			<strong>多角色协作</strong>（对话中说"用内容运营和 SEO 优化师协作处理…"即可）。
			<strong>技能库</strong>：Agent 会从成功任务中自动提炼新技能，也可被直接传授（对话中说"记住这个流程…"），技能越多越熟练。
		</p>
	</div>

	<h2 style="margin-top:24px;">能力角色</h2>
	<p>
		<button type="button" class="button button-primary" id="bokeauto-role-new">新建角色</button>
		<span class="description"> 内置角色不可删除，可停用；自定义角色可编辑/删除</span>
	</p>
	<table class="widefat striped" id="bokeauto-roles-table">
		<thead>
			<tr>
				<th>名称</th><th>职责</th><th>工具数</th><th>模型</th><th>类型</th><th>状态</th><th>操作</th>
			</tr>
		</thead>
		<tbody><tr><td colspan="7">加载中…</td></tr></tbody>
	</table>

	<h2 style="margin-top:32px;">技能库（Agent 自学习能力）</h2>
	<p class="description">技能由成功任务自动提炼（来源：自动学习），或由你口头传授（来源：手动）。停用后 Agent 不再采用该技能。</p>
	<table class="widefat striped" id="bokeauto-skills-table">
		<thead>
			<tr>
				<th>技能名称</th><th>说明</th><th>工具序列</th><th>来源</th><th>使用次数</th><th>状态</th><th>操作</th>
			</tr>
		</thead>
		<tbody><tr><td colspan="7">加载中…</td></tr></tbody>
	</table>

	<!-- 角色编辑弹窗 -->
	<div class="bokeauto-modal" id="bokeauto-role-modal" style="display:none;">
		<div class="bokeauto-modal-box bokeauto-role-modal-box">
			<div class="bokeauto-modal-head">
				<h3 id="bokeauto-role-modal-title">新建角色</h3>
				<button type="button" class="bokeauto-modal-close" title="关闭">×</button>
			</div>
			<div class="bokeauto-modal-body">
				<input type="hidden" id="bokeauto-role-id">
				<p><label>角色名称 *<br><input type="text" id="bokeauto-role-name" placeholder="如：客服专员"></label></p>
				<p><label>职责描述 *<br><textarea id="bokeauto-role-desc" rows="2" placeholder="这个角色负责什么、擅长什么"></textarea></label></p>
				<p><label>角色类型<br>
					<select id="bokeauto-role-type">
						<option value="chat">聊天型（对话 + 工具完成任务）</option>
						<option value="functional">功能性（绑定工具直接输出，不做对话）</option>
					</select>
					<span class="description">功能性角色如「生图助手」：AI 调用时直接执行绑定工具出结果，无需它对话</span>
				</label></p>
				<div id="bokeauto-role-bind-box" style="display:none;">
					<p><label>绑定输出工具（功能性角色）<br>
						<select id="bokeauto-role-bindtool"></select>
						<span class="description">AI 调用该角色时直接执行此工具，如生图助手 → generate_image</span>
					</label></p>
				</div>
				<p id="bokeauto-role-prompt-row"><label>行为风格（system prompt，可选）<br><textarea id="bokeauto-role-prompt" rows="3" placeholder="角色的语气、工作方式、注意事项等"></textarea></label></p>
				<p id="bokeauto-role-tools-row"><label>可用工具（留空 = 全部工具）<br>
					<select id="bokeauto-role-tools" multiple size="6"></select>
					<span class="description">按住 Ctrl/Cmd 多选</span>
				</label></p>
				<p id="bokeauto-role-ownllm-row"><label class="bokeauto-inline-check"><input type="checkbox" id="bokeauto-role-ownllm"> 为该角色单独配置模型/执行凭据</label>
					<span class="description">聊天型：对话用该模型；功能性：绑定工具调用用该 Key/模型，如生图助手配自己的智谱 Key</span>
				</p>
				<div class="bokeauto-subpanel" id="bokeauto-role-llm-box" style="display:none;">
					<p><label>服务商<br>
						<select id="bokeauto-role-llm-provider"></select>
					</label></p>
					<p><label>API 地址<br><input type="text" id="bokeauto-role-llm-baseurl"></label></p>
					<p><label>API Key<br><input type="password" id="bokeauto-role-llm-apikey" autocomplete="off"></label>
						<span class="bokeauto-subpanel-actions">
							<button type="button" class="button button-small" id="bokeauto-role-llm-test">测试连接</button>
							<span id="bokeauto-role-llm-result" class="bokeauto-test-result"></span>
						</span>
					</p>
					<p><label>模型名称<br><input type="text" id="bokeauto-role-llm-model" placeholder="如 glm-4.5-flash"></label></p>
				</div>
				<p><label class="bokeauto-inline-check"><input type="checkbox" id="bokeauto-role-status" checked> 启用该角色</label></p>
			</div>
			<div class="bokeauto-modal-foot">
				<button type="button" class="button button-primary" id="bokeauto-role-save">保存</button>
				<button type="button" class="button bokeauto-modal-close">取消</button>
			</div>
		</div>
	</div>
</div>
