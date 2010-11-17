<?php 
/*
Plugin Name: Tumble
Description: Send your posts and media (images, audio, video) to Tumblr manually (just one click!)
Author: Scott Taylor
Version: 0.2
Author URI: http://tsunamiorigami.com
*/

define('TUMBLR_EMAIL', '');
define('TUMBLR_PASSWORD', '');
define('TUMBLR_UA', ''); /* not required */
define('TUMBLR_TO_TWITTER', 'no'); /* other choices are "auto" or (custom string for Twitter) */
define('TUMBLR_AUDIO_MAX_FILESIZE', 10000000); /* 10MB */
define('TUMBLR_PHOTO_MAX_FILESIZE', 10000000); /* 10MB */
define('TUMBLR_VIDEO_MAX_FILESIZE', 50000000); /* 50MB */

define('T_VIDEO_FILE_TOO_BIG', 6);
define('T_AUDIO_NOT_MP3', 5);
define('T_AUDIO_FILE_TOO_BIG', 4);
define('T_PHOTO_FILE_TOO_BIG', 3);
define('T_SUCCESS', 2);
define('T_WRONG_CREDENTIALS', 1);
define('T_ERROR', 0);

function tumble_local_url($file_url) {
	$parts = parse_url($file_url);
	return str_replace('/wp-admin', '', getcwd()) . $parts['path'];
}

function post_to_tumblr() {
	if (isset($_GET['post_id']) && (int) $_GET['post_id'] > 0) {
		$post = get_post($_GET['post_id'], OBJECT);	
		$code = 0;
	
		setup_postdata($post);	
		$required = array(
	        'email'     => TUMBLR_EMAIL,
	        'password'  => TUMBLR_PASSWORD,
	        'generator' => TUMBLR_UA,
	        'send-to-twitter' => TUMBLR_TO_TWITTER
		);
		
		if (isset($_GET['data_type']) && $_GET['data_type'] === 'media') { 
			$id = get_the_id();
			$mime = get_post_mime_type($id);
			$is_audio = strstr($mime, 'audio');
			$is_video = strstr($mime, 'video');
			$is_image = strstr($mime, 'image');
		
			if ($is_video) {
				$video_url = wp_get_attachment_url($id);
				if (filesize(tumble_local_url($video_url)) < TUMBLR_VIDEO_MAX_FILESIZE) {			
					$data = array(
						'type' => 'video',
						'data' => $video_url,
						'title' => get_the_title(),
						'caption' => get_the_content()
					);
				} else {
					$code = T_VIDEO_FILE_TOO_BIG;
				}							
			} elseif ($is_audio) {
				if ($mime === 'audio/mp3') {
					$audio_url = wp_get_attachment_url($id);
					if (filesize(tumble_local_url($audio_url)) < TUMBLR_AUDIO_MAX_FILESIZE) {			
						$data = array(
							'type' => 'audio',
							'data' => $audio_url,
							'caption' => get_the_content()
						);
					} else {
						$code = T_AUDIO_FILE_TOO_BIG;
					}	
				} else {
					$code = T_AUDIO_NOT_MP3;
				}					
			} elseif ($is_image) {
				$photo_url = wp_get_attachment_url($id);
				if (filesize(tumble_local_url($photo_url)) < TUMBLR_PHOTO_MAX_FILESIZE) {
					$data = array(
						'type' => 'photo',
						'source' => $photo_url,
						'caption' => get_the_content()
					);
				} else {
					$code = T_PHOTO_FILE_TOO_BIG;
				}					
			}
			if (isset($data) && is_array($data)) {
				$request_data = array_merge($data, $required);	
			}				
		} else {				
			$data = array(
		        'type' 	=> 'regular',
		        'title' => get_the_title(),
		        'body'  => get_the_content()				
			);
			$request_data = http_build_query(array_merge($data, $required));				
		}
		
		if (isset($request_data)) {
			// Send the POST request (with cURL)
			$c = curl_init('http://www.tumblr.com/api/write');
			curl_setopt($c, CURLOPT_POST, true);
			curl_setopt($c, CURLOPT_POSTFIELDS, $request_data);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			$result = curl_exec($c);
			$status = curl_getinfo($c, CURLINFO_HTTP_CODE);
			curl_close($c);
		}
		
		// Check for success
		if ($status == 201) {
			$code = T_SUCCESS;
		} else if ($status == 403) {
		   	$code = T_WRONG_CREDENTIALS;
		} else {
		    $code = T_ERROR;
		}			
	}
	
	$sendback = wp_get_referer();
	$sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
	$sendback = $sendback . (strstr($sendback, '?') ? '&' : '?') . 'tumble_message=' . $code;
	
	wp_redirect($sendback);
	exit(0);	
}

function tumble_columns($defaults) {
	$defaults['tumble'] = __('Tumblr');
	return $defaults;
}

function tumble_custom_column($column_name, $id) {
    if ($column_name === 'tumble') {    
		printf('<a href="admin.php?action=post_to_tumblr&amp;post_id=%d&amp;data_type=media">%s</a>', $id, __('Post'));
    }
}

function add_tumblr_link($actions, $post) {
    $actions = array_merge($actions, array(
        'post_to_tumblr' => 
        	sprintf('<a href="admin.php?action=post_to_tumblr&post_id=%d">%s</a>', $post->ID, __('Post to Tumblr'))
    ));
    
    return $actions;
}

function tumble_do_notice($strong = '', $message = '') {
	printf('<div id="tumble-warning" class="updated fade"><p><strong>%s</strong> %s</p></div>', $strong, $message);
}

function tumble_warning() {
	tumble_do_notice('Tumble Warning', 'Please add your Tumblr email and password to the top of <code>wp-plugins/tumble/tumble.php</code>');
}

function tumble_message() {
	switch ((int) $_GET['tumble_message']) {
	case T_VIDEO_FILE_TOO_BIG:
		tumble_do_notice('Tumble Error', 'Video file is too big to upload!');	
		break;
	case T_AUDIO_NOT_MP3:
		tumble_do_notice('Tumble Error', 'Audio file must be an MP3!');	
		break;
	case T_AUDIO_FILE_TOO_BIG:
		tumble_do_notice('Tumble Error', 'Audio file is too big to upload!');	
		break;
	case T_PHOTO_FILE_TOO_BIG:
		tumble_do_notice('Tumble Error', 'Photo is too big to upload!');	
		break;
	case T_SUCCESS:
		tumble_do_notice('Tumble', 'Post was successful!');	
		break;
	case T_WRONG_CREDENTIALS:
		tumble_do_notice('Tumble Warning', 'Wrong username or password :(');
		break;
	case T_ERROR:
		tumble_do_notice('Tumble Error', 'There was an error posting to Tumblr.');			
		break;				
	}
}

function tumble_init() {
	if (TUMBLR_EMAIL === '' || TUMBLR_PASSWORD === '') {
		add_action('admin_notices', 'tumble_warning');	
	} else {
		if (isset($_GET['tumble_message'])) {
			add_action('admin_notices', 'tumble_message');			
		}
	
		add_action('admin_action_post_to_tumblr', 'post_to_tumblr');	
		add_filter('post_row_actions', 'add_tumblr_link', 10, 2);
		add_filter('page_row_actions', 'add_tumblr_link', 10, 2);	
		add_filter('manage_media_columns', 'tumble_columns');
		add_action('manage_media_custom_column', 'tumble_custom_column', 10, 2);	
	}
}
add_action('init', 'tumble_init');
?>