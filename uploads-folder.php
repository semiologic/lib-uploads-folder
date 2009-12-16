<?php
/*
 * Uploads Folder
 * Author: Denis de Bernardy <http://www.mesoconcepts.com>
 * Version: 2.0.1 RC
 */

if ( !defined('sem_uploads_folder_debug') )
	define('sem_uploads_folder_debug', false);

/**
 * uploads_folder
 *
 * @package Uploads Folder
 **/

class uploads_folder {
	/**
	 * filter()
	 *
	 * @param array $uploads
	 * @param int $post_id
	 * @return array $uploads
	 **/

	function filter($uploads, $post_id = null) {
		if ( !$post_id ) {
			if ( in_the_loop() ) {
				$post_id = get_the_ID();
			} elseif ( is_singular() ) {
				global $wp_the_query;
				$post_id = $wp_the_query->get_queried_object_id();
			} elseif ( !empty($_POST['post_id']) ) {
				if ( $_POST['post_id'] < 0 )
					return $uploads;
				$post_id = $_POST['post_id'];
			} elseif ( !empty($_GET['post_id']) ) {
				if ( $_GET['post_id'] < 0 )
					return $uploads;
				$post_id = $_GET['post_id'];
			} else {
				return $uploads;
			}
		}
		
		if ( wp_is_post_revision($post_id) )
			return $uploads;
		
		$post = get_post($post_id);
		
		if ( !in_array($post->post_type, array('post', 'page')) )
			return $uploads;
		
		$subdir = get_post_meta($post_id, '_upload_dir', true);
		
		if ( $subdir && $uploads['subdir'] != "/$subdir" ) {
			if ( !wp_mkdir_p( $uploads['basedir'] . "/$subdir") )
				return $uploads;
			
			$uploads['subdir'] = "/$subdir";
			$uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
			$uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
		}
		
		return $uploads;
	} # filter()
	
	
	/**
	 * save_entry()
	 *
	 * @param int $post_id
	 * @return void
	 **/

	function save_entry($post_id) {
		if ( !$_POST || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) )
			return;
		
		$post = get_post($post_id);
		
