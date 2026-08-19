<?php
/**
 * 后台页面底部推广位：项目主页与开源信息
 *
 * @package Bokeauto
 */

defined( 'ABSPATH' ) || exit;

$bokeauto_home   = defined( 'BOKEAUTO_HOMEPAGE' ) ? BOKEAUTO_HOMEPAGE : '';
$bokeauto_issues = $bokeauto_home ? trailingslashit( $bokeauto_home ) . 'issues' : '';
?>
<div class="bokeauto-promo">
	<div class="bokeauto-promo__main">
		<span class="bokeauto-promo__badge">开源项目</span>
		<span class="bokeauto-promo__text">
			波克wpAI自动化插件 <strong>v<?php echo esc_html( BOKEAUTO_VERSION ); ?></strong>
			· 由 <strong>zhikanyeye</strong> 开发维护 · 免费开源（GPL-2.0-or-later）
		</span>
	</div>
	<?php if ( $bokeauto_home ) : ?>
		<div class="bokeauto-promo__links">
			<a class="bokeauto-promo__btn bokeauto-promo__btn--primary" href="<?php echo esc_url( $bokeauto_home ); ?>" target="_blank" rel="noopener noreferrer">
				访问项目主页 · 给个 Star
			</a>
			<a class="bokeauto-promo__btn" href="<?php echo esc_url( $bokeauto_issues ); ?>" target="_blank" rel="noopener noreferrer">
				反馈问题 / 提需求
			</a>
			<a class="bokeauto-promo__btn" href="<?php echo esc_url( trailingslashit( $bokeauto_home ) . 'releases' ); ?>" target="_blank" rel="noopener noreferrer">
				检查新版本
			</a>
		</div>
	<?php endif; ?>
</div>
