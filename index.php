<?php
/*
Script Name: WP Quick Install
Author: Jonathan Buttigieg
Contributors: Julio Potier
Script URI: http://wp-quick-install.com
Version: 1.4.1
Licence: GPLv3
Last Update: 08 jan 15
*/

@set_time_limit( 0 );
error_reporting(E_ALL);
ini_set('display_errors', 1);

define( 'WP_API_CORE'				, 'http://api.wordpress.org/core/version-check/1.7/?locale=' );
define( 'WPQI_PATH'					, dirname(__FILE__).'/');
define( 'WP_PATH'					, WPQI_PATH.'/../');
define( 'WPQI_IMPORT_PATH'			, WPQI_PATH.'import/' );
define( 'WPQI_CACHE_PATH'			, WPQI_PATH.'cache/' );
define( 'WPQI_CACHE_CORE_PATH'		, WPQI_CACHE_PATH . 'core/' );
define( 'WPQI_CACHE_PLUGINS_PATH'	, WPQI_CACHE_PATH . 'plugins/' );
define( 'WPQI_CACHE_THEMES_PATH'	, WPQI_CACHE_PATH . 'themes/' );

require( 'inc/functions.php' );


// Create cache directories
if ( ! is_dir( WPQI_CACHE_PATH ) ) {
	mkdir( WPQI_CACHE_PATH );
}
if ( ! is_dir( WPQI_CACHE_CORE_PATH ) ) {
	mkdir( WPQI_CACHE_CORE_PATH );
}
if ( ! is_dir( WPQI_CACHE_PLUGINS_PATH ) ) {
	mkdir( WPQI_CACHE_PLUGINS_PATH );
}
if ( ! is_dir( WPQI_CACHE_THEMES_PATH ) ) {
	mkdir( WPQI_CACHE_THEMES_PATH );
}

// We verify if there is a preconfig file
$data = array();
if ( file_exists( 'data.ini' ) ) {
	$data = json_encode( parse_ini_file( 'data.ini' ) );
}

// We add  ../ to directory
$directory = ! empty( $_POST['directory'] ) ? '../' . $_POST['directory'] . '/' : '../';

ob_start();

$response = (object) array(
	'success' => false,
	'error' => null,
	'data' => null,
	'log' => null
);

