<?php
/**
 * Plugin Name: FIZZ Blog Approvals
 * Description: Emails the client when a blog is published (immediately or scheduled), lets them approve or request changes with no login, and notifies your team.
 * Version: 1.2.5
 * Author: FIZZ designs
 * Author URI: https://fizzdesigns.co.uk/
 * Plugin URI: https://fizzdesigns.co.uk/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Auto-updates: reads update.json from the repo (no GitHub API, so no rate limits).
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$ban_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://raw.githubusercontent.com/arhumb12/FIZZ-Blog-Approvals/main/update.json',
	__FILE__,
	'fizz-blog-approvals'
);

class BAN_Plugin {
	const OPT = 'ban_settings';

	public static function init() {
		add_action( 'transition_post_status', [ __CLASS__, 'on_publish' ], 10, 3 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render_review' ], 1 );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_filter( 'manage_posts_columns', [ __CLASS__, 'col' ] );
		add_action( 'manage_posts_custom_column', [ __CLASS__, 'col_val' ], 10, 2 );
		add_action( 'admin_post_ban_resend', [ __CLASS__, 'resend' ] );
	}

	static function opt( $k, $d = '' ) {
		$o = get_option( self::OPT, [] );
		return ! empty( $o[ $k ] ) ? $o[ $k ] : $d;
	}

	static function brand( $k ) {
		$name = self::opt( 'brand_name', 'FIZZ designs' );
		$def  = [
			'brand_name'   => 'FIZZ designs',
			'brand_logo'   => plugins_url( 'assets/logo.png', __FILE__ ),
			'brand_color'  => '#1a1a1a',
			'brand_url'    => 'https://fizzdesigns.co.uk/',
			'brand_footer' => 'If you have any issues, please contact the ' . $name . ' team.',
		];
		return self::opt( $k, $def[ $k ] );
	}

	static function brand_header() {
		return '<div style="padding:14px 0;border-bottom:3px solid ' . esc_attr( self::brand( 'brand_color' ) ) . ';margin-bottom:20px"><a href="' . esc_url( self::brand( 'brand_url' ) ) . '"><img src="' . esc_url( self::brand( 'brand_logo' ) ) . '" alt="' . esc_attr( self::brand( 'brand_name' ) ) . '" style="max-height:60px;width:auto;border:0"></a></div>';
	}

	static function brand_footer() {
		return '<div style="margin-top:28px;padding-top:14px;border-top:1px solid #e3e3e3;color:#777;font-size:13px">' . esc_html( self::brand( 'brand_footer' ) )
		. '<br><a href="' . esc_url( self::brand( 'brand_url' ) ) . '" style="color:' . esc_attr( self::brand( 'brand_color' ) ) . '">' . esc_html( self::brand( 'brand_name' ) ) . '</a></div>';
	}

	/* ---------- Trigger ---------- */
	static function on_publish( $new, $old, $post ) {
		if ( $new !== 'publish' || $old === 'publish' ) return;
		if ( $post->post_type !== 'post' ) return;
		if ( get_post_meta( $post->ID, '_ban_sent', true ) ) return; // only once per post
		if ( ! self::opt( 'client_email' ) ) return;
		update_post_meta( $post->ID, '_ban_sent', time() );
		self::send_client_email( $post );
	}

	static function token( $post_id ) {
		$t = get_post_meta( $post_id, '_ban_token', true );
		if ( ! $t ) {
			$t = wp_generate_password( 32, false );
			update_post_meta( $post_id, '_ban_token', $t );
		}
		return $t;
	}

	static function review_url( $post_id ) {
		return add_query_arg( [ 'ban_review' => $post_id, 't' => self::token( $post_id ) ], home_url( '/' ) );
	}

	/* ---------- Emails ---------- */
	static function send( $to, $subject, $html, $reply = '' ) {
		$h = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( $reply ) $h[] = 'Reply-To: ' . $reply;
		return wp_mail( $to, $subject, $html, $h );
	}

	static function fb_html( $t ) {
		return $t === wp_strip_all_tags( $t ) ? nl2br( esc_html( $t ) ) : wp_kses_post( $t );
	}

	static function wrap( $body ) {
		return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#222;line-height:1.5">' . self::brand_header() . $body . self::brand_footer() . '</div>';
	}

	static function btn( $url, $label, $color ) {
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . $color . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;margin:4px 6px 4px 0">' . esc_html( $label ) . '</a>';
	}

	static function send_client_email( $post ) {
		$name  = self::opt( 'client_name', 'there' );
		$url   = get_permalink( $post );
		$rev   = self::review_url( $post->ID );
		$body  = '<p>Hi ' . esc_html( $name ) . ',</p>'
		. '<p>Your new blog has just been published:</p>'
		. '<p style="font-size:18px"><strong>' . esc_html( get_the_title( $post ) ) . '</strong></p>'
		. '<p>' . self::btn( $url, 'View your blog', '#444' ) . '</p>'
		. '<p>Please take a moment to review it:</p>'
		. '<p>' . self::btn( $rev . '#approve', '✅ Looks great!', '#2e9e4f' ) . self::btn( $rev . '#feedback', '✏️ Request changes', '#d9822b' ) . '</p>'
		. '<p style="color:#777;font-size:13px">No login needed. Your response goes straight to our team.</p>';
		self::send( self::recipients( 'client' ), 'Your new blog is live – please review: ' . get_the_title( $post ), self::wrap( $body ) );
	}

	static function send_team_email( $post, $status, $feedback ) {
		$team = self::recipients( 'team' ) ?: get_option( 'admin_email' );
		$icon = $status === 'approved' ? '✅ Approved' : '✏️ Changes requested';
		$body = '<p><strong>' . $icon . '</strong> by ' . esc_html( self::opt( 'client_name', 'client' ) ) . '</p>'
		. '<p>Blog: <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>'
		. ( $feedback ? '<p><strong>Feedback:</strong></p><blockquote style="border-left:3px solid #ccc;margin:0;padding-left:12px">' . self::fb_html( $feedback ) . '</blockquote>' : '' )
		. '<p>' . self::btn( get_edit_post_link( $post->ID, 'raw' ), 'Edit post', '#444' ) . '</p>';
		self::send( $team, $icon . ' – ' . get_the_title( $post ) . ' (' . get_bloginfo( 'name' ) . ')', self::wrap( $body ) );
	}

	/* ---------- Public review page ---------- */
	static function maybe_render_review() {
		if ( empty( $_REQUEST['ban_review'] ) ) return;
		$id   = absint( $_REQUEST['ban_review'] );
		$tok  = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		$post = get_post( $id );
		$real = $post ? get_post_meta( $id, '_ban_token', true ) : '';
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! $post || ! $real || ! hash_equals( $real, $tok ) ) {
			self::page( 'Link invalid', '<p>This review link is not valid or has expired.</p>' );
		}

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			$action   = isset( $_POST['ban_action'] ) ? sanitize_key( $_POST['ban_action'] ) : '';
			$allowed = [ 'p' => [ 'style' => true ], 'div' => [ 'style' => true ], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'h2' => [], 'h3' => [], 'a' => [ 'href' => true ] ];
			$feedback = trim( wp_kses( wp_unslash( $_POST['feedback'] ?? '' ), $allowed ) );
			if ( trim( wp_strip_all_tags( $feedback ) ) === '' ) $feedback = '';
			if ( $action === 'approve' ) {
				$status = 'approved'; $feedback = '';
			} elseif ( $action === 'changes' && $feedback !== '' ) {
				$status = 'changes';
			} else {
				self::page( 'Missing feedback', '<p>Please describe the changes you would like.</p><p><a href="' . esc_url( self::review_url( $id ) ) . '#feedback">Go back</a></p>' );
			}
			$log   = get_post_meta( $id, '_ban_log', true ) ?: [];
			$log[] = [ 'time' => time(), 'status' => $status, 'feedback' => $feedback ];
			update_post_meta( $id, '_ban_log', $log );
			update_post_meta( $id, '_ban_status', $status );
			self::send_team_email( $post, $status, $feedback );
			self::page( 'Thank you!', $status === 'approved'
				? '<p>Thanks for approving your blog. We\'re all done here. 🎉</p>'
				: '<p>Your feedback has been sent to our team. We\'ll make the changes and be in touch.</p>' );
		}

		$st  = get_post_meta( $id, '_ban_status', true );
		$msg = $st === 'approved' ? '<p style="background:#e6f6ec;padding:10px;border-radius:6px">You\'ve already approved this blog. You can still send more feedback below.</p>' : '';
		$f  = '<h2>' . esc_html( get_the_title( $post ) ) . '</h2>' . $msg
		. '<p><a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener">View the published blog ↗</a></p>'
		. '<form method="post" id="approve"><input type="hidden" name="ban_action" value="approve"><button style="background:#2e9e4f;color:#fff;border:0;padding:14px 24px;border-radius:6px;font-size:16px;cursor:pointer">✅ Looks great! No changes needed.</button></form>'
		. '<hr style="margin:28px 0"><form method="post" id="feedback"><input type="hidden" name="ban_action" value="changes">'
		. '<p><strong>Need changes?</strong> Tell us what to update:</p>'
		. '<div style="border:1px solid #bbb;border-radius:6px;overflow:hidden">'
		. '<div style="background:#f3f4f5;padding:6px;border-bottom:1px solid #ddd;display:flex;flex-wrap:wrap;gap:4px;align-items:center">'
		. '<select id="ban-block" style="padding:6px"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option></select>'
		. '<button type="button" data-c="bold" title="Bold"><b>B</b></button><button type="button" data-c="italic" title="Italic"><i>I</i></button>'
		. '<button type="button" data-c="insertUnorderedList" title="Bullet list">&bull; List</button><button type="button" data-c="insertOrderedList" title="Numbered list">1. List</button>'
		. '<button type="button" data-c="quote" title="Quote">&ldquo;</button>'
		. '<button type="button" data-c="justifyLeft" title="Align left">&#8676;</button><button type="button" data-c="justifyCenter" title="Align center">&#8596;</button><button type="button" data-c="justifyRight" title="Align right">&#8677;</button>'
		. '<button type="button" data-c="link" title="Insert link">&#128279; Link</button></div>'
		. '<div id="ban-editor" contenteditable="true" style="min-height:140px;padding:12px;outline:none"></div></div>'
		. '<input type="hidden" name="feedback" id="ban-fb">'
		. '<p><button style="background:#d9822b;color:#fff;border:0;padding:12px 22px;border-radius:6px;font-size:16px;cursor:pointer">Send feedback</button></p></form>'
		. '<style>#feedback button[data-c]{background:#fff;border:1px solid #ccc;border-radius:4px;padding:6px 10px;cursor:pointer}#ban-editor ul,#ban-editor ol{padding-left:24px}#ban-editor blockquote{border-left:3px solid #ccc;margin:8px 0;padding-left:12px;color:#555}</style>'
		. '<script>(function(){var ed=document.getElementById("ban-editor"),f=document.getElementById("feedback");'
		. 'f.querySelectorAll("button[data-c]").forEach(function(b){b.onmousedown=function(e){e.preventDefault();var c=b.dataset.c;ed.focus();'
		. 'if(c=="link"){var u=prompt("Link URL");if(u)document.execCommand("createLink",false,u);}'
		. 'else if(c=="quote"){document.execCommand("formatBlock",false,"blockquote");}'
		. 'else document.execCommand(c,false,null);};});'
		. 'document.getElementById("ban-block").onchange=function(){ed.focus();document.execCommand("formatBlock",false,this.value);};'
		. 'f.onsubmit=function(){if(!ed.innerText.trim()){alert("Please describe the changes you would like.");return false;}document.getElementById("ban-fb").value=ed.innerHTML;};})();</script>';
		self::page( 'Review your blog', $f );
	}

	static function page( $title, $html ) {
		status_header( 200 );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title></head>'
		. '<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:24px"><div style="max-width:600px;margin:auto;background:#fff;padding:28px;border-radius:10px;line-height:1.5">'
		. self::brand_header() . '<h1 style="font-size:22px;margin-top:0">' . esc_html( $title ) . '</h1>' . $html . self::brand_footer() . '</div></body></html>';
		exit;
	}

	/* ---------- Admin ---------- */
	static function menu() {
		add_menu_page( 'Blog Approvals', 'Blog Approvals', 'edit_posts', 'ban-reviews', [ __CLASS__, 'reviews_page' ], 'dashicons-yes-alt', 26 );
		add_submenu_page( 'ban-reviews', 'Reviews', 'Reviews', 'edit_posts', 'ban-reviews', [ __CLASS__, 'reviews_page' ] );
		add_submenu_page( 'ban-reviews', 'Settings', 'Settings', 'manage_options', 'ban-settings', [ __CLASS__, 'settings_page' ] );
	}

	static function reviews_page() {
		$filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per    = 15;
		$posts  = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 300, 'meta_key' => '_ban_sent', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true ] );
		$rows = []; $count = [ 'all' => 0, 'awaiting' => 0, 'approved' => 0, 'changes' => 0 ];
		foreach ( $posts as $p ) {
			$st = get_post_meta( $p->ID, '_ban_status', true ) ?: 'awaiting';
			$count['all']++; $count[ $st ]++;
			if ( $filter === 'all' || $filter === $st ) $rows[] = [ $p, $st ];
		}
		$total_rows = count( $rows );
		$rows = array_slice( $rows, ( $paged - 1 ) * $per, $per );
		$rate = $count['all'] ? round( $count['approved'] / $count['all'] * 100 ) : 0;
		$base = admin_url( 'admin.php?page=ban-reviews' );
		$c    = esc_attr( self::brand( 'brand_color' ) );
		$meta = [
			'awaiting' => [ '⏳ Awaiting', '#b45309', '#fef3c7' ],
			'approved' => [ '✅ Approved', '#15803d', '#dcfce7' ],
			'changes'  => [ '✏️ Changes requested', '#c2410c', '#ffedd5' ],
		];
		?>
		<style>
		.ban-wrap{max-width:1200px;margin:18px 20px 0 0;font-size:14px}
		.ban-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:linear-gradient(135deg,#111827,<?php echo $c; ?>);color:#fff;border-radius:14px;padding:22px 26px}
		.ban-hero h1{color:#fff;margin:0;font-size:24px;line-height:1.2}.ban-hero p{margin:4px 0 0;color:#cbd5e1}
		.ban-hero .ban-logo{background:#fff;border-radius:10px;padding:6px 10px;margin-right:16px}.ban-hero .ban-logo img{display:block;max-height:44px;width:auto}
		.ban-hero .ban-l{display:flex;align-items:center}
		.ban-btn{background:#fff;color:#111;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600}.ban-btn:hover{background:#f1f5f9;color:#111}
		.ban-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0}
		.ban-card{display:block;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;text-decoration:none;color:#111;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:box-shadow .15s,transform .15s}
		.ban-card:hover{box-shadow:0 6px 16px rgba(0,0,0,.08);transform:translateY(-1px);color:#111}
		.ban-card.on{border-color:<?php echo $c; ?>;box-shadow:0 0 0 2px <?php echo $c; ?>22}
		.ban-card small{display:block;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.04em;font-size:11px}.ban-card b{display:block;font-size:30px;line-height:1.2;margin-top:4px}
		.ban-panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.ban-row{display:grid;grid-template-columns:2.2fr 1.2fr 1.1fr 2.6fr 1fr;gap:14px;align-items:start;padding:14px 20px;border-top:1px solid #f1f1f4}
		.ban-row.h{background:#f9fafb;border-top:0;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
		.ban-row:not(.h):hover{background:#fafbff}
		.ban-title{font-weight:600;font-size:14px;text-decoration:none}.ban-sub{color:#6b7280;font-size:12px;margin-top:2px}
		.ban-pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
		.ban-modal{border:0;border-radius:14px;padding:0;width:min(640px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.35)}
		.ban-modal::backdrop{background:rgba(17,24,39,.55)}
		.ban-mh{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 22px;border-bottom:1px solid #eee;background:#f9fafb}
		.ban-x{background:none;border:0;font-size:26px;line-height:1;cursor:pointer;color:#6b7280}
		.ban-mb{padding:6px 22px 4px;max-height:60vh;overflow:auto}.ban-entry{padding:12px 0;border-bottom:1px solid #f1f1f4}.ban-entry:last-child{border:0}
		.ban-modal .full{background:#f9fafb;border-left:3px solid <?php echo $c; ?>;padding:10px 14px;border-radius:0 8px 8px 0;margin-top:6px;line-height:1.55}
		.ban-modal .full ul,.ban-modal .full ol{padding-left:22px}
		.ban-mf{padding:14px 22px;border-top:1px solid #eee;display:flex;gap:8px;justify-content:flex-end}
		.ban-btn2{background:<?php echo $c; ?>;color:#fff;text-decoration:none;padding:8px 16px;border-radius:8px;font-weight:600}.ban-btn2.alt{background:#fff;color:#111;border:1px solid #d1d5db}.ban-btn2:hover{opacity:.9;color:#fff}.ban-btn2.alt:hover{color:#111}
		.ban-act a{display:inline-block;margin:0 8px 4px 0;text-decoration:none;font-weight:600}
		.ban-empty{padding:48px 20px;text-align:center;color:#6b7280}
		.ban-pg{padding:14px 20px;border-top:1px solid #f1f1f4}.ban-pg .page-numbers{padding:5px 10px;border-radius:6px;text-decoration:none}.ban-pg .current{background:<?php echo $c; ?>;color:#fff}
		@media(max-width:900px){.ban-row{grid-template-columns:1fr}.ban-row.h{display:none}}
		</style>
		<div class="ban-wrap">
			<div class="ban-hero">
				<div class="ban-l"><div class="ban-logo"><img src="<?php echo esc_url( self::brand( 'brand_logo' ) ); ?>" alt=""></div>
					<div><h1>Client Approvals</h1><p>Track client reviews of your published blogs in one place.</p></div></div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="ban-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=ban-settings' ) ); ?>">⚙ Settings</a><?php endif; ?>
			</div>
			<?php if ( isset( $_GET['resent'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Client email resent.</p></div>'; ?>
			<?php if ( ! self::opt( 'client_email' ) ) echo '<div class="notice notice-warning"><p>No client email set yet. <a href="' . esc_url( admin_url( 'admin.php?page=ban-settings' ) ) . '">Configure settings</a> to start sending review emails.</p></div>'; ?>
			<div class="ban-stats">
				<?php
				$cards = [ 'all' => [ 'Total sent', $count['all'] ], 'awaiting' => [ 'Awaiting', $count['awaiting'] ], 'approved' => [ 'Approved', $count['approved'] ], 'changes' => [ 'Changes requested', $count['changes'] ] ];
				foreach ( $cards as $k => $v ) {
					echo '<a class="ban-card' . ( $filter === $k ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( 'status', $k, $base ) ) . '"><small>' . esc_html( $v[0] ) . '</small><b>' . (int) $v[1] . '</b></a>';
				}
				echo '<div class="ban-card"><small>Approval rate</small><b>' . (int) $rate . '%</b></div>';
				?>
			</div>
			<div class="ban-panel">
				<div class="ban-row h"><div>Blog</div><div>Emailed</div><div>Status</div><div>Latest feedback</div><div>Actions</div></div>
				<?php if ( ! $rows ) echo '<div class="ban-empty">Nothing here yet. Review emails are sent automatically when a new blog is published.</div>'; ?>
				<?php foreach ( $rows as [ $p, $st ] ) :
					$log  = get_post_meta( $p->ID, '_ban_log', true ) ?: [];
					$last = $log ? end( $log ) : null;
					[ $lab, $fg, $bg ] = $meta[ $st ];
					$sent = (int) get_post_meta( $p->ID, '_ban_sent', true );
					$fb   = $last['feedback'] ?? '';
					?>
					<div class="ban-row">
						<div><a class="ban-title" href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></div>
						<div><?php echo esc_html( human_time_diff( $sent ) ); ?> ago<div class="ban-sub"><?php echo esc_html( wp_date( 'j M Y, H:i', $sent ) ); ?></div></div>
						<div><span class="ban-pill" style="color:<?php echo $fg; ?>;background:<?php echo $bg; ?>"><?php echo $lab; ?></span><?php if ( $last ) echo '<div class="ban-sub">' . esc_html( wp_date( 'j M, H:i', $last['time'] ) ) . '</div>'; ?></div>
						<div><?php if ( $fb ) :
							$short = wp_trim_words( wp_strip_all_tags( $fb ), 16 );
							$more  = $short !== trim( wp_strip_all_tags( $fb ) ) || preg_match( '/<(ul|ol|blockquote|h2|h3|br|a)\b/i', $fb ) || count( $log ) > 1;
							echo esc_html( $short );
							if ( $more ) { echo ' <a href="#" class="ban-more" data-ban="ban-m-' . (int) $p->ID . '" style="color:' . $c . ';font-weight:600">Read more</a>'; }
						else : echo '<span class="ban-sub">—</span>'; endif; ?></div>
						<div class="ban-act">
							<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener">View</a>
							<a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>">Edit</a>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ban_resend&post=' . $p->ID . '&back=ban' ), 'ban_resend' ) ); ?>">Resend</a>
						</div>
					</div>
					<?php if ( $fb ) : ?>
					<dialog class="ban-modal" id="ban-m-<?php echo (int) $p->ID; ?>">
						<div class="ban-mh"><div><strong><?php echo esc_html( get_the_title( $p ) ); ?></strong><div class="ban-sub"><span class="ban-pill" style="color:<?php echo $fg; ?>;background:<?php echo $bg; ?>"><?php echo $lab; ?></span></div></div><button type="button" class="ban-x" aria-label="Close">&times;</button></div>
						<div class="ban-mb">
							<?php foreach ( array_reverse( $log ) as $l ) : if ( $l['status'] !== 'changes' ) { echo '<div class="ban-entry"><div class="ban-sub">' . esc_html( wp_date( 'j M Y, H:i', $l['time'] ) ) . '</div><p>✅ Approved</p></div>'; continue; } ?>
								<div class="ban-entry"><div class="ban-sub"><?php echo esc_html( wp_date( 'j M Y, H:i', $l['time'] ) ); ?> · Changes requested</div><div class="full"><?php echo self::fb_html( $l['feedback'] ); ?></div></div>
							<?php endforeach; ?>
						</div>
						<div class="ban-mf"><a class="ban-btn2" href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>">Edit post</a> <a class="ban-btn2 alt" href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener">View blog</a></div>
					</dialog>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( $total_rows > $per ) echo '<div class="ban-pg">' . paginate_links( [ 'base' => add_query_arg( 'paged', '%#%', add_query_arg( 'status', $filter, $base ) ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total_rows / $per ) ] ) . '</div>'; ?>
			</div>
		</div>
		<script>
		document.addEventListener('click',function(e){
			var t=e.target,o=t.closest&&t.closest('.ban-more');
			if(o){e.preventDefault();var d=document.getElementById(o.dataset.ban);if(d&&d.showModal)d.showModal();return;}
			if(t.classList&&t.classList.contains('ban-x')){t.closest('dialog').close();return;}
			if(t.tagName==='DIALOG')t.close();
		});
		</script>
		<?php
	}

	static function register() {
		register_setting( 'ban', self::OPT, [ 'sanitize_callback' => function ( $in ) {
			$old = get_option( self::OPT, [] );
			$out = [
				'client_name'  => sanitize_text_field( $in['client_name'] ?? '' ),
				'brand_name'   => sanitize_text_field( $in['brand_name'] ?? '' ),
				'brand_logo'   => esc_url_raw( $in['brand_logo'] ?? '' ),
				'brand_color'  => sanitize_hex_color( $in['brand_color'] ?? '' ) ?: '',
				'brand_url'    => esc_url_raw( $in['brand_url'] ?? '' ),
				'brand_footer' => sanitize_text_field( $in['brand_footer'] ?? '' ),
			];
			foreach ( [ 'client', 'team' ] as $g ) {
				$primary = sanitize_email( $in[ $g . '_email' ] ?? '' );
				if ( ! is_email( $primary ) ) {
					add_settings_error( 'ban', 'ban_' . $g, ucfirst( $g ) . ' primary email is required and must be valid. Previous value kept.' );
					$primary = $old[ $g . '_email' ] ?? '';
				}
				$extra = [];
				foreach ( (array) ( $in[ $g . '_extra' ] ?? [] ) as $e ) {
					$e = sanitize_email( $e );
					if ( is_email( $e ) && $e !== $primary ) $extra[] = $e;
				}
				$out[ $g . '_email' ] = $primary;
				$out[ $g . '_extra' ] = array_values( array_unique( $extra ) );
			}
			return $out;
		} ] );
	}

	static function recipients( $g ) {
		$list = array_merge( [ self::opt( $g . '_email' ) ], (array) self::opt( $g . '_extra', [] ) );
		return array_values( array_filter( $list, 'is_email' ) );
	}

	static function email_fields( $g, $label, $desc ) {
		$extras = (array) self::opt( $g . '_extra', [] );
		$n = self::OPT . '[' . $g;
		echo '<div class="bs-card"><h2>' . esc_html( $label ) . '</h2><p class="bs-d">' . esc_html( $desc ) . '</p>'
		. '<label class="bs-l">Primary email <span class="bs-req">required</span></label>'
		. '<input type="email" required name="' . $n . '_email]" value="' . esc_attr( self::opt( $g . '_email' ) ) . '" placeholder="name@example.com">'
		. '<label class="bs-l" style="margin-top:14px">Additional emails <span class="bs-opt">optional</span></label>'
		. '<div class="bs-extra" data-name="' . $n . '_extra][]">';
		foreach ( $extras as $e ) {
			echo '<div class="bs-erow"><input type="email" name="' . $n . '_extra][]" value="' . esc_attr( $e ) . '"><button type="button" class="bs-del" aria-label="Remove">&times;</button></div>';
		}
		echo '</div><button type="button" class="bs-add">+ Add another email</button></div>';
	}

	static function settings_page() {
		$c = esc_attr( self::brand( 'brand_color' ) );
		?>
		<style>
		.bs-wrap{max-width:900px;margin:18px 20px 0 0;font-size:14px}
		.bs-hero{display:flex;align-items:center;gap:16px;background:linear-gradient(135deg,#111827,<?php echo $c; ?>);color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:18px}
		.bs-hero h1{color:#fff;margin:0;font-size:24px;line-height:1.2}.bs-hero p{margin:4px 0 0;color:#cbd5e1}
		.bs-logo{background:#fff;border-radius:10px;padding:6px 10px}.bs-logo img{display:block;max-height:44px;width:auto}
		.bs-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.bs-card h2{margin:0 0 2px;font-size:16px}.bs-d{margin:0 0 14px;color:#6b7280}
		.bs-l{display:block;font-weight:600;margin-bottom:6px}.bs-req{color:#b91c1c;font-weight:600;font-size:11px;text-transform:uppercase;margin-left:6px}.bs-opt{color:#6b7280;font-weight:500;font-size:11px;text-transform:uppercase;margin-left:6px}
		.bs-card input[type=text],.bs-card input[type=email],.bs-card input[type=url]{width:100%;max-width:480px;height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;box-sizing:border-box}
		.bs-card input:focus{border-color:<?php echo $c; ?>;box-shadow:0 0 0 2px <?php echo $c; ?>22;outline:0}
		.bs-erow{display:flex;gap:6px;max-width:480px;margin-bottom:8px}.bs-erow input{flex:1}
		.bs-del{border:1px solid #d1d5db;background:#fff;border-radius:8px;width:40px;cursor:pointer;font-size:20px;color:#6b7280}.bs-del:hover{color:#b91c1c;border-color:#b91c1c}
		.bs-add{background:#f3f4f6;border:1px dashed #9ca3af;border-radius:8px;padding:8px 14px;cursor:pointer;font-weight:600}.bs-add:hover{background:#e5e7eb}
		.bs-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.bs-grid .full{grid-column:1/-1}
		.bs-card input[type=color]{width:56px;height:40px;padding:2px;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;background:#fff}
		.bs-prev{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;display:inline-block;margin-top:8px}.bs-prev img{display:block;max-height:40px;width:auto}
		.bs-save{position:sticky;bottom:0;background:linear-gradient(transparent,#f0f0f1 40%);padding:16px 0}
		.bs-save .button-primary{height:42px;padding:0 26px;border-radius:8px;font-weight:600;background:<?php echo $c; ?>;border-color:<?php echo $c; ?>}
		@media(max-width:782px){.bs-grid{grid-template-columns:1fr}}
		</style>
		<div class="bs-wrap">
			<div class="bs-hero"><div class="bs-logo"><img src="<?php echo esc_url( self::brand( 'brand_logo' ) ); ?>" alt=""></div><div><h1>Approval Settings</h1><p>Who gets the emails, and how they look.</p></div></div>
			<?php settings_errors(); ?>
			<form method="post" action="options.php"><?php settings_fields( 'ban' ); ?>
				<div class="bs-card"><h2>Client</h2><p class="bs-d">The client name appears in the review email greeting.</p>
					<label class="bs-l">Client name</label><input type="text" name="<?php echo self::OPT; ?>[client_name]" value="<?php echo esc_attr( self::opt( 'client_name' ) ); ?>"></div>
				<div class="bs-grid">
					<?php self::email_fields( 'client', 'Client emails', 'These people receive the "please review your blog" email.' ); ?>
					<?php self::email_fields( 'team', 'Team emails', 'These people are alerted when the client approves or sends feedback.' ); ?>
				</div>
				<div class="bs-card"><h2>Branding</h2><p class="bs-d">Shown in the emails, the review page and this dashboard. Leave blank to use the defaults.</p>
					<div class="bs-grid">
						<div><label class="bs-l">Company name</label><input type="text" name="<?php echo self::OPT; ?>[brand_name]" value="<?php echo esc_attr( self::brand( 'brand_name' ) ); ?>"></div>
						<div><label class="bs-l">Website URL</label><input type="url" name="<?php echo self::OPT; ?>[brand_url]" value="<?php echo esc_attr( self::brand( 'brand_url' ) ); ?>"></div>
						<div class="full"><label class="bs-l">Logo URL</label><input type="url" name="<?php echo self::OPT; ?>[brand_logo]" value="<?php echo esc_attr( self::brand( 'brand_logo' ) ); ?>" style="max-width:100%"><div class="bs-prev"><img src="<?php echo esc_url( self::brand( 'brand_logo' ) ); ?>" alt="Logo preview"></div></div>
						<div><label class="bs-l">Brand colour</label><input type="color" name="<?php echo self::OPT; ?>[brand_color]" value="<?php echo $c; ?>"></div>
						<div class="full"><label class="bs-l">Footer message</label><input type="text" name="<?php echo self::OPT; ?>[brand_footer]" value="<?php echo esc_attr( self::brand( 'brand_footer' ) ); ?>" style="max-width:100%"></div>
					</div>
				</div>
				<div class="bs-save"><?php submit_button( 'Save settings', 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<script>
		document.addEventListener('click',function(e){
			var t=e.target;
			if(t.classList.contains('bs-add')){var box=t.previousElementSibling,r=document.createElement('div');r.className='bs-erow';r.innerHTML='<input type="email" name="'+box.dataset.name+'"><button type="button" class="bs-del" aria-label="Remove">&times;</button>';box.appendChild(r);r.firstChild.focus();}
			if(t.classList.contains('bs-del'))t.closest('.bs-erow').remove();
		});
		</script>
	<?php }

	static function meta_box() {
		add_meta_box( 'ban_box', 'Client Approval', function ( $post ) {
			$sent = get_post_meta( $post->ID, '_ban_sent', true );
			$log  = get_post_meta( $post->ID, '_ban_log', true ) ?: [];
			echo '<p>' . ( $sent ? 'Client emailed ' . esc_html( wp_date( 'j M Y H:i', $sent ) ) : 'Not sent yet (sends on publish).' ) . '</p>';
			foreach ( array_reverse( $log ) as $l ) {
				echo '<p><strong>' . ( $l['status'] === 'approved' ? '✅ Approved' : '✏️ Changes' ) . '</strong> – ' . esc_html( wp_date( 'j M H:i', $l['time'] ) )
				. ( $l['feedback'] ? '<br>' . self::fb_html( $l['feedback'] ) : '' ) . '</p>';
			}
			if ( $post->post_status === 'publish' ) {
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ban_resend&post=' . $post->ID ), 'ban_resend' ) ) . '">Resend client email</a>';
			}
		}, 'post', 'side' );
	}

	static function resend() {
		check_admin_referer( 'ban_resend' );
		$post = get_post( absint( $_GET['post'] ?? 0 ) );
		if ( $post && current_user_can( 'edit_post', $post->ID ) ) {
			update_post_meta( $post->ID, '_ban_sent', time() );
			self::send_client_email( $post );
		}
		wp_safe_redirect( isset( $_GET['back'] ) ? admin_url( 'admin.php?page=ban-reviews&resent=1' ) : get_edit_post_link( $post->ID, 'raw' ) ); exit;
	}

	static function col( $c ) { $c['ban'] = 'Client review'; return $c; }
	static function col_val( $c, $id ) {
		if ( $c !== 'ban' ) return;
		$s = get_post_meta( $id, '_ban_status', true );
		echo $s === 'approved' ? '✅ Approved' : ( $s === 'changes' ? '✏️ Changes' : ( get_post_meta( $id, '_ban_sent', true ) ? '⏳ Awaiting' : '—' ) );
	}
}
BAN_Plugin::init();
