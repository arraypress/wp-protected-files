<?php
/**
 * Protected Folders Registry
 *
 * Centralized registry for managing protected folder instances.
 * Implements a singleton pattern to ensure consistent access across the application.
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

use Exception;
use InvalidArgumentException;

/**
 * Class Registry
 *
 * Singleton registry for managing protected folder instances across the application.
 * Provides centralized access to Protector instances without using global variables.
 */
class Registry {

    /**
     * Singleton instance
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Registered protector instances
     *
     * @var array<string, Protector> Associative array of id => Protector instance
     */
    private array $protectors = [];

    /**
     * Hooks initialization status
     *
     * @var bool
     */
    private bool $hooks_initialized = false;

    /**
     * Private constructor
     *
     * Prevents direct instantiation to enforce singleton pattern.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Prevent cloning
     *
     * Ensures singleton instance cannot be cloned.
     *
     * @return void
     */
    private function __clone() {
    }

    /**
     * Prevent unserialization
     *
     * Ensures singleton instance cannot be unserialized.
     *
     * @return void
     * @throws Exception When attempting to unserialize
     */
    public function __wakeup() {
        throw new Exception( "Cannot unserialize singleton" );
    }

    /**
     * Get registry instance
     *
     * Returns the singleton instance of the registry, creating it if necessary.
     *
     * @return self Registry instance
     */
    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize WordPress hooks
     *
     * Sets up necessary hooks for the registry to function properly.
     *
     * @return void
     */
    private function init_hooks(): void {
        if ( $this->hooks_initialized ) {
            return;
        }

        // Process any auto-protect configurations on admin_init
        add_action( 'admin_init', [ $this, 'process_auto_protect' ], 5 );

        // Process upload filters early
        add_action( 'init', [ $this, 'setup_upload_filters' ], 5 );

        $this->hooks_initialized = true;
    }

    /**
     * Register a protected folder
     *
     * Creates and configures a new Protector instance or returns existing one.
     *
     * @param string $id                 Unique identifier for the protected folder
     * @param array  $config             {
     *                                   Configuration options
     *
     * @type array   $allowed_types      File extensions allowed for public access (default: ['jpg', 'jpeg', 'png',
     *                                   'gif', 'webp'])
     * @type bool    $dated_folders      Whether to organize by year/month (default: true)
     * @type bool    $auto_protect       Auto-protect on admin_init (default: false)
     * @type mixed   $upload_filter      Post type(s), admin page(s), or callback for upload filtering
     * @type array   $admin_notice_pages Admin pages to show protection notices on (default: [])
     *                                   }
     *
     * @return Protector The protector instance
     * @throws InvalidArgumentException If ID is empty
     */
    public function register( string $id, array $config = [] ): Protector {
        $id = sanitize_key( $id );

        if ( empty( $id ) ) {
            throw new InvalidArgumentException( __( 'Protected folder ID cannot be empty', 'arraypress' ) );
        }

        // Return existing instance if already registered
        if ( isset( $this->protectors[ $id ] ) ) {
            return $this->protectors[ $id ];
        }

        // Parse configuration with defaults
        $defaults = [
                'allowed_types'      => [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ],
                'dated_folders'      => true,
                'auto_protect'       => false,
                'upload_filter'      => null,
                'admin_notice_pages' => [],
        ];

        $config = wp_parse_args( $config, $defaults );

        // Create new protector instance
        $protector = new Protector(
                $id,
                $config['allowed_types'],
                $config['dated_folders']
        );

        // Store configuration for later processing
        $protector->set_config( $config );

        // Register the protector
        $this->protectors[ $id ] = $protector;

        // Setup admin notices if configured
        if ( ! empty( $config['admin_notice_pages'] ) ) {
            $this->setup_admin_notices( $id, $config['admin_notice_pages'] );
        }

        return $protector;
    }

    /**
     * Get a protector instance
     *
     * Retrieves a registered protector instance by ID.
     *
     * @param string $id Protected folder identifier
     *
     * @return Protector|null Protector instance or null if not found
     */
    public function get( string $id ): ?Protector {
        $id = sanitize_key( $id );

        return $this->protectors[ $id ] ?? null;
    }

    /**
     * Check if a protector exists
     *
     * Determines whether a protector has been registered for the given ID.
     *
     * @param string $id Protected folder identifier
     *
     * @return bool True if protector exists, false otherwise
     */
    public function has( string $id ): bool {
        $id = sanitize_key( $id );

        return isset( $this->protectors[ $id ] );
    }