if ( isset( $_GET['action'] ) ) {

	switch( $_GET['action'] ) {

		case "check_before_upload" :

			/*--------------------------*/
			/*	We verify if we can connect to DB or WP is not installed yet
			/*--------------------------*/

			// DB Test
			try {
			   $db = new PDO('mysql:host='. $_POST['dbhost'] .';dbname=' . $_POST['dbname'] , $_POST['uname'], $_POST['pwd'] );
			}
			catch (Exception $e) {
				$response->error = "Error establishing db connection";
			}

			// WordPress test
			if ( file_exists( $directory . 'wp-config.php' ) ) {
				$response->error = "Error WordPress already exists";
			}
			
			break;

		case "download_wp" :

			// Get WordPress language
			$language = substr( $_POST['language'], 0, 6 );

			// Get WordPress data
			$wp = json_decode( file_get_contents( WP_API_CORE . $language ) )->offers[0];

			/*--------------------------*/
			/*	We download the latest version of WordPress
			/*--------------------------*/

			if ( ! file_exists( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language  . '.zip' ) ) {
				file_put_contents( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language  . '.zip', file_get_contents( $wp->download ) );
			}

			break;

		case "unzip_wp" :

			// Get WordPress language
			$language = substr( $_POST['language'], 0, 6 );

			// Get WordPress data
			$wp = json_decode( file_get_contents( WP_API_CORE . $language ) )->offers[0];

			/*--------------------------*/
			/*	We create the website folder with the files and the WordPress folder
			/*--------------------------*/

			// If we want to put WordPress in a subfolder we create it
			if ( ! empty( $directory ) && !is_dir($directory) ) {
				// Let's create the folder
				@mkdir( $directory );

				// We set the good writing rights
				@chmod( $directory , 0755 );
			}

			$zip = new ZipArchive;

			// We verify if we can use the archive
			if ( $zip->open( WPQI_CACHE_CORE_PATH . 'wordpress-' . $wp->version . '-' . $language  . '.zip' ) === true ) {

				// Let's unzip
				$zip->extractTo( '.' );
				$zip->close();

				// We scan the folder
				$files = scandir( 'wordpress' );

				// We remove the "." and ".." from the current folder and its parent
				$files = array_diff( $files, array( '.', '..', 'index.php' ) );

				// We move the files and folders
				foreach ( $files as $file ) {
					@rename(  'wordpress/' . $file, $directory . '/' . $file );
				}

				@rmdir( 'wordpress' ); // We remove WordPress folder
				@unlink( $directory . '/license.txt' ); // We remove licence.txt
				@unlink( $directory . '/readme.html' ); // We remove readme.html
				@unlink( $directory . '/wp-content/plugins/hello.php' ); // We remove Hello Dolly plugin
				
			}else{
				
				$response->error = 'Cannot Install WordPress';
			}

			break;

		case "wp_config" :

			/*--------------------------*/
			/*	Let's create the wp-config file
			/*--------------------------*/
	
			// We retrieve each line as an array
			$config_file = file( $directory . 'wp-config.php' );
	
			// Managing the security keys
			$secret_keys = explode( "\n", file_get_contents( 'https://api.wordpress.org/secret-key/1.1/salt/' ) );
	
			foreach ( $secret_keys as $k => $v ) {
				$secret_keys[$k] = substr( $v, 28, 64 );
			}
	
			// We change the data
			$key = 0;
			foreach ( $config_file as &$line ) {
	
				if ( '$table_prefix  =' == substr( $line, 0, 16 ) ) {
					$line = '$table_prefix  = \'' . sanit( $_POST[ 'prefix' ] ) . "';\r\n";
					continue;
				}
	
				if ( ! preg_match( '/^define\(\'([A-Z_]+)\',([ ]+)/', $line, $match ) ) {
					continue;
				}
	
				$constant = $match[1];
	
				switch ( $constant ) {
					case 'WP_DEBUG'	   :
	
						// Debug mod
						if ( (int) $_POST['debug'] == 1 ) {
							$line = "define('WP_DEBUG', true);\r\n";
	
							// Display error
							if ( (int) $_POST['debug_display'] == 1 ) {
								$line .= "\r\n\n " . "/** Affichage des erreurs à l'écran */" . "\r\n";
								$line .= "define('WP_DEBUG_DISPLAY', true);\r\n";
							}
	
							// To write error in a log files
							if ( (int) $_POST['debug_log'] == 1 ) {
								$line .= "\r\n\n " . "/** Ecriture des erreurs dans un fichier log */" . "\r\n";
								$line .= "define('WP_DEBUG_LOG', true);\r\n";
							}
						}
	
						// We add the extras constant
						if ( ! empty( $_POST['uploads'] ) ) {
							$line .= "\r\n\n " . "/** Dossier de destination des fichiers uploadés */" . "\r\n";
							$line .= "define('UPLOADS', '" . sanit( $_POST['uploads'] ) . "');";
						}
	
						if ( (int) $_POST['post_revisions'] >= 0 ) {
							$line .= "\r\n\n " . "/** Désactivation des révisions d'articles */" . "\r\n";
							$line .= "define('WP_POST_REVISIONS', " . (int) $_POST['post_revisions'] . ");";
						}
	
						if ( (int) $_POST['disallow_file_edit'] == 1 ) {
							$line .= "\r\n\n " . "/** Désactivation de l'éditeur de thème et d'extension */" . "\r\n";
							$line .= "define('DISALLOW_FILE_EDIT', true);";
						}
	
						if ( (int) $_POST['autosave_interval'] >= 60 ) {
							$line .= "\r\n\n " . "/** Intervalle des sauvegardes automatique */" . "\r\n";
							$line .= "define('AUTOSAVE_INTERVAL', " . (int) $_POST['autosave_interval'] . ");";
						}
						
						if ( ! empty( $_POST['wpcom_api_key'] ) ) {
							$line .= "\r\n\n " . "/** WordPress.com API Key */" . "\r\n";
							$line .= "define('WPCOM_API_KEY', '" . $_POST['wpcom_api_key'] . "');";
						}
	
						if ( (int) $_POST['memory_limit'] ) {
							$line .= "\r\n\n " . "/** On augmente la mémoire limite */" . "\r\n";
							$line .= "define('WP_MEMORY_LIMIT', '". (int) $_POST['memory_limit']."M');" . "\r\n";
						}
						
						$line .= "\r\n\n " . "/** On augmente la mémoire limite */" . "\r\n";
						$line .= "define('WP_SITEURL', 'https://". $_SERVER['HTTP_HOST']. "');" . "\r\n";
						$line .= "define('WP_HOME', 'https://". $_SERVER['HTTP_HOST']. "');" . "\r\n";
			
						break;
					case 'DB_NAME'     :
						if(!empty($_POST[ 'dbname' ])) {
							$line = "define('DB_NAME', '" . sanit( $_POST[ 'dbname' ] ) . "');\r\n";
						}
						break;
					case 'DB_USER'     :
						if(!empty($_POST[ 'uname' ])) {
							$line = "define('DB_USER', '" . sanit( $_POST['uname'] ) . "');\r\n";
						}
						break;
					case 'DB_PASSWORD' :
						if(!empty($_POST[ 'pwd' ])) {
							$line = "define('DB_PASSWORD', '" . sanit( $_POST['pwd'] ) . "');\r\n";
						}
						break;
					case 'DB_HOST'     :
						if(!empty($_POST[ 'dbhost' ])) {
							$line = "define('DB_HOST', '" . sanit( $_POST['dbhost'] ) . "');\r\n";
						}
						break;
					case 'AUTH_KEY'         :
					case 'SECURE_AUTH_KEY'  :
					case 'LOGGED_IN_KEY'    :
					case 'NONCE_KEY'        :
					case 'AUTH_SALT'        :
					case 'SECURE_AUTH_SALT' :
					case 'LOGGED_IN_SALT'   :
					case 'NONCE_SALT'       :
						$line = "define('" . $constant . "', '" . $secret_keys[$key++] . "');\r\n";
						break;
						
				}
			}
			unset( $line );
	
			$handle = fopen( $directory . 'wp-config.php', 'w' );
			foreach ( $config_file as $line ) {
				fwrite( $handle, $line );
			}
			fclose( $handle );
	
			// We set the good rights to the wp-config file
			chmod( $directory . 'wp-config.php', 0666 );
	
			break;

		case "install_wp" :

			/*--------------------------*/
			/*	Let's install WordPress database
			/*--------------------------*/

			define( 'WP_INSTALLING', true );
			
			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/** Load wpdb */
			require_once( $directory . 'wp-includes/wp-db.php' );

			// WordPress installation
			$wp = wp_install( $_POST[ 'weblog_title' ], $_POST['user_login'], $_POST['admin_email'], (int) $_POST[ 'blog_public' ], '', $_POST['admin_password'] );
			
			$response->data = $wp;
			
			// We update the options with the right siteurl et homeurl value
/*
			$protocol = ! is_ssl() ? 'http' : 'https';
            $get = basename( dirname( __FILE__ ) ) . '/index.php/wp-admin/install.php?action=install_wp';
            $dir = str_replace( '../', '', $directory );
            $link = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $url = str_replace( $get, $dir, $link );
            $url = trim( $url, '/' );

			update_option( 'siteurl', $url );
			update_option( 'home', $url );
*/
			

			/*--------------------------*/
			/*	We remove the default content
			/*--------------------------*/

			if ( $_POST['default_content'] == '1' ) {
				wp_delete_post( 1, true ); // We remove the article "Hello World"
				wp_delete_post( 2, true ); // We remove the "Exemple page"
			}

			/*--------------------------*/
			/*	We update permalinks
			/*--------------------------*/
			if ( ! empty( $_POST['permalink_structure'] ) ) {
				update_option( 'permalink_structure', $_POST['permalink_structure'] );
			}

			/*--------------------------*/
			/*	We update the media settings
			/*--------------------------*/

			if ( ! empty( $_POST['thumbnail_size_w'] ) || !empty($_POST['thumbnail_size_h'] ) ) {
				update_option( 'thumbnail_size_w', (int) $_POST['thumbnail_size_w'] );
				update_option( 'thumbnail_size_h', (int) $_POST['thumbnail_size_h'] );
				update_option( 'thumbnail_crop', (int) $_POST['thumbnail_crop'] );
			}

			if ( ! empty( $_POST['medium_size_w'] ) || !empty( $_POST['medium_size_h'] ) ) {
				update_option( 'medium_size_w', (int) $_POST['medium_size_w'] );
				update_option( 'medium_size_h', (int) $_POST['medium_size_h'] );
			}

			if ( ! empty( $_POST['large_size_w'] ) || !empty( $_POST['large_size_h'] ) ) {
				update_option( 'large_size_w', (int) $_POST['large_size_w'] );
				update_option( 'large_size_h', (int) $_POST['large_size_h'] );
			}
			
			if ( ! empty( $_POST['uploads_use_yearmonth_folders'] ) ) {
				update_option( 'uploads_use_yearmonth_folders', (int) $_POST['uploads_use_yearmonth_folders'] );
			}

			/*--------------------------*/
			/*	We add the pages we found in the data.ini file
			/*--------------------------*/

			// We check if data.ini exists
			if ( file_exists( 'data.ini' ) ) {

				// We parse the file and get the array
				$file = parse_ini_file( 'data.ini' );

				// We verify if we have at least one page
				if ( count( $file['posts'] ) >= 1 ) {

					foreach ( $file['posts'] as $post ) {

						// We get the line of the page configuration
						$pre_config_post = explode( "-", $post );
						$post = array();

						foreach ( $pre_config_post as $config_post ) {

							// We retrieve the page title
							if ( preg_match( '#title::#', $config_post ) == 1 ) {
								$post['title'] = str_replace( 'title::', '', $config_post );
							}

							// We retrieve the status (publish, draft, etc...)
							if ( preg_match( '#status::#', $config_post ) == 1 ) {
								$post['status'] = str_replace( 'status::', '', $config_post );
							}

							// On retrieve the post type (post, page or custom post types ...)
							if ( preg_match( '#type::#', $config_post ) == 1 ) {
								$post['type'] = str_replace( 'type::', '', $config_post );
							}

							// We retrieve the content
							if ( preg_match( '#content::#', $config_post ) == 1 ) {
								$post['content'] = str_replace( 'content::', '', $config_post );
							}

							// We retrieve the slug
							if ( preg_match( '#slug::#', $config_post ) == 1 ) {
								$post['slug'] = str_replace( 'slug::', '', $config_post );
							}

							// We retrieve the title of the parent
							if ( preg_match( '#parent::#', $config_post ) == 1 ) {
								$post['parent'] = str_replace( 'parent::', '', $config_post );
							}

						} // foreach

						if ( isset( $post['title'] ) && !empty( $post['title'] ) ) {

							$parent = get_page_by_title( trim( $post['parent'] ) );
								$parent = $parent ? $parent->ID : 0;

							// Let's create the page
							$args = array(
								'post_title' 		=> trim( $post['title'] ),
								'post_name'			=> $post['slug'],
								'post_content'		=> trim( $post['content'] ),
								'post_status' 		=> $post['status'],
								'post_type' 		=> $post['type'],
								'post_parent'		=> $parent,
								'post_author'		=> 1,
								'post_date' 		=> date('Y-m-d H:i:s'),
								'post_date_gmt' 	=> gmdate('Y-m-d H:i:s'),
								'comment_status' 	=> 'closed',
								'ping_status'		=> 'closed'
							);
							wp_insert_post( $args );

						}

					}
				}
			}

			break;
			
		case "install_wpcli" :
		
			echo shell_exec('cd '.WP_PATH.'; curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; chmod +x wp-cli.phar; mv wp-cli.phar /usr/local/bin/wp;');
			break;
			
		case "install_plugins" :

			/*--------------------------*/
			/*	Let's retrieve the plugin folder
			/*--------------------------*/

			if ( ! empty( $_POST['plugins'] ) ) {

				$plugins     = explode( ";", $_POST['plugins'] );
				$plugins     = array_map( 'trim' , $plugins );
				$plugins_dir = $directory . 'wp-content/plugins/';
				
				$errors = [];
				
				foreach ( $plugins as $plugin_slug ) {

					// We retrieve the plugin XML file to get the link to downlad it
				    $plugin_repo = file_get_contents( "http://api.wordpress.org/plugins/info/1.0/$plugin_slug.json" );

				    if ( $plugin_repo && $plugin = json_decode( $plugin_repo ) ) {
						
						if(empty($plugin->error)) {
							
							$plugin_path = WPQI_CACHE_PLUGINS_PATH . $plugin->slug . '-' . $plugin->version . '.zip';
	
							if ( ! file_exists( $plugin_path ) ) {
								// We download the lastest version
								if ( $download_link = file_get_contents( $plugin->download_link ) ) {
									file_put_contents( $plugin_path, $download_link );
								}							
							}
	
					    	// We unzip it
					    	$zip = new ZipArchive;
							if ( $zip->open( $plugin_path ) === true ) {
								$zip->extractTo( $plugins_dir );
								$zip->close();
							}
							
						}else{
							$errors[] = $plugin_slug .': '.$plugin->error;
						}
				    }
				}
				
				if(!empty($errors)) {
					$response->error = implode(",", $errors);
				}
			}

			if ( $_POST['plugins_premium'] == 1 ) {

				// We scan the folder
				$plugins = scandir( 'plugins' );

				// We remove the "." and ".." corresponding to the current and parent folder
				$plugins = array_diff( $plugins, array( '.', '..', 'index.php' ) );

				// We move the archives and we unzip
				foreach ( $plugins as $plugin ) {

					// We verify if we have to retrive somes plugins via the WP Quick Install "plugins" folder
					if ( preg_match( '#(.*).zip$#', $plugin ) == 1 ) {

						$zip = new ZipArchive;

						// We verify we can use the archive
						if ( $zip->open( 'plugins/' . $plugin ) === true ) {

							// We unzip the archive in the plugin folder
							$zip->extractTo( $plugins_dir );
							$zip->close();

						}
					}
				}
			}

			/*--------------------------*/
			/*	We activate extensions
			/*--------------------------*/

			if ( $_POST['activate_plugins'] == 1 ) {

				/** Load WordPress Bootstrap */
				require_once( $directory . 'wp-load.php' );

				/** Load WordPress Plugin API */
				require_once( $directory . 'wp-admin/includes/plugin.php');

				// Activation
				activate_plugins( array_keys( get_plugins() ) );
			}

			break;


		case "install_theme" :

			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/*--------------------------*/
			/*	We download the latest version of StoreFront theme
			/*--------------------------*/

			$theme_slug = !empty($_POST['theme']) ? $_POST['theme'] : 'storefront';
			$theme_url = 'https://downloads.wordpress.org/theme/'.$theme_slug.'.zip';
			
			if ( ! file_exists( WPQI_CACHE_THEMES_PATH . $theme_slug.'.zip' ) ) {
				file_put_contents( WPQI_CACHE_THEMES_PATH . $theme_slug.'.zip', file_get_contents($theme_url) );
			}
		
			// We verify if theme.zip exists
			if ( file_exists( WPQI_CACHE_THEMES_PATH . $theme_slug.'.zip' ) ) {

				$zip = new ZipArchive;

				// We verify we can use it
				if ( $zip->open( WPQI_CACHE_THEMES_PATH . $theme_slug.'.zip' ) === true ) {

					// We retrieve the name of the folder
					$stat = $zip->statIndex( 0 );
					$theme_name = str_replace('/', '' , $stat['name']);

					// We unzip the archive in the themes folder
					$zip->extractTo( $directory . 'wp-content/themes/' );
					$zip->close();

					// Let's activate the theme
					// Note : The theme is automatically activated if the user asked to remove the default theme
					if ( $_POST['activate_theme'] == 1 || $_POST['delete_default_themes'] == 1 ) {
						switch_theme( $theme_name, $theme_name );
					}

					// Let's remove the Tweenty family
					if ( $_POST['delete_default_themes'] == 1 ) {
						delete_theme( 'twentyfourteen' );
						delete_theme( 'twentythirteen' );
						delete_theme( 'twentytwelve' );
						delete_theme( 'twentyeleven' );
						delete_theme( 'twentyten' );
					}

					// We delete the _MACOSX folder (bug with a Mac)
					delete_theme( '__MACOSX' );
					
					// Import theme XML data
					if ( file_exists( WPQI_IMPORT_PATH . $theme_slug .'.xml' ) ) {
						echo shell_exec('cd '.WP_PATH.'; wp import '.WPQI_IMPORT_PATH . $theme_slug .'.xml');
					}

				}else{
					
					$response->error = 'Cannot install theme';
				}
			}

			break;

		case "success" :

			/*--------------------------*/
			/*	If we have a success we add the link to the admin and the website
			/*--------------------------*/

			/** Load WordPress Bootstrap */
			require_once( $directory . 'wp-load.php' );

			/** Load WordPress Administration Upgrade API */
			require_once( $directory . 'wp-admin/includes/upgrade.php' );

			/*--------------------------*/
			/*	We update permalinks
			/*--------------------------*/
			file_put_contents( $directory . '.htaccess' , null );
			flush_rewrite_rules();

			break;
	}

}

$response->success = empty($response->error);
$response->log = ob_get_contents();

ob_end_clean();

die(json_encode($response));
