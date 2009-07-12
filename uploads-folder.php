<?php
/*
 * Uploads Folder
 * Author: Denis de Bernardy <http://www.mesoconcepts.com>
 * Version: 2.0
 */


/**
 * uploads_folder
 *
 * @package Uploads Folder
 **/

add_filter('upload_dir', array('uploads_folder', 'filter'));
add_filter('save_post', array('uploads_folder', 'save_entry'));

class uploads_folder {
	/**
	 * filter()
	 *
	 * @param array $uploads
	 * @return array $uploads
	 **/

	function filter($uploads) {
		if ( !isset($_POST['post_id']) || $_POST['post_id'] <= 0 ) {
			return $uploads;
		} else {
			$post_id = $_POST['post_id'];
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
		if ( wp_is_post_revision($post_id) )
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
		if ( defined('UPLOADS') ) {
			return ABSPATH . UPLOADS;
		}
		
		$path = get_option( 'upload_path' );
		$path = trim($upload_path);
		if ( !$path )
			$path = WP_CONTENT_DIR . '/uploads';
		
		// $path is (maybe) relative to ABSPATH
		$path = path_join( ABSPATH, $path );
		
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
			while ( $parent->post_parent != 0 ) {
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
		
		update_post_meta($post->ID, '_upload_dir', $subdir);
		
		$attachments = get_children(
			array(
				'post_parent' => $post->ID,
				'post_type' => 'attachment',
				)
			);
		
		$old_paths = array();
		
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
				foreach ( $files as $file ) {
					$is_writable &= is_writable("$upload_path/$old_path/$file");
				}
				
				if ( !$is_writable )
					continue;
					
				# process files
				$update_db = false;
				
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
					}
					
					# edit post_content
					$find = ( ( $old_path != '.' )
						? "$rel_upload_path/$old_path/$file"
						: "$rel_upload_path/$file"
						);
					$repl = "$rel_upload_path/$new_path/$file";
					
					$post->post_content = str_replace(
						$find,
						$repl,
						$post->post_content,
						$count);
					$update_db |= $count;
				}
					
				# update post
				if ( $update_db ) {
					$wpdb->query("
						UPDATE	$wpdb->posts
						SET		post_content = '" . $wpdb->escape($post->post_content) . "'
						WHERE	ID = " . intval($post->ID)
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
?>