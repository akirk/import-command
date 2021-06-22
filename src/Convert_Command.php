<?php

class Convert_Command extends Import_Command {

	public $processed_posts = array();

	public function __invoke( $args, $assoc_args ) {
		$defaults   = array(
			'output' => '-'
		);
		$assoc_args = wp_parse_args( $assoc_args, $defaults );

		$importer = $this->is_importer_available();
		if ( is_wp_error( $importer ) ) {
			WP_CLI::error( $importer );
		}

		WP_CLI::log( 'Starting the conversion process...' );

		$new_args = array();
		foreach ( $args as $arg ) {
			if ( is_dir( $arg ) ) {
				$dir   = WP_CLI\Utils\trailingslashit( $arg );
				$files = glob( $dir . '*.wxr' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}

				$files = glob( $dir . '*.xml' );
				if ( ! empty( $files ) ) {
					$new_args = array_merge( $new_args, $files );
				}
			} else {
				if ( file_exists( $arg ) ) {
					$new_args[] = $arg;
				}
			}
		}
		$args = $new_args;

		foreach ( $args as $file ) {
			if ( ! is_readable( $file ) ) {
				WP_CLI::warning( "Can't read '$file' file." );
			}

			$ret = $this->convert_wxr( $file, $assoc_args );

			if ( is_wp_error( $ret ) ) {
				WP_CLI::error( $ret );
			} else {
				WP_CLI::log( '' ); // WXR import ends with HTML, so make sure message is on next line
				WP_CLI::success( "Finished converting from '$file' file." );
			}
		}
	}

	/**
	 * Imports a WXR file.
	 */
	private function convert_wxr( $file, $args ) {
		$wp_import                  = new WP_Import();
		$wp_import->processed_posts = $this->processed_posts;
		$import_data                = $wp_import->parse( $file );
		if ( is_wp_error( $import_data ) ) {
			return $import_data;
		}

		$base_dir = getcwd() . '/export/';
		if ( ! file_exists( $base_dir) ) {
			mkdir( $base_dir );
		}

		$site_dir = $base_dir . '/site/';
		if ( ! file_exists( $site_dir) ) {
			mkdir( $site_dir );
		}

		file_put_contents( $site_dir . 'config.json', json_encode( array() ) );

		$posts_dir = $base_dir . '/posts/';
		if ( ! file_exists( $posts_dir) ) {
			mkdir( $posts_dir );
		}

		foreach( $import_data['posts'] as $post ) {
			$filename = $posts_dir . $post['post_id'] . '.json';
			file_put_contents( $filename, json_encode( $post ) );
		}

		$terms_dir = $base_dir . '/terms/';
		if ( ! file_exists( $terms_dir) ) {
			mkdir( $terms_dir );
		}

		foreach( $import_data['terms'] as $term ) {
			$filename = $terms_dir . $term['term_id'] . '.json';
			file_put_contents( $filename, json_encode( $term ) );
		}

		$objects_dir = $base_dir . '/objects/';
		if ( ! file_exists( $objects_dir) ) {
			mkdir( $objects_dir );
		}

		foreach( $import_data['objects'] as $object ) {
			$filename = $objects_dir . $object['object_id'] . '.json';
			file_put_contents( $filename, json_encode( $object ) );
		}

		$users_dir = $base_dir . '/users/';
		if ( ! file_exists( $users_dir) ) {
			mkdir( $users_dir );
		}

		foreach( $import_data['authors'] as $user ) {
			$filename = $users_dir . $user['author_id'] . '.json';
			$user['user_id'] = $user['author_id'];
			unset( $user['author_id'] );
			file_put_contents( $filename, json_encode( $user ) );
		}
	}
}
