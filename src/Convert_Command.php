<?php

class Convert_Command extends Import_Command {

	public $filelist = array();

	public function __invoke( $args, $assoc_args ) {
		$defaults   = array(
			'overwrite' => false,
			'output' => false,
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
				WP_CLI::success( "Converted '$file' to '$ret'." );
			}
		}
	}

	private function json_encode( $json ) {
		return json_encode( $json, JSON_PRETTY_PRINT );
	}

	private function get_blog_details( $file ) {
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

		return $config;
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

		$write_to_dir = false;
		// $write_to_dir = getcwd() . '/export/'; // uncomment to write files.

		$this->add_file( 'site/config.json', $this->json_encode( $this->get_blog_details( $file ) ), $write_to_dir );

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

			foreach ( $import_data[ $key ] as $entry ) {
				if ( ! isset( $entry[ $data['id'] ] ) || ! is_numeric( $entry[ $data['id'] ] ) ) {
					continue;
				}

				$json = array();
				foreach( $data['map'] as $wxr_key => $json_key ) {
					if ( isset( $entry[ $wxr_key ] ) ) {
						$json[ $json_key ] = $entry[ $wxr_key ];
					}
				}

				$this->add_file( $data['dir'] . $entry[ $data['id'] ] . '.json', $this->json_encode( $json ) );
			}
		}

		$output_filename = $args['output'];
		if ( ! $output_filename ) {
			$output_filename = preg_replace( '/\.(wxr|xml)$/i', '.wxz', $file );
			if ( $output_filename === $file ) {
				$output_filename = $file . '.wxz';
			}
		}

		if ( file_exists( $output_filename ) && ! $args['overwrite'] ) {
			return new WP_Error( 'file-exists', "File $output_filename already exists." );
		}

		return $this->write_wxz( $output_filename );
	}

	private function add_file( $filename, $content, $write_to_dir = false ) {
		require_once ABSPATH . '/wp-admin/includes/class-pclzip.php';

		$this->filelist[] = array(
			PCLZIP_ATT_FILE_NAME => $filename,
			PCLZIP_ATT_FILE_CONTENT => $content,
		);

		if ( $write_to_dir ) {
			$dir = dirname( $filename );
			$write_to_dir = rtrim( $write_to_dir, '/' ) . '/';
			if ( ! file_exists( $write_to_dir . $dir ) ) {
				mkdir( $write_to_dir . $dir, 0777, true );
			}
			file_put_contents( $write_to_dir . $filename, $content );
		}

		return $filename;
	}

	private function write_wxz( $output_filename ) {
		if ( empty( $this->filelist ) ) {
			return new WP_Error( 'no-files', 'No files to write.' );
		}

		$archive = new PclZip( $output_filename );
		$list = $archive->create( $this->filelist );

		return $output_filename;
	}
}
