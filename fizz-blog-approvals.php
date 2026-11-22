<?php
/**
 * Plugin Name: FIZZ Blog Approvals
 * Description: Emails the client when a blog is published (immediately or scheduled), lets them approve or request changes with no login, and notifies your team.
 * Version: 1.2.0
 * Author: FIZZ designs
 * Author URI: https://fizzdesigns.co.uk/
 * Plugin URI: https://fizzdesigns.co.uk/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Auto-updates from GitHub releases.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$ban_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/arhumb12/FIZZ-Blog-Approvals/',
	__FILE__,
	'fizz-blog-approvals'
);
$ban_updater->setBranch( 'main' );
$ban_updater->getVcsApi()->enableReleaseAssets();

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
		$posts  = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 200, 'meta_key' => '_ban_sent', 'orderby' => 'meta_value_num', 'order' => 'DESC' ] );
		$rows = []; $count = [ 'all' => 0, 'awaiting' => 0, 'approved' => 0, 'changes' => 0 ];
		foreach ( $posts as $p ) {
			$st = get_post_meta( $p->ID, '_ban_status', true ) ?: 'awaiting';
			$count['all']++; $count[ $st ]++;
			if ( $filter === 'all' || $filter === $st ) $rows[] = [ $p, $st ];
		}
		$base = admin_url( 'admin.php?page=ban-reviews' );
		echo '<div class="wrap"><h1 class="wp-heading-inline">Blog Approvals</h1> <a href="' . esc_url( admin_url( 'admin.php?page=ban-settings' ) ) . '" class="page-title-action">Settings</a><hr class="wp-header-end">';
		if ( isset( $_GET['resent'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Client email resent.</p></div>';
		if ( ! self::opt( 'client_email' ) ) echo '<div class="notice notice-warning"><p>No client email set yet. <a href="' . esc_url( admin_url( 'admin.php?page=ban-settings' ) ) . '">Configure settings</a> to start sending review emails.</p></div>';
		echo '<ul class="subsubsub">';
		$tabs = [ 'all' => 'All', 'awaiting' => '⏳ Awaiting', 'approved' => '✅ Approved', 'changes' => '✏️ Changes requested' ];
		$i = 0;
		foreach ( $tabs as $k => $l ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( add_query_arg( 'status', $k, $base ) ) . '"' . ( $filter === $k ? ' class="current"' : '' ) . '>' . esc_html( $l ) . ' <span class="count">(' . (int) $count[ $k ] . ')</span></a></li>';
		}
		echo '</ul><table class="wp-list-table widefat fixed striped" style="margin-top:12px"><thead><tr><th style="width:28%">Blog</th><th>Emailed</th><th>Status</th><th style="width:34%">Latest feedback</th><th>Actions</th></tr></thead><tbody>';
		if ( ! $rows ) echo '<tr><td colspan="5">No blogs here yet. Review emails are sent automatically when a new blog is published.</td></tr>';
		foreach ( $rows as [ $p, $st ] ) {
			$log = get_post_meta( $p->ID, '_ban_log', true ) ?: [];
			$last = $log ? end( $log ) : null;
			$badge = [ 'awaiting' => '⏳ Awaiting', 'approved' => '✅ Approved', 'changes' => '✏️ Changes requested' ][ $st ];
			echo '<tr><td><strong><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></strong></td>'
			. '<td>' . esc_html( wp_date( 'j M Y H:i', get_post_meta( $p->ID, '_ban_sent', true ) ) ) . '</td>'
			. '<td>' . $badge . ( $last ? '<br><small>' . esc_html( wp_date( 'j M H:i', $last['time'] ) ) . '</small>' : '' ) . '</td>'
			. '<td>' . ( $last && $last['feedback'] ? self::fb_html( $last['feedback'] ) : '—' ) . '</td>'
			. '<td><a href="' . esc_url( get_permalink( $p ) ) . '" target="_blank">View</a> | <a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">Edit</a> | <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ban_resend&post=' . $p->ID . '&back=ban' ), 'ban_resend' ) ) . '">Resend</a></td></tr>';
		}
		echo '</tbody></table></div>';
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

	static function email_fields( $g, $label ) {
		$extras = (array) self::opt( $g . '_extra', [] );
		$n = self::OPT . '[' . $g;
		echo '<tr><th>' . esc_html( $label ) . ' <span style="color:#d63638">*</span></th><td>'
		. '<input type="email" class="regular-text" required name="' . $n . '_email]" value="' . esc_attr( self::opt( $g . '_email' ) ) . '" placeholder="Primary email (required)">'
		. '<div class="ban-extra" data-name="' . $n . '_extra][]" style="margin-top:8px">';
		foreach ( $extras as $e ) {
			echo '<p class="ban-row"><input type="email" class="regular-text" name="' . $n . '_extra][]" value="' . esc_attr( $e ) . '"> <button type="button" class="button ban-del">Remove</button></p>';
		}
		echo '</div><button type="button" class="button ban-add" data-target="' . esc_attr( $g ) . '">+ Add another email (optional)</button></td></tr>';
	}

	static function settings_page() { ?>
		<div class="wrap"><h1>Blog Approval Settings</h1>
		<?php settings_errors( 'ban' ); ?>
		<form method="post" action="options.php"><?php settings_fields( 'ban' ); ?>
		<table class="form-table">
			<tr><th>Client name</th><td><input class="regular-text" name="<?php echo self::OPT; ?>[client_name]" value="<?php echo esc_attr( self::opt( 'client_name' ) ); ?>"></td></tr>
			<?php self::email_fields( 'client', 'Client email(s)' ); ?>
			<?php self::email_fields( 'team', 'Team email(s)' ); ?>
		</table>
		<h2>Branding</h2>
		<p class="description">Shown in the plugin emails and on the review page. Leave blank to use the defaults.</p>
		<table class="form-table">
			<?php foreach ( [ 'brand_name' => 'Company name', 'brand_logo' => 'Logo URL', 'brand_color' => 'Brand colour (hex, e.g. #1a1a1a)', 'brand_url' => 'Website URL', 'brand_footer' => 'Footer message' ] as $k => $l ) : ?>
			<tr><th><?php echo esc_html( $l ); ?></th><td><input class="regular-text" name="<?php echo self::OPT . '[' . $k . ']'; ?>" value="<?php echo esc_attr( self::brand( $k ) ); ?>"></td></tr>
			<?php endforeach; ?>
		</table>
		<p class="description">The primary email is required. Additional emails are optional and receive the same messages. Team emails receive approval/feedback alerts.</p>
		<?php submit_button(); ?></form></div>
		<script>
		document.querySelectorAll('.ban-add').forEach(function(b){
			b.addEventListener('click',function(){
				var box=b.previousElementSibling,p=document.createElement('p');
				p.className='ban-row';
				p.innerHTML='<input type="email" class="regular-text" name="'+box.dataset.name+'"> <button type="button" class="button ban-del">Remove</button>';
				box.appendChild(p);p.querySelector('input').focus();
			});
		});
		document.addEventListener('click',function(e){
			if(e.target.classList.contains('ban-del'))e.target.closest('.ban-row').remove();
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
