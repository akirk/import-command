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

		$dom       = new DOMDocument;
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}
		$success = $dom->loadXML( file_get_contents( $file ) );
		if ( ! is_null( $old_value ) ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $success || isset( $dom->doctype ) ) {
			return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this WXR file', 'wordpress-importer' ), libxml_get_errors() );
		}

		$xml = simplexml_import_dom( $dom );
		unset( $dom );

		// halt if loading produces an error
		if ( ! $xml ) {
			return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this WXR file', 'wordpress-importer' ), libxml_get_errors() );
		}
		$config = array();
		foreach ( array(
			'/rss/channel/title' => 'title',
			'/rss/channel/link' => 'link',
			'/rss/channel/description' => 'description',
			'/rss/channel/pubDate' => 'date',
			'/rss/channel/language' => 'language',
		) as $xpath => $key ) {
			$val = $xml->xpath( $xpath );
			if ( ! $val ) {
				continue;
			}
			$config[ $key ] = (string) trim( $val[0] );
		}

		file_put_contents( $site_dir . 'config.json', json_encode( $config ) );

		$map = array(
			'users' => array(
				'dir' => 'users/',
				'id' => 'author_id',
				'map' => array(
					'author_login' => 'username',
					'author_display_name' => 'display_name',
					'author_email' => 'email',
				),
			),
			'posts' => array(
				'dir' => 'posts/',
				'id' => 'post_id',
				'map' => array(
					'post_title' => 'title',
					'post_author' => 'author',
					'post_status' => 'status',
					'post_content' => 'content',
					'post_type' => 'type',
					'post_content' => 'content',
					'post_date_gmt' => 'date_utc',
					'attachment_url' => 'attachment_url',
					'postmeta' => 'postmeta',
				),
			),
			'terms' => array(
				'dir' => 'terms/',
				'id' => 'term_id',
				'map' => array(
					'term_name' => 'name',
					'term_taxonomy' => 'taxonomy',
					'slug' => 'slug',
					'term_parent' => 'parent',
					'term_description' => 'description',
				),
			),
			'categories' => array(
				'dir' => 'categories/',
				'id' => 'term_id',
				'map' => array(
					'category_nicename' => 'name',
					'category_parent' => 'parent',
					'cat_name' => 'slug',
					'category_description' => 'description',
				),
			),
			'objects' => array(
				'dir' => 'objects/',
				'id' => 'object_id',
				'map' => array(
					'type' => 'type',
					'data' => 'data',
				),
			),
		);

		foreach ( $map as $key => $data ) {
			if ( empty( $import_data[ $key ] ) ) {
				continue;
			}

			$dir = $base_dir . '/' . $data['dir'];
			if ( ! file_exists( $dir ) ) {
				mkdir( $dir );
			}

			$json = array();
			foreach ( $import_data[ $key ] as $entry ) {
				if ( ! isset( $entry[ $data['id'] ] ) || ! is_numeric( $entry[ $data['id'] ] ) ) {
					continue;
				}

				foreach( $data['map'] as $wxr_key => $json_key ) {
					if ( isset( $entry[ $wxr_key ] ) ) {
						$json[ $json_key ] = $entry[ $wxr_key ];
					}
				}

				file_put_contents( $dir . $entry[ $data['id'] ] . '.json', json_encode( $json ) );
			}
		}
	}
}
