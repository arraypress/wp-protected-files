# WordPress Protected Folders

Complete folder protection and file delivery system for WordPress. Protects upload directories with .htaccess rules, verifies protection is working, and provides secure file streaming with range support and server optimizations.

## Features

- 🔒 **Folder Protection** - Automatic .htaccess and index file creation
- 🚀 **Smart File Delivery** - Streaming with X-Sendfile, range requests, and chunked transfer
- 📁 **Upload Organization** - Auto-organize uploads by post type or custom logic
- ✅ **Protection Testing** - Actually verifies files are protected, not just file presence
- 🎯 **Intelligent Defaults** - PDFs display inline, ZIPs download, videos stream
- ⚡ **Server Optimized** - Supports Apache, Nginx, LiteSpeed with X-Sendfile/X-Accel-Redirect

## Install

```bash
composer require arraypress/wp-protected-folders
```

## Requirements

- PHP 7.4 or later
- WordPress 5.0 or later
- [arraypress/wp-file-utils](https://github.com/arraypress/wp-file-utils) (auto-installed)

## Quick Start

```php
// Register a protected folder
register_protected_folder( 'downloads', [
    'allowed_types' => ['jpg', 'png'],  // Allow preview images
    'dated_folders' => true,            // Organize by year/month
    'auto_protect'  => true,            // Auto-protect on admin_init
    'upload_filter' => 'download'       // Auto-organize download post type uploads
] );

// Serve a protected file (automatically handles everything!)
deliver_protected_file( '/path/to/protected/file.pdf' );
// - PDFs display inline
// - ZIPs force download  
// - Videos stream with range support
// - Uses X-Sendfile when available
```

## Core Functions

### Folder Protection

```php
// Register a protected folder
register_protected_folder( string $id, array $config = [] ): ?Protector

// Get file system path
get_protected_folder_path( string $id, bool $dated = false ): string

// Get public URL
get_protected_folder_url( string $id, bool $dated = false ): string

// Check if protected (tests actual file access)
is_folder_protected( string $id, bool $force = false ): bool

// Get protector instance for advanced operations
get_protected_folder( string $id ): ?Protector
```

### File Delivery

```php
// Deliver a protected file with automatic optimization
deliver_protected_file( string $file_path, array $options = [] ): void

// Create a reusable delivery instance
create_file_delivery( array $options = [] ): Delivery
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `allowed_types` | array | `['jpg', 'jpeg', 'png', 'gif', 'webp']` | File extensions allowed for public access |
| `dated_folders` | bool | `true` | Organize uploads by year/month |
| `auto_protect` | bool | `false` | Automatically protect on admin_init |
| `upload_filter` | mixed | `null` | Post types, admin pages, or callback for upload filtering |
| `admin_notice_pages` | array | `[]` | Admin page slugs to show protection notices |

## File Delivery Features

The delivery system automatically optimizes based on file type:

### Automatic Behavior by File Type

| File Type | Behavior | Chunk Size |
|-----------|----------|------------|
| **PDF** | Display inline | 1MB |
| **Images** | Display inline | 512KB |
| **Video** | Stream with range support | 2MB |
| **Audio** | Stream with range support | 1MB |
| **Archives** | Force download | 4MB |
| **Documents** | Force download | 1MB |

### Delivery Options

```php
deliver_protected_file( $file_path, [
    'filename'       => 'custom-name.pdf',  // Custom download name
    'force_download' => true,                // Override auto-detection
    'enable_range'   => false,               // Disable range support
    'chunk_size'     => 4194304,            // Custom chunk size (4MB)
    'mime_type'      => 'application/pdf'   // Override MIME detection
] );
```

### X-Sendfile Support

Automatically detects and uses X-Sendfile for better performance:

- **Apache**: mod_xsendfile
- **Nginx**: X-Accel-Redirect (requires configuration)
- **LiteSpeed**: X-Sendfile

For Nginx, configure internal location:
```nginx
location /internal/ {
    internal;
    alias /path/to/wp-content/uploads/;
}
```

Then enable in WordPress:
```php
add_filter( 'protected_folders_nginx_xsendfile', '__return_true' );
add_filter( 'protected_folders_nginx_internal_path', function() {
    return '/internal/';
} );
```

## Complete Examples

### Digital Download Plugin

```php
class Download_Manager {
    
    public function __construct() {
        add_action( 'init', [ $this, 'register_protection' ] );
        add_action( 'init', [ $this, 'handle_download' ] );
    }
    
    public function register_protection() {
        register_protected_folder( 'downloads', [
            'allowed_types' => ['jpg', 'png'],      // Preview images only
            'dated_folders' => true,
            'auto_protect'  => true,
            'upload_filter' => 'download'           // Auto-organize uploads
        ] );
    }
    
    public function handle_download() {
        if ( ! isset( $_GET['download_id'] ) ) {
            return;
        }
        
        // Verify purchase, permissions, etc.
        if ( ! $this->verify_access( $_GET['download_id'] ) ) {
            wp_die( 'Access denied' );
        }
        
        // Get file path from your database
        $file_path = $this->get_download_path( $_GET['download_id'] );
        
        // Deliver the file - that's it!
        deliver_protected_file( $file_path );
        // Automatically handles:
        // - MIME type detection
        // - Optimal chunking
        // - Range requests for resume
        // - X-Sendfile when available
    }
}
```

### E-Commerce with Multiple File Types

```php
class Product_Downloads {
    
    public function serve_product_file( $product_id, $file_id ) {
        $file = $this->get_product_file( $product_id, $file_id );
        
        // Different behavior based on file type
        switch ( $file['type'] ) {
            case 'preview':
                // Force inline display for previews
                deliver_protected_file( $file['path'], [
                    'force_download' => false
                ] );
                break;
                
            case 'bonus':
                // Custom filename for bonus content
                deliver_protected_file( $file['path'], [
                    'filename' => "Bonus - {$file['title']}.pdf"
                ] );
                break;
                
            default:
                // Let the system decide (PDF inline, ZIP download, etc.)
                deliver_protected_file( $file['path'] );
        }
    }
}
```

### Membership Site with Streaming

```php
class Member_Content {
    
    public function __construct() {
        // Register protection for different content types
        register_protected_folder( 'courses', [
            'allowed_types' => [],  // No direct access
            'dated_folders' => false,
            'auto_protect'  => true
        ] );
    }
    
    public function stream_video( $lesson_id ) {
        if ( ! $this->has_access( $lesson_id ) ) {
            wp_die( 'Please upgrade your membership' );
        }
        
        $video_path = get_protected_folder_path( 'courses' ) . "/{$lesson_id}.mp4";
        
        // Stream video with range support for seeking
        deliver_protected_file( $video_path );
        // Automatically:
        // - Sends proper video headers
        // - Supports range requests for seeking
        // - Uses 2MB chunks for smooth playback
        // - Enables resume if connection drops
    }
    
    public function download_resources( $resource_id ) {
        $file_path = $this->get_resource_path( $resource_id );
        
        // Force download even for PDFs
        deliver_protected_file( $file_path, [
            'force_download' => true,
            'filename' => $this->get_nice_filename( $resource_id )
        ] );
    }
}
```

### Advanced Delivery Control

```php
// Create reusable delivery instance with custom settings
$delivery = create_file_delivery( [
    'chunk_size'   => 4194304,  // 4MB chunks
    'enable_range' => true       // Always enable range support
] );

// Use for multiple files with same settings
foreach ( $files as $file ) {
    $delivery->stream( $file['path'], [
        'filename' => $file['name']
    ] );
}

// Or use the class directly for full control
use ArrayPress\ProtectedFolders\Delivery;

$delivery = new Delivery();
$delivery->set_option( 'chunk_size', 8388608 );  // 8MB chunks

if ( $delivery->supports_xsendfile() ) {
    // Server supports fast file serving!
}

$delivery->stream( $large_file );
```

## Upload Filtering

Automatically organize uploads to protected folders:

```php
// Single post type
register_protected_folder( 'downloads', [
    'upload_filter' => 'download'
] );

// Multiple post types
register_protected_folder( 'media', [
    'upload_filter' => ['product', 'download']
] );

// Admin page
register_protected_folder( 'settings', [
    'upload_filter' => 'admin:my-settings-page'
] );

// Custom logic
register_protected_folder( 'conditional', [
    'upload_filter' => function() {
        return isset( $_GET['special_upload'] );
    }
] );
```

## Server Configuration

### Apache/LiteSpeed
Works automatically with .htaccess files.

### Nginx
Get rules for manual configuration:
```php
$protector = get_protected_folder( 'downloads' );
echo $protector->get_nginx_rules();
```

### IIS
Get web.config rules:
```php
$protector = get_protected_folder( 'downloads' );
echo $protector->get_iis_rules();
```

## How Protection Works

Each protected folder gets multiple layers of protection:

1. **`.htaccess`** - Server-level denial with optional exceptions
2. **`index.php`** - PHP silence file as fallback
3. **`index.html`** - Empty HTML as additional fallback
4. **Verification** - Actually tests HTTP access to confirm protection

## Performance Optimizations

The delivery system automatically optimizes for performance:

- **X-Sendfile** - Offloads file serving to web server when available
- **Smart Chunking** - Optimal chunk sizes based on file type
- **Range Support** - Enables resume and video seeking
- **Memory Management** - Streams files without loading into memory
- **Output Buffering** - Proper buffer management for large files

## Why Use This?

- **Complete Solution** - Protection + delivery in one package
- **Zero Configuration** - Smart defaults that just work
- **Performance Optimized** - X-Sendfile, chunking, and range support
- **WordPress Native** - Follows WordPress coding standards
- **Battle-Tested** - Used in production e-commerce systems
- **Minimal API** - Simple functions for common tasks
- **Extensible** - Full class access for advanced needs

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

GPL-2.0-or-later

## Credits

Created and maintained by [David Sherlock](https://davidsherlock.com) at [ArrayPress](https://arraypress.com).

## Support

- [Documentation](https://github.com/arraypress/wp-protected-folders)
- [Issue Tracker](https://github.com/arraypress/wp-protected-folders/issues)