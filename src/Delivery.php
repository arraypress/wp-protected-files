<?php
/**
 * File Delivery Handler
 *
 * Handles secure file delivery with support for streaming, range requests,
 * and X-Sendfile/X-Accel-Redirect for optimal performance.
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

/**
 * Delivery Class
 *
 * Secure file delivery with streaming and server optimization support.
 */
class Delivery {

	/**
	 * Default delivery options.
	 *
	 * @var array
	 */
	private array $defaults = [
		'chunk_size'   => 1048576, // 1MB
		'enable_range' => true
		// Note: force_download removed - now auto-detected
	];

	/**
	 * Current delivery options.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Common MIME type mappings.
	 *
	 * @var array
	 */
	private array $mime_types = [
		// Documents
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

		// Media
		'mp3'  => 'audio/mpeg',
		'ogg'  => 'audio/ogg',
		'wav'  => 'audio/wav',
		'mp4'  => 'video/mp4',
		'webm' => 'video/webm',
		'avi'  => 'video/x-msvideo',

		// Images
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'svg'  => 'image/svg+xml',

		// Archives
		'zip'  => 'application/zip',
		'rar'  => 'application/x-rar-compressed',
		'7z'   => 'application/x-7z-compressed',
		'tar'  => 'application/x-tar',
		'gz'   => 'application/gzip',

		// Text
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'json' => 'application/json',
		'xml'  => 'application/xml'
	];

	/**
	 * Constructor.
	 *
	 * @param array $options      {
	 *                            Optional delivery configuration.
	 *
	 * @type int    $chunk_size   Chunk size in bytes (default: 1MB, auto-optimized by file type)
	 * @type bool   $enable_range Enable range request support (default: true)
	 *                            }
	 */
	public function __construct( array $options = [] ) {
		$this->options = array_merge( $this->defaults, $options );
	}

	/**
	 * Stream a file to the browser.
	 *
	 * Automatically detects optimal settings based on file type.
	 *
	 * @param string $file_path      Path to the file to stream.
	 * @param array  $overrides      {
	 *                               Optional delivery overrides for this specific file.
	 *
	 * @type string  $filename       Filename for download (default: basename of file)
	 * @type string  $mime_type      MIME type (default: auto-detect)
	 * @type bool    $force_download Force download instead of auto-detect behavior
	 * @type int     $chunk_size     Chunk size in bytes
	 * @type bool    $enable_range   Enable range request support
	 *                               }
	 *
	 * @return void Exits after delivery.
	 */
	public function stream( string $file_path, array $overrides = [] ): void {
		// Verify file exists and is readable
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			wp_die(
				__( 'File not found or not readable.', 'arraypress' ),
				__( 'Download Error', 'arraypress' ),
				[ 'response' => 404 ]
			);
		}

		// Merge options with overrides
		$options = array_merge( $this->options, $overrides );

		// Set default filename if not provided
		if ( empty( $options['filename'] ) ) {
			$options['filename'] = basename( $file_path );
		}

		// Auto-detect MIME type if not provided
		if ( empty( $options['mime_type'] ) ) {
			$options['mime_type'] = $this->detect_mime_type( $file_path );
		}

		// Auto-detect download behavior if not explicitly set
		if ( ! isset( $overrides['force_download'] ) ) {
			$options['force_download'] = $this->should_force_download( $options['mime_type'] );
		}

		// Optimize chunk size based on MIME type if not explicitly set
		if ( ! isset( $overrides['chunk_size'] ) ) {
			$options['chunk_size'] = $this->get_optimal_chunk_size( $options['mime_type'] );
		}

		// Setup environment
		$this->setup_environment( $file_path );

		// Try X-Sendfile if available (always check, it's a performance win!)
		if ( $this->supports_xsendfile() ) {
			$this->deliver_via_xsendfile( $file_path, $options );
			exit;
		}

