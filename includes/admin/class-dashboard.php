<?php
/**
 * WP Admin Dashboard — three tools: UCP, Sync, License.
 * Adapts based on tier (unlicensed / free / pro).
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

final class Shopwalk_AI_Admin_Dashboard {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_shopwalk_self_test', array( $this, 'ajax_self_test' ) );
		add_action( 'wp_ajax_shopwalk_probe', array( $this, 'ajax_probe' ) );
		add_action( 'wp_ajax_shopwalk_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_shopwalk_test_license', array( $this, 'ajax_test_license' ) );
		add_action( 'wp_ajax_shopwalk_disconnect', array( $this, 'ajax_disconnect' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Shopwalk AI', 'shopwalk-ai' ),
			__( 'Shopwalk AI', 'shopwalk-ai' ),
			'manage_woocommerce',
			'shopwalk-ai',
			array( $this, 'render_page' ),
			'dashicons-share-alt2',
			58
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_shopwalk-ai' ) {
			return;
		}
		wp_register_script( 'shopwalk-ai-admin', '', array(), SHOPWALK_AI_VERSION, true );
		wp_enqueue_script( 'shopwalk-ai-admin' );
		wp_add_inline_script(
			'shopwalk-ai-admin',
			'window.swAdmin = ' . wp_json_encode( array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'self_test'    => wp_create_nonce( 'shopwalk_self_test' ),
					'probe'        => wp_create_nonce( 'shopwalk_probe' ),
					'activate'     => wp_create_nonce( 'shopwalk_activate' ),
					'test_license' => wp_create_nonce( 'shopwalk_test_license' ),
					'disconnect'   => wp_create_nonce( 'shopwalk_disconnect' ),
					'full_sync'    => wp_create_nonce( 'shopwalk_full_sync' ),
				),
			) ) . ';' . $this->admin_js()
		);
	}

	// ── Tier detection ─────────────────────────────────────────────────────

	private function get_tier(): string {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return 'unlicensed';
		}
		$key = Shopwalk_License::key();
		if ( $key === '' ) {
			return 'unlicensed';
		}
		$plan = get_option( 'shopwalk_plan', 'free' );
		return $plan === 'pro' ? 'pro' : 'free';
	}

	// ── Page render ────────────────────────────────────────────────────────

	public function render_page(): void {
		$tier = $this->get_tier();
		$tier_label = $tier === 'pro' ? 'Pro' : ( $tier === 'free' ? '' : '' );
		?>
		<div class="wrap sw-wrap">
			<h1>
				<?php esc_html_e( 'Shopwalk AI', 'shopwalk-ai' ); ?>
				<?php if ( $tier === 'free' || $tier === 'pro' ) : ?>
					<span class="sw-connected">✅ <?php esc_html_e( 'Connected', 'shopwalk-ai' ); ?></span>
				<?php endif; ?>
				<?php if ( $tier === 'pro' ) : ?>
					<span class="sw-badge sw-badge-pro">PRO</span>
				<?php elseif ( $tier === 'free' ) : ?>
					<span class="sw-badge sw-badge-free">FREE</span>
				<?php endif; ?>
			</h1>

			<?php $this->render_styles(); ?>
			<?php $this->render_ucp_tool( $tier ); ?>
			<?php if ( $tier !== 'unlicensed' ) : ?>
				<?php $this->render_sync_tool( $tier ); ?>
			<?php endif; ?>
			<?php $this->render_license_tool( $tier ); ?>
		</div>
		<?php
	}

	// ── UCP Tool ───────────────────────────────────────────────────────────

	private function render_ucp_tool( string $tier ): void {
		$product_count = wp_count_posts( 'product' )->publish ?? 0;
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'UCP Tool', 'shopwalk-ai' ); ?></h2>

			<div id="sw-ucp-results">
				<p class="sw-muted"><?php esc_html_e( 'Click "Test Connectivity" to check your UCP endpoints.', 'shopwalk-ai' ); ?></p>
			</div>

			<p class="sw-muted">
				<?php echo esc_html( sprintf( '%d products · Plugin v%s', $product_count, SHOPWALK_AI_VERSION ) ); ?>
			</p>

			<p>
				<button type="button" class="button button-primary" id="sw-probe-btn">
					<?php esc_html_e( 'Test Connectivity', 'shopwalk-ai' ); ?>
				</button>
				<button type="button" class="button" id="sw-self-test-btn">
					<?php esc_html_e( 'Local Self-Test', 'shopwalk-ai' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	// ── Sync Tool ──────────────────────────────────────────────────────────

	private function render_sync_tool( string $tier ): void {
		$product_count = wp_count_posts( 'product' )->publish ?? 0;
		$state         = (array) get_option( 'shopwalk_sync_state', array() );
		$queue         = (array) get_option( 'shopwalk_sync_queue', array() );
		$history       = (array) get_option( 'shopwalk_sync_history', array() );
		$last_sync     = ! empty( $state['completed_at'] ) ? human_time_diff( (int) $state['completed_at'] ) . ' ago' : 'Never';
		$last_products = $state['products'] ?? 0;
		$last_batches  = $state['batches'] ?? 0;
		$interval      = $tier === 'pro' ? '6 hours (Pro)' : '24 hours';
		$queue_count   = count( $queue );
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'Sync Tool', 'shopwalk-ai' ); ?></h2>

			<div class="sw-stats">
				<div class="sw-stat">
					<div class="sw-stat-value"><?php echo esc_html( number_format( $product_count ) ); ?></div>
					<div class="sw-stat-label"><?php esc_html_e( 'Total', 'shopwalk-ai' ); ?></div>
				</div>
				<div class="sw-stat">
					<div class="sw-stat-value"><?php echo esc_html( number_format( (int) $last_products ) ); ?></div>
					<div class="sw-stat-label"><?php esc_html_e( 'Synced', 'shopwalk-ai' ); ?></div>
				</div>
				<div class="sw-stat">
					<div class="sw-stat-value"><?php echo esc_html( $queue_count ); ?></div>
					<div class="sw-stat-label"><?php esc_html_e( 'Pending', 'shopwalk-ai' ); ?></div>
				</div>
			</div>

			<table class="sw-details">
				<tr><td><?php esc_html_e( 'Last sync', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( $last_sync ); ?></td></tr>
				<?php if ( $last_products > 0 ) : ?>
					<tr><td><?php esc_html_e( 'Last result', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( "$last_products products, $last_batches batches" ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'Sync interval', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( $interval ); ?></td></tr>
			</table>

			<div id="sw-sync-progress" style="display:none;">
				<div class="sw-progress-bar"><div class="sw-progress-fill" id="sw-progress-fill"></div></div>
				<p class="sw-muted" id="sw-sync-status-text"></p>
			</div>

			<p>
				<button type="button" class="button button-primary" id="shopwalk-sync-now">
					<?php esc_html_e( 'Sync Now', 'shopwalk-ai' ); ?>
				</button>
				<span class="sw-muted" id="sw-cooldown-text"></span>
			</p>

			<?php if ( ! empty( $history ) ) : ?>
				<h3><?php esc_html_e( 'Sync History', 'shopwalk-ai' ); ?></h3>
				<table class="sw-details">
					<?php foreach ( array_slice( $history, 0, 5 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, H:i', (int) ( $entry['timestamp'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( ( $entry['type'] ?? 'full' ) . ' · ' . ( $entry['total'] ?? 0 ) . ' products' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── License Tool ───────────────────────────────────────────────────────

	private function render_license_tool( string $tier ): void {
		$license_key = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::key() : '';
		$partner_id  = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::partner_id() : '';
		$plan        = get_option( 'shopwalk_plan', 'free' );
		$plan_label  = $plan === 'pro' ? get_option( 'shopwalk_plan_label', 'Pro' ) : 'Free';
		$next_bill   = get_option( 'shopwalk_next_billing', '' );
		?>
		<div class="sw-card">
			<h2>
				<?php esc_html_e( 'License Tool', 'shopwalk-ai' ); ?>
				<?php if ( $tier === 'pro' ) : ?>
					<span class="sw-badge sw-badge-pro">PRO</span>
				<?php elseif ( $tier === 'free' ) : ?>
					<span class="sw-badge sw-badge-free">FREE</span>
				<?php endif; ?>
			</h2>

			<?php if ( $tier === 'unlicensed' ) : ?>
				<p><?php esc_html_e( 'No license active. Enter a license key to connect to Shopwalk.', 'shopwalk-ai' ); ?></p>
				<p>
					<input type="text" id="sw-license-input" class="regular-text" placeholder="sw_site_..." value="" />
					<button type="button" class="button button-primary" id="sw-activate-btn">
						<?php esc_html_e( 'Activate', 'shopwalk-ai' ); ?>
					</button>
				</p>
				<p id="sw-activate-status"></p>
				<p>
					<a href="<?php echo esc_url( SHOPWALK_SIGNUP_URL ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( "Don't have one? Get a free license →", 'shopwalk-ai' ); ?>
					</a>
				</p>

			<?php else : ?>
				<table class="sw-details">
					<tr>
						<td><?php esc_html_e( 'License', 'shopwalk-ai' ); ?></td>
						<td>
							<code id="sw-license-display"><?php echo esc_html( $license_key ); ?></code>
							<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('sw-license-display').textContent)">
								<?php esc_html_e( 'Copy', 'shopwalk-ai' ); ?>
							</button>
						</td>
					</tr>
					<tr><td><?php esc_html_e( 'Partner ID', 'shopwalk-ai' ); ?></td><td><code><?php echo esc_html( $partner_id ); ?></code></td></tr>
					<tr><td><?php esc_html_e( 'Plan', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( $plan_label ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Status', 'shopwalk-ai' ); ?></td><td>✅ <?php esc_html_e( 'Active', 'shopwalk-ai' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Domain', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></td></tr>
					<?php if ( $tier === 'pro' && $next_bill ) : ?>
						<tr><td><?php esc_html_e( 'Next billing', 'shopwalk-ai' ); ?></td><td><?php echo esc_html( $next_bill ); ?></td></tr>
					<?php endif; ?>
				</table>

				<p>
					<button type="button" class="button" id="sw-test-license-btn">
						<?php esc_html_e( 'Test License', 'shopwalk-ai' ); ?>
					</button>
					<span class="sw-muted" id="sw-test-license-result"></span>
				</p>

				<h3><?php esc_html_e( 'Update License', 'shopwalk-ai' ); ?></h3>
				<p>
					<input type="text" id="sw-license-input" class="regular-text" placeholder="sw_site_..." value="" />
					<button type="button" class="button" id="sw-activate-btn">
						<?php esc_html_e( 'Update', 'shopwalk-ai' ); ?>
					</button>
				</p>
				<p id="sw-activate-status"></p>

				<?php if ( $tier === 'free' ) : ?>
					<div class="sw-upgrade-cta">
						<p><strong>⬆️ <?php esc_html_e( 'Upgrade to Pro', 'shopwalk-ai' ); ?></strong></p>
						<p class="sw-muted"><?php esc_html_e( '$19/mo annual · $29/mo monthly — Analytics, brand voice, knowledge base, gap analysis', 'shopwalk-ai' ); ?></p>
						<p>
							<a href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/subscribe' ); ?>" class="button button-primary" target="_blank" rel="noopener">
								<?php esc_html_e( 'Upgrade to Pro →', 'shopwalk-ai' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<p>
					<a href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" class="button" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open Partner Portal →', 'shopwalk-ai' ); ?>
					</a>
					<a href="#" id="sw-disconnect-btn" class="sw-disconnect-link">
						<?php esc_html_e( 'Disconnect', 'shopwalk-ai' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Styles ─────────────────────────────────────────────────────────────

	private function render_styles(): void {
		?>
		<style>
			.sw-wrap { max-width: 760px; }
			.sw-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px 24px; margin-bottom: 20px; }
			.sw-card h2 { margin-top: 0; display: flex; align-items: center; gap: 8px; }
			.sw-card h3 { margin-bottom: 8px; }
			.sw-connected { color: #065f46; font-size: 14px; font-weight: 400; }
			.sw-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; }
			.sw-badge-pro { background: #11CF52; color: #fff; }
			.sw-badge-free { background: #e5e7eb; color: #374151; }
			.sw-muted { color: #6b7280; font-size: 13px; }
			.sw-stats { display: flex; gap: 16px; margin-bottom: 16px; }
			.sw-stat { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center; min-width: 80px; }
			.sw-stat-value { font-size: 24px; font-weight: 700; color: #111; }
			.sw-stat-label { font-size: 12px; color: #6b7280; margin-top: 2px; }
			.sw-details { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
			.sw-details td { padding: 6px 12px 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
			.sw-details td:first-child { color: #6b7280; white-space: nowrap; width: 120px; }
			.sw-progress-bar { background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; margin-bottom: 8px; }
			.sw-progress-fill { background: #11CF52; height: 100%; width: 0%; transition: width 0.3s; border-radius: 6px; }
			.sw-upgrade-cta { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 16px 0; }
			.sw-disconnect-link { color: #991b1b; text-decoration: none; margin-left: 12px; font-size: 13px; }
			.sw-disconnect-link:hover { text-decoration: underline; }
			.sw-check-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
			.sw-check-row:last-child { border-bottom: 0; }
		</style>
		<?php
	}

	// ── JS ──────────────────────────────────────────────────────────────────

	private function admin_js(): string {
		return <<<'JS'
(function () {
	var s = window.swAdmin;
	if (!s) return;

	function $(id) { return document.getElementById(id); }

	function postAjax(action, body) {
		var data = new URLSearchParams();
		data.append('action', action);
		Object.keys(body || {}).forEach(function (k) { data.append(k, body[k]); });
		return fetch(s.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (r) { return r.json(); });
	}

	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	// ── Probe (Test Connectivity via Shopwalk) ──────────────────────────
	var probeBtn = $('sw-probe-btn');
	if (probeBtn) {
		probeBtn.addEventListener('click', function () {
			var out = $('sw-ucp-results');
			probeBtn.disabled = true;
			probeBtn.textContent = 'Testing…';
			out.innerHTML = '<p class="sw-muted">Connecting to Shopwalk servers…</p>';

			postAjax('shopwalk_probe', { nonce: s.nonces.probe }).then(function (resp) {
				probeBtn.disabled = false;
				probeBtn.textContent = 'Test Connectivity';
				if (!resp || !resp.success) {
					out.innerHTML = '<p style="color:#991b1b;">Probe failed: ' + esc(resp && resp.data && resp.data.message || 'unknown') + '</p>';
					return;
				}
				var d = resp.data;
				var html = '';

				// Endpoints
				html += '<strong>UCP Endpoints</strong>';
				html += '<div class="sw-check-row"><span>' + (d.reachable ? '✅' : '❌') + ' Store Info — /ucp/store</span><span class="sw-muted">' + (d.latency_ms || '') + 'ms</span></div>';
				html += '<div class="sw-check-row"><span>' + (d.products_ok ? '✅' : '❌') + ' Products — /ucp/products</span><span class="sw-muted">' + (d.products_issue || '') + '</span></div>';
				html += '<div class="sw-check-row"><span>' + (d.discovery_ok ? '✅' : '❌') + ' Discovery — /.well-known/ucp</span><span class="sw-muted">' + (d.discovery_issue || '') + '</span></div>';

				// Store info
				if (d.product_count) {
					html += '<p class="sw-muted">' + d.product_count + ' products · ' + (d.in_stock_count || 0) + ' in stock · ' + (d.currency || 'USD') + '</p>';
				}

				// Connectivity
				html += '<br><strong>Connectivity</strong>';
				if (d.reachable) {
					html += '<div class="sw-check-row"><span>✅ Reachable from Shopwalk</span><span class="sw-muted">' + d.latency_ms + 'ms</span></div>';
				} else {
					html += '<div class="sw-check-row"><span>❌ ' + esc(d.reason || 'Unreachable') + '</span></div>';
					html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;margin:8px 0;font-size:13px;">';
					html += '<strong>⚠️ Shopwalk cannot reach your store.</strong><br>';
					html += 'Common fixes:<br>';
					html += '• Whitelist Shopwalk IP: 15.204.101.254<br>';
					html += '• Check .htaccess for blocking rules<br>';
					html += '• Ensure permalinks are not set to "Plain"<br><br>';
					html += '<a href="mailto:support@shopwalk.com?subject=UCP%20connectivity%20issue&body=Store:%20' + encodeURIComponent(location.origin) + '" class="button">Contact Support</a>';
					html += '</div>';
				}
				if (d.host_name) {
					html += '<div class="sw-check-row"><span>ℹ️ Hosting: ' + esc(d.host_name) + '</span></div>';
				}
				if (d.ucp_version) {
					html += '<p class="sw-muted">UCP v' + esc(d.ucp_version) + (d.plugin_version ? ' · Plugin v' + esc(d.plugin_version) : '') + '</p>';
				}

				out.innerHTML = html;
			});
		});
	}

	// ── Self-test (local) ───────────────────────────────────────────────
	var selfTestBtn = $('sw-self-test-btn');
	if (selfTestBtn) {
		selfTestBtn.addEventListener('click', function () {
			var out = $('sw-ucp-results');
			selfTestBtn.disabled = true;
			selfTestBtn.textContent = 'Testing…';
			postAjax('shopwalk_self_test', { nonce: s.nonces.self_test }).then(function (resp) {
				selfTestBtn.disabled = false;
				selfTestBtn.textContent = 'Local Self-Test';
				if (!resp || !resp.success) {
					out.innerHTML = '<p style="color:#991b1b;">Self-test failed</p>';
					return;
				}
				var html = '<strong>Local Self-Test</strong>';
				(resp.data.checks || []).forEach(function (c) {
					var icon = c.status === 'pass' ? '✅' : c.status === 'warn' ? '⚠️' : '❌';
					html += '<div class="sw-check-row"><span>' + icon + ' ' + esc(c.check) + '</span><span class="sw-muted">' + esc(c.message) + '</span></div>';
				});
				out.innerHTML = html;
			});
		});
	}

	// ── Activate / Update License ───────────────────────────────────────
	var activateBtn = $('sw-activate-btn');
	if (activateBtn) {
		activateBtn.addEventListener('click', function () {
			var input = $('sw-license-input');
			var status = $('sw-activate-status');
			if (!input || !input.value.trim()) {
				status.innerHTML = '<span style="color:#991b1b;">License key is required.</span>';
				return;
			}
			activateBtn.disabled = true;
			status.innerHTML = '<span class="sw-muted">Validating…</span>';

			postAjax('shopwalk_activate', { nonce: s.nonces.activate, license_key: input.value.trim() }).then(function (resp) {
				activateBtn.disabled = false;
				if (resp && resp.success) {
					status.innerHTML = '<span style="color:#065f46;">✅ ' + esc(resp.data.message || 'License activated') + '</span>';
					setTimeout(function () { window.location.reload(); }, 1000);
				} else {
					var current = $('sw-license-display');
					var msg = (resp && resp.data && resp.data.message) || 'Activation failed.';
					if (current) {
						msg += ' Your current license is unchanged.';
					}
					status.innerHTML = '<span style="color:#991b1b;">❌ ' + esc(msg) + '</span>';
				}
			});
		});
	}

	// ── Test License ────────────────────────────────────────────────────
	var testBtn = $('sw-test-license-btn');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var result = $('sw-test-license-result');
			testBtn.disabled = true;
			result.textContent = 'Checking…';
			postAjax('shopwalk_test_license', { nonce: s.nonces.test_license }).then(function (resp) {
				testBtn.disabled = false;
				if (resp && resp.success && resp.data.valid) {
					result.innerHTML = '<span style="color:#065f46;">✅ Valid · ' + esc(resp.data.plan || 'Free') + ' plan</span>';
				} else {
					result.innerHTML = '<span style="color:#991b1b;">❌ ' + esc(resp && resp.data && resp.data.message || 'Validation failed') + '</span>';
				}
			});
		});
	}

	// ── Disconnect ──────────────────────────────────────────────────────
	var disconnectBtn = $('sw-disconnect-btn');
	if (disconnectBtn) {
		disconnectBtn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('Disconnect from Shopwalk? Your products will no longer be synced. UCP endpoints continue working independently.')) return;
			postAjax('shopwalk_disconnect', { nonce: s.nonces.disconnect }).then(function (resp) {
				if (resp && resp.success) window.location.reload();
				else alert((resp && resp.data && resp.data.message) || 'Disconnect failed.');
			});
		});
	}

	// ── Sync Now ────────────────────────────────────────────────────────
	var syncBtn = $('shopwalk-sync-now');
	if (syncBtn) {
		syncBtn.addEventListener('click', function () {
			if (syncBtn.disabled) return;
			syncBtn.disabled = true;
			syncBtn.textContent = 'Syncing…';

			postAjax('shopwalk_full_sync', { nonce: s.nonces.full_sync }).then(function (resp) {
				if (resp && resp.success) {
					syncBtn.textContent = (resp.data && resp.data.message) || 'Done!';
					setTimeout(function () {
						syncBtn.disabled = false;
						syncBtn.textContent = 'Sync Now';
					}, 3000);
				} else {
					syncBtn.disabled = false;
					syncBtn.textContent = 'Sync Now';
					var msg = resp && resp.data && resp.data.message || 'Sync failed.';
					alert(msg);
				}
			});
		});
	}
})();
JS;
	}

	// ── AJAX Handlers ──────────────────────────────────────────────────────

	public function ajax_self_test(): void {
		check_ajax_referer( 'shopwalk_self_test', 'nonce' );
		$checks = array();
		$base   = home_url();

		$endpoints = array(
			'Store Info'  => '/wp-json/ucp/v1/store',
			'Products'    => '/wp-json/ucp/v1/products?per_page=1',
			'Categories'  => '/wp-json/ucp/v1/categories',
			'Discovery'   => '/.well-known/ucp',
		);

		foreach ( $endpoints as $name => $path ) {
			$start = microtime( true );
			$resp  = wp_remote_get( $base . $path, array( 'timeout' => 5, 'sslverify' => false ) );
			$ms    = round( ( microtime( true ) - $start ) * 1000 );
			$code  = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );

			$checks[] = array(
				'check'   => $name,
				'status'  => $code === 200 ? 'pass' : 'fail',
				'message' => $code === 200 ? $ms . 'ms' : 'HTTP ' . $code,
			);
		}

		wp_send_json_success( array( 'checks' => $checks ) );
	}

	public function ajax_probe(): void {
		check_ajax_referer( 'shopwalk_probe', 'nonce' );

		$resp = wp_remote_post(
			'https://api.shopwalk.com/api/v1/public/ucp/probe',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json', 'User-Agent' => 'shopwalk-ai-plugin/' . SHOPWALK_AI_VERSION ),
				'body'    => wp_json_encode( array( 'store_url' => home_url() ) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => 'Could not reach Shopwalk API: ' . $resp->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		wp_send_json_success( $body ?: array() );
	}

	public function ajax_activate(): void {
		check_ajax_referer( 'shopwalk_activate', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$new_key = sanitize_text_field( $_POST['license_key'] ?? '' );
		if ( ! $new_key ) {
			wp_send_json_error( array( 'message' => 'License key is required.' ) );
		}

		// Validate the new key BEFORE replacing the old one
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-license.php';
		}

		$result = Shopwalk_License::activate( $new_key );
		if ( $result['ok'] ?? false ) {
			// Refresh plan from API response
			$plan = $result['plan'] ?? 'free';
			update_option( 'shopwalk_plan', $plan );
			if ( ! empty( $result['plan_label'] ) ) {
				update_option( 'shopwalk_plan_label', $result['plan_label'] );
			}
			if ( ! empty( $result['next_billing_date'] ) ) {
				update_option( 'shopwalk_next_billing', $result['next_billing_date'] );
			}
			wp_send_json_success( array( 'message' => 'License activated. Plan: ' . ucfirst( $plan ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Activation failed.' ) );
		}
	}

	public function ajax_test_license(): void {
		check_ajax_referer( 'shopwalk_test_license', 'nonce' );

		if ( ! class_exists( 'Shopwalk_License' ) ) {
			wp_send_json_error( array( 'message' => 'License module not loaded.' ) );
		}

		$key = Shopwalk_License::key();
		if ( ! $key ) {
			wp_send_json_error( array( 'message' => 'No license key configured.' ) );
		}

		$result = Shopwalk_License::activate( $key );
		$valid  = $result['ok'] ?? false;
		$plan   = $result['plan'] ?? 'free';

		// Update cached plan
		if ( $valid ) {
			update_option( 'shopwalk_plan', $plan );
			if ( ! empty( $result['plan_label'] ) ) {
				update_option( 'shopwalk_plan_label', $result['plan_label'] );
			}
			if ( ! empty( $result['next_billing_date'] ) ) {
				update_option( 'shopwalk_next_billing', $result['next_billing_date'] );
			}
		}

		wp_send_json_success( array(
			'valid'   => $valid,
			'plan'    => $plan,
			'message' => $valid ? 'License is valid' : ( $result['message'] ?? 'Validation failed' ),
		) );
	}

	public function ajax_disconnect(): void {
		check_ajax_referer( 'shopwalk_disconnect', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		if ( class_exists( 'Shopwalk_License' ) ) {
			Shopwalk_License::deactivate();
		}

		delete_option( 'shopwalk_plan' );
		delete_option( 'shopwalk_plan_label' );
		delete_option( 'shopwalk_next_billing' );
		delete_option( 'shopwalk_sync_state' );
		delete_option( 'shopwalk_sync_history' );

		wp_send_json_success( array( 'message' => 'Disconnected from Shopwalk.' ) );
	}
}
