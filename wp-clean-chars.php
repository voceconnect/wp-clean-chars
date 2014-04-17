<?php
/*
Plugin Name: WP Clean Characters
Plugin URI: http://voceconnect.com
Description: Cleans invalid unicode characters from your posts
Version: 0.1.0
Author: Michael Pretty (prettyboymp)
*/

/*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


class WP_Clean_Characters {

	/**
	 * Initializes plugin, registers hooks
	 *
	 */
	public function initialize() {
		if( in_array( get_option( 'blog_charset' ), array( 'utf8', 'utf-8', 'UTF8', 'UTF-8' ) ) ) {
			add_filter('pre_post_content', array($this, 'filter_characters'));
			add_filter('pre_post_excerpt', array($this, 'filter_characters'));
			add_filter('pre_post_title', array($this, 'filter_characters'));

			add_action( 'admin_menu', array(&$this, 'add_admin_menu') );
			add_action( 'wp_ajax_clean_characters', array(&$this, 'ajax_clean_characters') );
		}
	}

	/**
	 * Callback or filtering content
	 *
	 * @param string $value
	 * @return string
	 */
	public function filter_characters( $value ) {
		$value = $this->convert_invalid_chars_to_entities($value);
		$value = $this->convert_to_untextured($value);
		return $value;
	}

	/**
	 * Converts characters to the untextured form.  This is somewhat the reverse
	 * of wptexturize
	 *
	 * @param string $text
	 * @return string
	 */
	private function convert_to_untextured( $text ) {
		$opening_quote = _x('&#8220;', 'opening curly quote');
		$closing_quote = _x('&#8221;', 'closing curly quote');

		$replacements = array(
			'&#8211;' => '-',
			'&#8212;' => '---',
			'&#8216;' => "'",
			'&#8217;' => "'",
			$opening_quote => '"',
			$closing_quote => '"',
			'&#8221;' => '"',
			'&#8220;' => '"',
			'&#8230;' => '...',
			'&#8242;' => '-',
			//' &#8482;' => ' (tm)',
		);

		$text = str_replace(array_keys($replacements), array_values($replacements), $text);

		return $text;
	}

	/**
	 * Function converts characters out of valid range into their UTF-8 entity equivalents
	 *
	 * @param string $text
	 * @return string
	 */
	private function convert_invalid_chars_to_entities( $text ) {
		$new_text = '';
		for($i = 0; $i < strlen($text); $i++) {
			$test = utf8_decode($text[$i]);
			if($test == '?' && ($ord = ord($text[$i])) !== 63) {
				if ($ord >= 0 && $ord <= 127) {
					$ud = $ord;
				} elseif ($ord >= 192 && $ord <= 223) {
					$ud = (($ord-192)*64) + (ord($text[$i + 1])-128);
					$i++;
				} elseif ($ord >= 224 && $ord <= 239) {
					$ud = (($ord-224)*4096) + (ord($text[$i + 1])-128)*64 + (ord($text[$i + 2])-128);
					$i+=2;
				} elseif ($ord >= 240 && $ord <= 247) {
					$ud = ($ord-240)*262144 + (ord($text[$i + 1])-128)*4096 + (ord($text[$i + 2])-128)*64 + (ord($c{3})-128);
					$i+=3;
				} elseif ($ord >= 248 && $ord <= 251) {
					$ud = ($ord-248)*16777216 + (ord($text[$i + 1])-128)*262144 + (ord($text[$i + 2])-128)*4096 + (ord($text[$i + 3])-128)*64 + (ord($text[$i + 4])-128);
					$i+=4;
				} elseif ($ord >= 252 && $ord <= 253) {
					$ud = ($ord-252)*1073741824 + (ord($text[$i + 1])-128)*16777216 + (ord($text[$i + 2])-128)*262144 + (ord($text[$i + 3])-128)*4096 + (ord($text[$i + 4])-128)*64 + (ord($text[$i + 5])-128);
					$i+=5;
				} elseif ($ord >= 254 && $ord <= 255) {
					$ud = false;
				}
				if($ud) {
					$char = "&#{$ud};";
				} else {
					$char = '';
				}
			} else {
				$char = $text[$i];
			}
			$new_text.= $char;
		}

		return $new_text;
	}

	/**
	 * Registers the admin menu for the bulk cleaning
	 *
	 */
	public function add_admin_menu() {
		$hook = add_management_page( __( 'Clean Invalid Characters', 'wp-clean-characters' ), __( 'Clean Characters', 'wp-clean-characters' ), 'manage_options', 'wp-clean-characters', array($this, 'clean_characters_page') );
		add_action( "load-$hook" , array(&$this, 'admin_enqueues') );
	}

	/**
	 * Enqueues the needed scripts for the bulk cleaning admin
	 *
	 */
	public function admin_enqueues() {
		wp_enqueue_script( 'jquery-ui-progressbar', plugins_url( 'jquery-ui/ui.progressbar.js', __FILE__ ), array('jquery-ui-core'), '1.7.2' );
		wp_enqueue_style( 'jquery-ui-regenthumbs', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );
	}

	/**
	 * Outputs the bulk cleaning admin page
	 *
	 */
	public function clean_characters_page() {
		global $wpdb;
		$valid_post_types = get_post_types(array('public'=>true));
		?>
		<div id="message" class="updated fade" style="display:none"></div>
		<div class="wrap">
			<h2><?php _e('Clean Invalid Characters', 'wp-clean-characters'); ?></h2>
			<?php
			// If the button was clicked
			if ( !empty($_POST['wp_clean_characters']) && !empty($_POST['cc_post_type']) ) {
				// Capability check
				if ( !current_user_can('manage_options') )
					wp_die( __('Cheatin&#8217; uh?') );

				// Form nonce check
				check_admin_referer( 'wp_clean_characters' );

				$checked_post_types = array_intersect($_POST['cc_post_type'], $valid_post_types);

				// Just query for the IDs only to reduce memory usage
				$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type in ('".join("','",$checked_post_types)."')");

				// Make sure there are images to process
				if ( empty($post_ids) ) {
					echo '	<p>' . __( "Unable to find any posts matching the selected post types.") . "</p>\n\n";
				}	else {
					echo '	<p>' . __( "Please be patient while all posts are filtered.", 'wp-clean-characters' ) . '</p>';
					// Generate the list of IDs
					$ids = implode( ',', $post_ids );
					$count = count( $post_ids );
					?>
					<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'wp-clean-characters' ) ?></em></p></noscript>

					<div id="cleancharsbar" style="position:relative;height:25px;">
						<div id="cleancharsbar-percent" style="position:absolute;left:50%;top:50%;width:50px;margin-left:-25px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
					</div>

					<script type="text/javascript">
					// <![CDATA[
						jQuery(document).ready(function($){
							var i;
							var rt_images = [<?php echo $ids; ?>];
							var rt_total = rt_images.length;
							var rt_count = 1;
							var rt_percent = 0;
							var cc_nonce = '<?php echo esc_js(wp_create_nonce('ajax_clean_chars')) ?>';

							$("#cleancharsbar").progressbar();
							$("#cleancharsbar-percent").html( "0%" );

							function CleanContent( id ) {
								$.post( "admin-ajax.php", { action: "clean_characters", id: id, cc_nonce:cc_nonce }, function() {
									rt_percent = ( rt_count / rt_total ) * 100;
									$("#cleancharsbar").progressbar( "value", rt_percent );
									$("#cleancharsbar-percent").html( Math.round(rt_percent) + "%" );
									rt_count = rt_count + 1;

									if ( rt_images.length ) {
										CleanContent( rt_images.shift() );
									} else {
										$("#message").html("<p><strong><?php echo esc_js( sprintf( __( 'All done! Processed %d posts.', 'wp-clean-characters' ), $count ) ); ?></strong></p>");
										$("#message").show();
									}
								});
							}

							CleanContent( rt_images.shift() );
						});
					// ]]>
					</script>
					<?php
				}
			}	else {
				?>
				<p><?php _e( "Use this tool to clean characters from all previously entered content.", 'wp-clean-characters'); ?></p>
				<p><?php _e( "This process is not reversible.  You should create a back up of your database before continuing.", 'wp-clean-characters'); ?></p>
				<p><?php _e( "Select the post types to process and then click the button below.", 'wp-clean-characters'); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field('wp_clean_characters') ?>
					<p>
						<?php foreach( $valid_post_types as $post_type ) : ?>
							<label for="comment_registration">
								<input type="checkbox" checked="checked" value="<?php echo esc_attr($post_type);?>" id="cc-pt-<?php echo esc_attr($post_type);?>" name="cc_post_type[]">
								<?php echo esc_html($post_type) ?>
							</label>
							<br />
						<?php endforeach; ?>
					</p>
					<p><input type="submit" class="button hide-if-no-js" name="wp_clean_characters" id="wp_clean_characters" value="<?php _e( 'Clean Old Content', 'wp-clean-characters' ) ?>" /></p>
					<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'wp-clean-characters' ) ?></em></p></noscript>
					</form>
				<?php
			} // End if button
			?>
		</div>
		<?php
	}

	/**
	 * Ajax handler for cleaning bulk posts
	 *
	 */
	public function ajax_clean_characters() {
		if ( !current_user_can( 'manage_options' ) ) {
			die('-1');
		}
		if( !wp_verify_nonce($_REQUEST['cc_nonce'], 'ajax_clean_chars') ) {
			die('-1');
		}

		$id = (int) $_REQUEST['id'];

		if ( empty($id) ) {
			die('-1');
		}

		$post = get_post($id, ARRAY_A);
		wp_insert_post($post);
		die('1');
	}
}
add_action('init', array(new WP_Clean_Characters(), 'initialize'));