	 	uploads_folder::set_upload_dir($post);
	} # save_entry()
	
	
	/**
	 * get_path()
	 *
	 * @return string $path
	 **/

	function get_path() {
		if ( defined('UPLOADS') )
			return ABSPATH . UPLOADS;
		
		$path = get_option('upload_path');
		$path = trim($upload_path);
		if ( !$path )
			$path = WP_CONTENT_DIR . '/uploads';
		
		// $path is (maybe) relative to ABSPATH
		$path = path_join(ABSPATH, $path);
		
		return $path;
	} # get_path()
	
	
	/**
	 * clean_path()
	 *
	 * @param string $path
	 * @return bool success
	 **/

	function clean_path($path) {
		if ( !is_dir($path) || !is_writable($path) )
			return false;
		
		$handle = @opendir($path);
		
		if ( !$handle )
			return;
		
		$rm = true;
		
		while ( ( $file = readdir($handle) ) !== false ) {
			if ( in_array($file, array('.', '..')) )
				continue;
			
			$rm &= uploads_folder::clean_path("$path/$file");
			
			if ( !$rm )
				break;
		}
		
		closedir($handle);
		
		return $rm && @rmdir($path);
	} # clean_path()
	
	
	/**
	 * set_upload_dir()
	 *
	 * @param object $post
	 * @return void
	 **/

	function set_upload_dir($post) {
		switch ( $post->post_type ) {
		case 'post':
			if ( !$post->post_name || !$post->post_date || defined('DOING_AJAX') )
				return;
			
			$subdir = date('Y/m/d/', strtotime($post->post_date)) . $post->post_name;
			break;
		case 'page':
			if ( !$post->post_name || defined('DOING_AJAX') )
				return;
			
			$subdir = $post->post_name;;
			
			$parent = $post;
			while ( $parent->post_parent && $parent->ID != $parent->post_parent ) {
				$parent = get_post($parent->post_parent);
				if ( !$parent->post_name )
					return;
				$subdir = $parent->post_name . '/' . $subdir;
			}
			break;
		default:
			return;
		}
		
		if ( $subdir == get_post_meta($post->ID, '_upload_dir', true) )
			return;
		
		if ( !sem_uploads_folder_debug )
			update_post_meta($post->ID, '_upload_dir', $subdir);
		
		$attachments = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type' => 'attachment',
				)
			);
		
		$old_paths = array();
		$new_paths = array();
		
		if ( $attachments ) {
			$upload_path = uploads_folder::get_path();
			$rel_upload_path = '/' . substr($upload_path, strlen(ABSPATH));
			
			if ( !wp_mkdir_p("$upload_path/$subdir") )
				return;
			
			global $wpdb;
			
			foreach ( array_keys($attachments) as $att_id ) {
				$file = get_post_meta($att_id, '_wp_attached_file', true);
				$meta = get_post_meta($att_id, '_wp_attachment_metadata', true);
				
				if ( !file_exists("$upload_path/$file") )
					continue;
				
				# fetch paths
				$old_path = dirname($file);
				$new_path = $subdir;
				
				# skip if path is unchanged
				if ( $new_path == $old_path )
					continue;
				
				# fetch files
				$files = array(0 => basename($file));
				if ( is_array($meta) && isset($meta['file']) ) {
					foreach ( (array) $meta['sizes'] as $size ) {
						$files[] = $size['file'];
					}
				}
				
				# check files
				$is_writable = true;
				$is_conflict = false;
				foreach ( $files as $file ) {
					$is_writable &= is_writable("$upload_path/$old_path/$file");
					$is_conflict |= file_exists("$upload_path/$new_path/$file");
				}
				
				if ( !$is_writable || $is_conflict )
					continue;
				
				# process files
				$update_db = false;
				$find = array();
				$repl = array();
				
				foreach ( $files as $key => $file ) {
					# move files
					@rename(
						"$upload_path/$old_path/$file",
						"$upload_path/$new_path/$file"
						);
					
					# update meta
					if ( $key === 0 ) {
						$old_paths[] = $old_path;
						update_post_meta($att_id, '_wp_attached_file', "$new_path/$file");
						if ( isset($meta['file']) ) {
							$meta['file'] = "$new_path/$file";
							update_post_meta($att_id, '_wp_attachment_metadata', $meta);
						}
						$wpdb->query("
							UPDATE	$wpdb->posts
							SET		guid = '" . $wpdb->escape(get_option('siteurl') . "$rel_upload_path/$new_path/$file") . "'
							WHERE	ID = " . intval($att_id)
							);
					}
					
					# edit post_content
					$find[] = ( ( $old_path != '.' )
						? "$rel_upload_path/$old_path/$file"
						: "$rel_upload_path/$file"
						);
					$repl[] = "$rel_upload_path/$new_path/$file";
				}
				
				foreach ( $find as $foo ) {
					$update_db |= strpos($post->post_excerpt, $foo) !== false
						|| strpos($post->post_content, $foo) !== false;
				}
				
				$post->post_excerpt = str_replace(
					$find,
					$repl,
					$post->post_excerpt);
				
				$post->post_content = str_replace(
					$find,
					$repl,
					$post->post_content);
				
				# update post
				if ( $update_db ) {
					$wpdb->query("
						UPDATE	$wpdb->posts
						SET		post_content = '" . $wpdb->escape($post->post_content) . "',
								post_excerpt = '" . $wpdb->escape($post->post_excerpt) . "'
						WHERE	ID = " . intval($post->ID)
						);
				}
				
				$where_sql = '';
				foreach ( $find as $file ) {
					if ( $where_sql )
						$where_sql .= ' OR ';
					$where_sql .= "post_content LIKE '%" . like_escape($wpdb->escape($file)) . "%'"
						. " OR post_excerpt LIKE '%" . like_escape($wpdb->escape($file)) . "%'";
				}
				
				$posts = $wpdb->get_results("
					SELECT	ID,
							post_content,
							post_excerpt
					FROM	$wpdb->posts
					WHERE	( $where_sql )
					AND		post_status <> 'inherit'
					AND		ID <> " . intval($post->ID)
					);
				
				foreach ( $posts as $extra ) {
					$extra->post_excerpt = str_replace(
						$find,
						$repl,
						$extra->post_excerpt);
					
					$extra->post_content = str_replace(
						$find,
						$repl,
						$extra->post_content);
					
					$wpdb->query("
						UPDATE	$wpdb->posts
						SET		post_content = '" . $wpdb->escape($extra->post_content) . "',
								post_excerpt = '" . $wpdb->escape($extra->post_excerpt) . "'
						WHERE	ID = " . intval($extra->ID)
						);
				}
			}
		}
		
		# process children
		if ( $post->post_type == 'page' ) {
			$children = get_children(
				array(
					'post_parent' => $post->ID,
					'post_type' => 'page',
					)
				);
			
			if ( $children ) {
				foreach ( $children as $child ) {
					uploads_folder::set_upload_dir($child);
				}
			}
		}
		
		# clean up
		$old_paths = array_unique($old_paths);
		$old_paths = array_diff($old_paths, array('.'));
		if ( $old_paths ) {
			foreach ( $old_paths as $old_path ) {
				uploads_folder::clean_path("$upload_path/$old_path");
			}
		}
	} # set_upload_dir()
	
	
	/**
	 * reset()
	 *
	 * @return void
	 **/

	function reset() {
		delete_post_meta_by_key('_upload_dir');
	} # reset()
} # uploads_folder

add_filter('upload_dir', array('uploads_folder', 'filter'));
add_filter('save_post', array('uploads_folder', 'save_entry'));
?>