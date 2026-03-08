<?php
/**
 * Search Gap Intelligence Teaser — Free tier static widget.
 *
 * Renders a hardcoded example of what Pro customers see.
 * 100% static — no real data, no API calls, no aggregation.
 * Free tier only gets this teaser with Pro upgrade CTA.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Search_Gaps class.
 */
class Shopwalk_WC_Search_Gaps {

	/**
	 * Render the static Search Gap Intelligence teaser widget.
	 *
	 * @return string HTML output.
	 */
	public static function render(): string {
		ob_start();
		?>
		<div class="shopwalk-gap-teaser" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 24px; margin: 20px 0;">
			<h3 style="margin-top: 0; margin-bottom: 4px;">🔍 Search Gap Intelligence <span class="shopwalk-pro-badge" style="display: inline-block; background: #1e5ba8; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 0.75em; font-weight: 600; margin-left: 8px;">Pro only</span></h3>
			<p style="margin: 8px 0 16px; color: #555;">See what your customers searched for and couldn't find.</p>
			<p style="margin: 0 0 16px; font-size: 0.9em; font-style: italic; color: #777;"><em>Example data only — upgrade to see YOUR store's gaps:</em></p>
			<table class="shopwalk-gap-table" style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
				<tbody>
					<tr style="border-bottom: 1px solid #eee;">
						<td style="padding: 12px 0; width: 60%;">"waterproof hiking boots"</td>
						<td style="padding: 12px 0; width: 20%; text-align: right;">23 searches</td>
						<td style="padding: 12px 0; width: 20%; text-align: right; color: #999;">2h ago</td>
					</tr>
					<tr style="border-bottom: 1px solid #eee;">
						<td style="padding: 12px 0;">"organic dog food"</td>
						<td style="padding: 12px 0; text-align: right;">17 searches</td>
						<td style="padding: 12px 0; text-align: right; color: #999;">4h ago</td>
					</tr>
					<tr>
						<td style="padding: 12px 0;">"xl standing desk"</td>
						<td style="padding: 12px 0; text-align: right;">11 searches</td>
						<td style="padding: 12px 0; text-align: right; color: #999;">Yesterday</td>
					</tr>
				</tbody>
			</table>
			<div class="shopwalk-upgrade-cta" style="background: #f0f7ff; border-left: 4px solid #1e5ba8; padding: 16px; border-radius: 4px; margin-top: 16px;">
				🚀 <strong>Upgrade to Pro</strong> — see what YOUR customers can't find. Turn gaps into revenue.
				<br><br>
				<a href="https://shopwalk.com/pro" class="button button-primary" style="display: inline-block; margin-top: 8px;">Upgrade to Pro</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
