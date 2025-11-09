<?php
/**
 * Protected Folders Helper Functions
 *
 * Core functionality helpers for protected folder system initialization and management.
 * These global functions provide a simplified API for folder protection registration and usage.
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

use ArrayPress\ProtectedFolders\Protector;
use ArrayPress\ProtectedFolders\Registry;
use ArrayPress\ProtectedFolders\Delivery;

/* ========================================================================== */
/* FOLDER PROTECTION FUNCTIONS                                               */
/* ========================================================================== */

if ( ! function_exists( 'register_protected_folder' ) ) {
	/**
	 * Register a protected folder
	 *
	 * This is the primary method for registering and configuring protected folders.
	 * It automatically creates a Protector instance and manages it through the Registry pattern.
	 *
	 * For advanced operations, retrieve the Protector instance with get_protected_folder().
	 *
	 * @param string $id                 Unique identifier for the protected folder
	 * @param array  $config             {
	 *                                   Configuration options
	 *
	 * @type array   $allowed_types      File extensions allowed for public access (default: ['jpg', 'jpeg', 'png', 'gif', 'webp'])
	 * @type bool    $dated_folders      Whether to organize by year/month (default: true)
	 * @type bool    $auto_protect       Auto-protect on admin_init (default: false)
	 * @type mixed   $upload_filter      Post type(s), admin page(s), or callback for upload filtering
	 * @type array   $admin_notice_pages Admin pages to show protection notices on (default: [])
	 *                                   }
	 *
	 * @return Protector|null The protector instance or null if registration failed
	 */
	function register_protected_folder( string $id, array $config = [] ): ?Protector {
		try {
			$registry = Registry::get_instance();

			return $registry->register( $id, $config );

		} catch ( Exception $e ) {
			error_log( sprintf(
				'Protected Folders: Failed to register folder "%s" - %s',
				$id,
				$e->getMessage()
			) );

			return null;
		}
	}
}

if ( ! function_exists( 'get_protected_folder_path' ) ) {
	/**
	 * Get the upload directory path for a protected folder
	 *
	 * Returns the full file system path to the protected folder.
	 *
	 * @param string $id    Protected folder identifier
	 * @param bool   $dated Whether to include year/month subdirectory
	 *
	 * @return string Path to the protected folder or empty string if not found
	 */
	function get_protected_folder_path( string $id, bool $dated = false ): string {
		$protector = get_protected_folder( $id );

		return $protector ? $protector->get_upload_path( $dated ) : '';
	}
}

if ( ! function_exists( 'get_protected_folder_url' ) ) {
	/**
	 * Get the upload directory URL for a protected folder
	 *
	 * Returns the public URL to the protected folder.
	 *
	 * @param string $id    Protected folder identifier
	 * @param bool   $dated Whether to include year/month subdirectory
	 *
	 * @return string URL to the protected folder or empty string if not found
	 */
	function get_protected_folder_url( string $id, bool $dated = false ): string {
		$protector = get_protected_folder( $id );

		return $protector ? $protector->get_upload_url( $dated ) : '';
	}
}

if ( ! function_exists( 'is_folder_protected' ) ) {
	/**
	 * Check if a folder is protected
	 *
	 * Tests whether the folder has working protection in place.
	 * This actually tests file access, not just the presence of protection files.
	 *
	 * @param string $id    Protected folder identifier
	 * @param bool   $force Force recheck even if cached
	 *
	 * @return bool True if protected, false otherwise or if folder not found
	 */
	function is_folder_protected( string $id, bool $force = false ): bool {
		$protector = get_protected_folder( $id );

		return $protector && $protector->is_protected( $force );
	}
}

if ( ! function_exists( 'get_protected_folder' ) ) {
	/**
	 * Get a protected folder instance
	 *
	 * Retrieves a registered Protector instance for advanced operations.
	 * Use this when you need access to methods not exposed through helper functions.
	 *
	 * Example:
	 *     $protector = get_protected_folder( 'downloads' );
	 *     if ( $protector ) {
	 *         $protector->protect( true );  // Force protection
	 *         $result = $protector->test_protection();
	 *         $info = $protector->get_debug_info();
	 *     }
	 *
	 * @param string $id Protected folder identifier
	 *
	 * @return Protector|null Protector instance or null if not found
	 */
	function get_protected_folder( string $id ): ?Protector {
		$registry = Registry::get_instance();

		return $registry->get( $id );
	}
}

/* ========================================================================== */
/* FILE DELIVERY FUNCTIONS                                                   */
/* ========================================================================== */

if ( ! function_exists( 'deliver_protected_file' ) ) {
	/**
	 * Deliver a protected file.
	 *
	 * Automatically determines the best delivery method based on file type:
	 * - PDFs display inline in browser
	 * - Images display inline
	 * - Videos/Audio stream with HTML5 players
	 * - Archives and documents force download
	 *
	 * Features:
	 * - Automatic MIME type detection
	 * - Smart chunk size optimization
	 * - Range support for resume/streaming
	 * - X-Sendfile when available
	 *
	 * @param string $file_path      Path to the file to deliver.
	 * @param array  $options        {
	 *                               Optional delivery options.
	 *
	 * @type string  $filename       Custom filename for download
	 * @type string  $mime_type      Override MIME type (auto-detected if not set)
	 * @type bool    $force_download Force download even for inline types (e.g., PDFs)
	 * @type bool    $enable_range   Enable range support for resume/streaming (default: true)
	 * @type int     $chunk_size     Chunk size in bytes (auto-optimized if not set)
	 *                               }
	 *
	 * @return void Exits after delivery.
	 */
	function deliver_protected_file( string $file_path, array $options = [] ): void {
		$delivery = new Delivery();
		$delivery->stream( $file_path, $options );
	}
}

if ( ! function_exists( 'create_file_delivery' ) ) {
	/**
	 * Create a configured Delivery instance.
	 *
	 * Use this when you need to deliver multiple files with the same configuration
	 * or want to reuse settings across multiple deliveries.
	 *
	 * Example:
	 *     // Create instance that always forces download
	 *     $delivery = create_file_delivery( [ 'force_download' => true ] );
	 *
	 *     // Deliver multiple files with same settings
	 *     foreach ( $files as $file ) {
	 *         $delivery->stream( $file );
	 *     }
	 *
	 * @param array $options      {
	 *                            Delivery configuration options.
	 *
	 * @type int    $chunk_size   Chunk size in bytes (default: 1MB)
	 * @type bool   $enable_range Enable range support (default: true)
	 *                            }
	 *
	 * @return Delivery Configured delivery instance.
	 */
	function create_file_delivery( array $options = [] ): Delivery {
		return new Delivery( $options );
	}
}