		// Stream file normally
		$this->stream_file( $file_path, $options );
		exit;
	}

	/**
	 * Set a delivery option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 *
	 * @return self Returns self for method chaining.
	 */
	public function set_option( string $key, $value ): self {
		$this->options[ $key ] = $value;

		return $this;
	}

	/**
	 * Set multiple delivery options.
	 *
	 * @param array $options Options to set.
	 *
	 * @return self Returns self for method chaining.
	 */
	public function set_options( array $options ): self {
		$this->options = array_merge( $this->options, $options );

		return $this;
	}

	/**
	 * Get current delivery options.
	 *
	 * @return array Current options.
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Detect MIME type from file.
	 *
	 * @param string $file_path File path.
	 *
	 * @return string MIME type.
	 */
	private function detect_mime_type( string $file_path ): string {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Check our common types first
		if ( isset( $this->mime_types[ $extension ] ) ) {
			return $this->mime_types[ $extension ];
		}

		// Fall back to WordPress detection
		$filetype = wp_check_filetype( $file_path );

		return $filetype['type'] ?: 'application/octet-stream';
	}

	/**
	 * Determine if file should be downloaded or displayed inline.
	 *
	 * @param string $mime_type MIME type.
	 *
	 * @return bool True to force download, false to display inline.
	 */
	private function should_force_download( string $mime_type ): bool {
		// Images should display inline
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return false;
		}

		// Video/audio should stream inline (HTML5 players)
		if ( str_starts_with( $mime_type, 'video/' ) || str_starts_with( $mime_type, 'audio/' ) ) {
			return false;
		}

		// These specific types make sense to view inline
		$inline_types = [
			'application/pdf',  // PDFs can be viewed in browser
			'text/plain',       // Text files
			'text/csv',         // CSV can be displayed
		];

		if ( in_array( $mime_type, $inline_types, true ) ) {
			return false;
		}

		// Everything else downloads (safer default)
		// This includes: ZIP, DOC, DOCX, XLS, executables, unknown types
		return true;
	}

	/**
	 * Get optimal chunk size for MIME type.
	 *
	 * @param string $mime_type MIME type.
	 *
	 * @return int Chunk size in bytes.
	 */
	private function get_optimal_chunk_size( string $mime_type ): int {
		// Video files need larger chunks for smooth streaming
		if ( str_starts_with( $mime_type, 'video/' ) ) {
			return 2097152; // 2MB
		}

		// Archives benefit from larger chunks
		if ( str_contains( $mime_type, 'zip' ) || str_contains( $mime_type, 'compressed' ) || str_contains( $mime_type, 'tar' ) ) {
			return 4194304; // 4MB
		}

		// Audio files
		if ( str_starts_with( $mime_type, 'audio/' ) ) {
			return 1048576; // 1MB
		}

		// Images can use smaller chunks
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 524288; // 512KB
		}

		// Default for documents and others
		return 1048576; // 1MB
	}

	/**
	 * Check if server supports X-Sendfile or X-Accel-Redirect.
	 *
	 * @return bool True if X-Sendfile is supported.
	 */
	public function supports_xsendfile(): bool {
		// Check Apache mod_xsendfile
		if ( function_exists( 'apache_get_modules' ) ) {
			$modules = apache_get_modules();
			if ( in_array( 'mod_xsendfile', $modules, true ) ) {
				return true;
			}
		}

		// Check for Nginx (requires manual configuration)
		$server = $_SERVER['SERVER_SOFTWARE'] ?? '';
		if ( str_contains( strtolower( $server ), 'nginx' ) ) {
			// Nginx support requires configuration, check filter
			return apply_filters( 'protected_folders_nginx_xsendfile', false );
		}

		// Check for LiteSpeed
		if ( str_contains( strtolower( $server ), 'litespeed' ) ) {
			// LiteSpeed supports X-Sendfile like Apache
			return true;
		}

		return false;
	}

	/**
	 * Set secure download headers.
	 *
	 * @param string      $filename  Filename for download.
	 * @param string|null $mime_type MIME type.
	 * @param bool        $inline    Whether to display inline instead of download.
	 *
	 * @return void
	 */
	private function set_download_headers( string $filename, ?string $mime_type = null, bool $inline = false ): void {
		// Prevent caching
		nocache_headers();

		// Security headers
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff' );

		// Force download for potentially dangerous types
		$dangerous_types = [
			'text/html',
			'text/javascript',
			'application/javascript',
			'application/x-javascript',
			'application/x-httpd-php'
		];

		if ( in_array( strtolower( $mime_type ), $dangerous_types, true ) ) {
			$mime_type = 'application/octet-stream';
			$inline    = false;
		}

		header( 'Content-Type: ' . $mime_type );

		// File transfer headers
		header( 'Content-Description: File Transfer' );
		header( 'Content-Transfer-Encoding: binary' );

		// Set disposition
		$disposition = $inline ? 'inline' : 'attachment';

		// Sanitize filename for header
		$safe_filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename );

		// Use RFC 5987 for international characters
		if ( $safe_filename !== $filename ) {
			header( sprintf(
				'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
				$disposition,
				$safe_filename,
				rawurlencode( $filename )
			) );
		} else {
			header( sprintf( 'Content-Disposition: %s; filename="%s"', $disposition, $safe_filename ) );
		}
	}

	/**
	 * Parse HTTP range header.
	 *
	 * @param int $file_size Total file size in bytes.
	 *
	 * @return array|null Array with 'start' and 'end' positions or null if no valid range.
	 */
	private function parse_range_header( int $file_size ): ?array {
		if ( ! isset( $_SERVER['HTTP_RANGE'] ) ) {
			return null;
		}

		$range = $_SERVER['HTTP_RANGE'];

		// Parse bytes range
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ) {
			return null;
		}

		$start = $matches[1] !== '' ? (int) $matches[1] : 0;
		$end   = $matches[2] !== '' ? (int) $matches[2] : $file_size - 1;

		// Validate range
		if ( $start > $end || $start >= $file_size || $end >= $file_size ) {
			header( 'HTTP/1.1 416 Range Not Satisfiable' );
			header( 'Content-Range: bytes */' . $file_size );

			return null;
		}

		return [ 'start' => $start, 'end' => $end ];
	}

	/**
	 * Setup delivery environment.
	 *
	 * @param string $file_path Path to file being delivered.
	 *
	 * @return void
	 */
	private function setup_environment( string $file_path ): void {
		// Clean output buffers
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		// Prevent timeouts for large files
		@set_time_limit( 0 );

		// Increase memory limit for large files
		$file_size = filesize( $file_path );
		if ( $file_size > 100 * 1024 * 1024 ) { // 100MB
			@ini_set( 'memory_limit', '256M' );
		}

		// Disable compression
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		@ini_set( 'zlib.output_compression', 'Off' );
	}

	/**
	 * Deliver file via X-Sendfile or X-Accel-Redirect.
	 *
	 * @param string $file_path File path.
	 * @param array  $options   Delivery options.
	 *
	 * @return void
	 */
	private function deliver_via_xsendfile( string $file_path, array $options ): void {
		// Set headers
		$this->set_download_headers(
			$options['filename'],
			$options['mime_type'] ?? null,
			! $options['force_download']
		);

		// Check server type
		$server = strtolower( $_SERVER['SERVER_SOFTWARE'] ?? '' );

		if ( str_contains( $server, 'nginx' ) ) {
			// Nginx uses X-Accel-Redirect with internal location
			$internal_path = apply_filters(
				'protected_folders_nginx_internal_path',
				'/protected/',
				$file_path
			);
			header( 'X-Accel-Redirect: ' . $internal_path . basename( $file_path ) );
		} else {
			// Apache and LiteSpeed use X-Sendfile with full path
			header( 'X-Sendfile: ' . $file_path );
		}
	}

	/**
	 * Stream file with optional range support.
	 *
	 * @param string $file_path File path.
	 * @param array  $options   Delivery options.
	 *
	 * @return void
	 */
	private function stream_file( string $file_path, array $options ): void {
		$file_size = filesize( $file_path );

		// Set download headers
		$this->set_download_headers(
			$options['filename'],
			$options['mime_type'] ?? null,
			! $options['force_download']
		);

		// Handle range requests
		$range = null;
		if ( $options['enable_range'] ) {
			$range = $this->parse_range_header( $file_size );
		}

		if ( $range !== null ) {
			// Partial content
			header( 'HTTP/1.1 206 Partial Content' );
			header( 'Accept-Ranges: bytes' );
			header( sprintf(
				'Content-Range: bytes %d-%d/%d',
				$range['start'],
				$range['end'],
				$file_size
			) );
			header( 'Content-Length: ' . ( $range['end'] - $range['start'] + 1 ) );

			$this->read_file_chunked( $file_path, $range['start'], $range['end'], $options['chunk_size'] );
		} else {
			// Full content
			header( 'Accept-Ranges: ' . ( $options['enable_range'] ? 'bytes' : 'none' ) );
			header( 'Content-Length: ' . $file_size );

			$this->read_file_chunked( $file_path, 0, $file_size - 1, $options['chunk_size'] );
		}
	}

	/**
	 * Read and output file in chunks.
	 *
	 * @param string $file_path  File path.
	 * @param int    $start      Start byte position.
	 * @param int    $end        End byte position.
	 * @param int    $chunk_size Chunk size in bytes.
	 *
	 * @return void
	 */
	private function read_file_chunked( string $file_path, int $start, int $end, int $chunk_size ): void {
		$handle = @fopen( $file_path, 'rb' );

		if ( ! $handle ) {
			wp_die(
				__( 'Cannot open file for reading.', 'arraypress' ),
				__( 'Download Error', 'arraypress' ),
				[ 'response' => 500 ]
			);
		}

		// Seek to start position
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$bytes_sent    = 0;
		$bytes_to_send = $end - $start + 1;

		while ( ! feof( $handle ) && $bytes_sent < $bytes_to_send && connection_status() === CONNECTION_NORMAL ) {
			// Calculate chunk size for this iteration
			$chunk = min( $chunk_size, $bytes_to_send - $bytes_sent );

			// Read and output chunk
			$buffer = fread( $handle, $chunk );
			if ( $buffer === false ) {
				break;
			}

			echo $buffer;
			$bytes_sent += strlen( $buffer );

			// Flush periodically for large files
			if ( $bytes_sent % ( 10 * 1024 * 1024 ) === 0 ) { // Every 10MB
				if ( ob_get_level() > 0 ) {
					@ob_flush();
				}
				@flush();
			}
		}

		fclose( $handle );

		// Final flush
		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}
		@flush();
	}

}