    /**
     * Remove a protector
     *
     * Unregisters a protector from the registry.
     *
     * @param string $id Protected folder identifier
     *
     * @return bool True if protector was removed, false if not found
     */
    public function remove( string $id ): bool {
        $id = sanitize_key( $id );

        if ( isset( $this->protectors[ $id ] ) ) {
            // Optionally unprotect before removing
            $this->protectors[ $id ]->unprotect();
            unset( $this->protectors[ $id ] );

            return true;
        }

        return false;
    }

    /**
     * Get all registered protectors
     *
     * Returns all currently registered protector instances.
     *
     * @return array<string, Protector> Array of id => Protector instance
     */
    public function get_all(): array {
        return $this->protectors;
    }

    /**
     * Process auto-protect configurations
     *
     * Automatically protects folders configured with auto_protect.
     *
     * @return void
     */
    public function process_auto_protect(): void {
        foreach ( $this->protectors as $protector ) {
            $config = $protector->get_config();
            if ( ! empty( $config['auto_protect'] ) ) {
                $protector->protect();
            }
        }
    }

    /**
     * Setup upload filters for all registered protectors
     *
     * Configures upload filtering based on protector configurations.
     *
     * @return void
     */
    public function setup_upload_filters(): void {
        foreach ( $this->protectors as $protector ) {
            $config = $protector->get_config();

            if ( empty( $config['upload_filter'] ) ) {
                continue;
            }

            $filters = is_array( $config['upload_filter'] ) && ! is_callable( $config['upload_filter'] )
                    ? $config['upload_filter']
                    : [ $config['upload_filter'] ];

            foreach ( $filters as $filter ) {
                $protector->setup_upload_filter( $filter );
            }
        }
    }

    /**
     * Setup admin notices for a protector
     *
     * Configures admin notices to show on specified pages.
     *
     * @param string $id    Protected folder identifier
     * @param array  $pages Admin page slugs
     *
     * @return void
     */
    private function setup_admin_notices( string $id, array $pages ): void {
        add_action( 'admin_notices', function () use ( $id, $pages ) {
            // Check if we're on one of the specified pages
            $current_page = $_GET['page'] ?? '';
            if ( ! in_array( $current_page, $pages, true ) ) {
                return;
            }

            // Check user capability
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $protector = $this->get( $id );
            if ( ! $protector ) {
                return;
            }

            // Don't show if protected
            if ( $protector->is_protected() ) {
                return;
            }

            // Get server-specific instructions
            $instructions = $protector->get_server_instructions();

            // Display protection warning
            $this->display_protection_warning( $id, $instructions );
        } );
    }

    /**
     * Display protection warning
     *
     * Shows an admin notice with protection status and instructions.
     *
     * @param string $id           Protected folder identifier
     * @param array  $instructions Server instructions from protector
     *
     * @return void
     */
    private function display_protection_warning( string $id, array $instructions ): void {
        $class = $instructions['type'] === 'apache' ? 'notice-error' : 'notice-warning';
        ?>
        <div class="notice <?php echo esc_attr( $class ); ?>">
            <p>
                <strong><?php echo esc_html( sprintf( __( '%s File Protection:', 'arraypress' ), ucfirst( $id ) ) ); ?></strong>
                <?php echo esc_html( $instructions['title'] ); ?>
            </p>

            <?php if ( ! empty( $instructions['instructions'] ) ) : ?>
                <p><?php echo esc_html( $instructions['instructions'] ); ?></p>
            <?php endif; ?>

            <?php if ( ! empty( $instructions['code'] ) ) : ?>
                <pre style="background: #f0f0f0; padding: 10px; overflow-x: auto;"><?php echo esc_html( $instructions['code'] ); ?></pre>
            <?php endif; ?>

            <?php if ( ! empty( $instructions['checklist'] ) ) : ?>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ( $instructions['checklist'] as $item ) : ?>
                        <li><?php echo esc_html( $item ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ( ! empty( $instructions['notes'] ) ) : ?>
                <p><em><?php echo esc_html( $instructions['notes'] ); ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get all registered IDs
     *
     * Returns an array of all registered protected folder IDs.
     *
     * @return string[] Array of registered IDs
     */
    public function get_ids(): array {
        return array_keys( $this->protectors );
    }

    /**
     * Count registered protectors
     *
     * Returns the total number of registered protectors.
     *
     * @return int Number of registered protectors
     */
    public function count(): int {
        return count( $this->protectors );
    }

    /**
     * Clear all protectors
     *
     * Removes all registered protectors from the registry.
     * Primarily useful for testing or complete reinitialization.
     *
     * @return self Returns self for method chaining
     */
    public function clear(): self {
        $this->protectors = [];

        return $this;
    }

    /**
     * Reset the singleton instance
     *
     * Clears the singleton instance, forcing creation of a new one on next access.
     * Should only be used for testing purposes.
     *
     * @return void
     * @internal
     */
    public static function reset(): void {
        self::$instance = null;
    }

}