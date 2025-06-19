<?php

namespace YAML_Content_Uploader;

use Symfony\Component\Yaml\Yaml;

if( ! defined( 'ABSPATH' ) ) {
    wp_die( "No ABSPATH." );
}

class YAML_Content_Paths {
    public static function resolveFilepathToShortpath( $filepath ) {
        $basename = basename( $filepath );
        if( preg_match( '%theme.([a-z-_]+.)?(gen\.)?(json|yaml)(.twig)?%', $basename ) ) {
            return 'theme';
        }
        else {
            $exp = '%^.*/([^/]+/[-a-z0-9]+)\.(gen\.)?(yaml|json)(\.twig)?$%';
            if( ! preg_match( $exp, $filepath ) ) {
                \WP_CLI::error( "Looks like we got an invalid filepath: $filepath" );
                exit( 1 );
            }
            return preg_replace(
                '%^.*/([^/]+/[-a-z0-9]+)\.(gen\.)?(yaml|json)(\.twig)?$%',
                '$1',
                $filepath
            );
        }
    }

    public static function resolvePathToClass( $path ) {
        list( $front, $back ) = explode( '/', $path );
        $type = str_replace( '-', '_', $front );
        $type_map = (new YAML_Content_Uploader)->type_map;
        if( ! isset( $type_map[$type] ) ) {
            if( in_array( $type, get_post_types() ) ) {
                $class = $type_map['post'];
            }
            else if( in_array( $type, get_taxonomies() ) ) {
                $class = $type_map['term'];
            }
            else {
                \WP_CLI::error(
                    "Looks like we don't have an upload class specifically for " .
                    "\"$front\", nor do we have any matching post types or " .
                    "taxonomies."
                );
                exit( 1 );
            }
        }
        else {
            $class = $type_map[$type];
        }
        return $class;
    }

    public static function resolvePathToContent( $path ) {
        $type_map = (new YAML_Content_Uploader)->type_map;
        $class = YAML_Content_Paths::resolvePathToClass( $path );
        return (new $class( $path, null, [] ))->retrieve();
    }

    public static function resolvePathToFile( $path ) {
        $dir = self::locateYAMLDir();
        if( null === $dir ) {
            wp_die( "Couldn't resolve the directory used for YAML files!" );
        }
        $fullPath = $dir . '/' . $path . '.yaml';
        if( ! file_exists( $fullPath ) ) {
            wp_die( "Oh no! We couldn't find that path!" );
        }
        return $fullPath;
    }

    public static function locateYAMLDir( $fromDir = null ) {
        $yamlDir = null;
        if( null === $fromDir ) {
            $fromDir = getcwd();
        }
        // check to see if we're in a theme folder
        foreach( wp_get_themes() as $slug => $theme ) {
            $root = $theme->stylesheet_dir;
            $compareTo = substr( $fromDir, 0, strlen( $root ) );
            if( $root === $compareTo ) {
                $candidateDir = $root . '/yaml';
                if( is_dir( $candidateDir ) ) {
                    $yamlDir = $candidateDir;
                }
                break;
            }
        }
        // check to see if we're facing a yaml directory
        if( ! $yamlDir ) {
            $target = $fromDir . "/yaml";
            if( is_dir( $target ) ) {
                $yamlDir = $target;
            }
        }
        return $yamlDir;
    }
}

class YAML_Content_Uploader {
    public function __construct() {
        $this->uploads  = [];
        $this->type_map = [
            'post'       => '\YAML_Content_Uploader\YAML_Post_Upload',
            'nav'        => '\YAML_Content_Uploader\YAML_Nav_Upload',
            'theme'      => '\YAML_Content_Uploader\YAML_Theme_Upload',
            'attachment' => '\YAML_Content_Uploader\YAML_Attachment_Upload',
            'image'      => '\YAML_Content_Uploader\YAML_Attachment_Upload',
            'product'    => '\YAML_Content_Uploader\YAML_WooCommerce_Product_Upload',
            'term'       => '\YAML_Content_Uploader\YAML_Term_Upload'
        ];
    }

    public function __invoke( $args, $assoc_args ) {
        $command = array_shift( $args );
        switch( $command ) {
        case 'add':
        case 'update':
        case 'upload':
            $this->add( $args );
            break;
        case 'parse':
            $this->parse( $args );
            break;
        case 'fetchID':
        case 'getID':
            $shortpath = YAML_Content_Paths::resolveFilepathToShortpath( $args[0] );
            $content = YAML_Content_Paths::resolvePathToContent( $shortpath );
            if( isset( $content->term_id ) ){
                echo $content->term_id;
            }
            else if( isset( $content->ID ) ){
                echo $content->ID;
            }
            break;
        case 'assign':
            if( sizeof( $args ) !== 2 ) {
                \WP_CLI::error(
                    'Not enough arguments for the assignment!'
                );
            }
            $p  = array_shift( $args );
            $ID = array_shift( $args );
            $path  = YAML_Content_Paths::resolveFilepathToShortPath( $p );
            $class = YAML_Content_Paths::resolvePathToClass( $path );
            $instance = new $class( $p, [], [] );
            $instance->set_ID( $ID );
            $instance->write();
            break;
        default:
            \WP_CLI::error( "$command: Not a valid command!" );
        }
    }

    private function parse( $paths ) {
        if( ! is_array( $paths ) ) {
            $paths = [ $paths ];
        }
        foreach( $paths as $p ) {
            $p = realpath( $p );
            $ext = pathinfo( $p, PATHINFO_EXTENSION );
            if( 'twig' === $ext ) {
                $parsed = Twig_Helper::parseYAMLTemplate( $p );
            }
        }
        echo $parsed;
    }

    private function add( $paths ) {
        if( ! is_array( $paths ) ) {
            $paths = [ $paths ];
        }
        if( 0 === sizeof( $paths ) ) {
            \WP_CLI::error(
                "No paths given, exiting now!"
            );
            exit( 1 );
        }
        else if( 1 === sizeof( $paths ) ) {
            \WP_CLI::log(
                "Adding content at path {$paths[0]}..."
            );
        }
        else {
            \WP_CLI::log(
                "Adding content at multiple paths..."
            );
        }
        foreach( $paths as $p ) {
            $p = realpath( $p );
            $ext = pathinfo( $p, PATHINFO_EXTENSION );
            if( 'twig' === $ext ) {
                $parsed = Twig_Helper::parseYAMLTemplate( $p );
                $data = Yaml::parse( $parsed );
            }
            else if( 'json' === $ext ) {
                $data = json_decode( file_get_contents( $p ), true );
            }
            else {
                $data = Yaml::parseFile( $p );
            }
            if( NULL === $data ) {
                $data = [];
            }
            $path  = YAML_Content_Paths::resolveFilepathToShortPath( $p );
            $class = YAML_Content_Paths::resolvePathToClass( $path );
            $this->uploads[] = new $class( $path, $data, [] );
        }
        foreach( $this->uploads as $u ) {
            if( $u->retrieve() ) {
                \WP_CLI::log(
                    "Replacing content at path "
                    . "{$u->path}..."
                );
            }
            else {
                \WP_CLI::log(
                    "Writing new content at path "
                    . "{$u->path}..."
                );
            }
            $u->write();
        }
    }
}

abstract class YAML_Upload {
    public function __construct( $path, $data = null, $options = array() ) {
        $this->path = $path;
        $this->data = $data;
        $this->options = $options;
    }

    abstract public function retrieve();

    abstract public function write();

    public function update( $data ) {
        $this->data = $data;
        $this->write();
    }

    protected function set_ID( $ID ) {
        $this->ID = $ID;
    }
}

class YAML_Term_Upload extends YAML_Upload {
    public function __construct( $path, $data, $options ) {
        parent::__construct( $path, $data, $options );
        list( $front, $back ) = explode( '/', $path );
        $this->options['taxonomy'] = str_replace( '-', '_', $front );
    }
    protected function args() {
        $args = [
            $this->data['title'],
            $this->options['taxonomy'],
            []
        ];
        if( isset( $this->data['parent'] ) ) {
            $args[2] = [ 'parent' => $this->data['parent'] ];
        }
        if( isset( $this->data['description'] ) ) {
            $args[2] = [ 'description' => $this->data['description'] ];
        }
        return $args;
    }

    public function retrieve() {
        if( isset( $this->ID ) && get_term( $this->ID ) ) {
            $term = get_term( $this->ID );
        }
        else {
            $taxonomy = $this->options['taxonomy'];
            $terms = get_terms( $taxonomy, [
                'hide_empty' => false,
            ] );
            foreach( $terms as $t ) {
                $meta = get_term_meta( $t->term_id, '_yaml_content_path', true );
                if( $meta && $meta === $this->path) {
                    $term = $t;
                    break;
                }
            }
        }
        return $term;
    }

    public function write() {
        $args = $this->args();
        if( ! $this->retrieve() ) {
            $ID = wp_insert_term( $args[0], $args[1], $args[2] );
            if( isset( $ID->errors ) ) {
                \WP_CLI::error( "Failed to upload term: " . json_encode( $ID->errors ) . " " . json_encode( $args ) . " " . $this->path );
                exit( 1 );
            }
            else if( $ID ) {
                $this->set_ID( $ID['term_id'] );
                update_term_meta( $this->ID, "_yaml_content_path", $this->path );
            }
            else {
                \WP_CLI::error(
                    "Failed to import {$this->path}!"
                );
                exit( 1 );
            }
        }
        else {
            wp_update_term( $this->retrieve()->term_id, $args[1], $args[2] );
        }
        $this->add_carbon();
        $this->add_meta();
    }

    protected function add_meta() {
        if( isset( $this->data['meta'] ) ) {
            foreach( $this->data['meta'] as $key => $value ) {
                update_term_meta( $this->retrieve()->term_id, $key, $value );
            }
        }
    }

    protected function add_carbon() {
        if( isset( $this->data['carbon'] ) ) {
            foreach( $this->data['carbon'] as $key => $value ) {
                $key = 'crb_' . $key;
                carbon_set_term_meta(
                    $this->retrieve()->term_id,
                    $key,
                    $value
                );
                if( $value && ! carbon_get_term_meta( $this->retrieve()->term_id, $key ) ) {
                    \WP_CLI::warning(
                        "Uh-oh! Issue setting $key for $this->path..."
                    );
                }
            }
        }
    }
}

class YAML_Post_Upload extends YAML_Upload {
    public function __construct( $path, $data, $options ) {
        if( null === $data ) {
            $data = []; // stub upload
        }
        parent::__construct( $path, $data, $options );
        list( $front, $back ) = explode( '/', $path );
        if( ! isset( $this->options['post_type'] ) ) {
            $this->options['post_type'] = str_replace( '-', '_', $front );
        }
    }

    protected function args() {
        $args = [];
        $shortkeys = [ 'content', 'title', 'status' ];
        foreach( $this->data as $key => $value ) {
            if( in_array( $key, $shortkeys ) ) {
                $key = 'post_' . $key;
            }
            $args[$key] = $value;
        }
        if( ! isset( $args['post_status'] ) ) {
            $args['post_status'] = 'publish';
        }
        if( isset( $args['meta'] ) ) {
            $args['meta_input'] = $args['meta'];
        }
        if( isset( $this->options['post_type'] ) ) {
            $args['post_type'] = $this->options['post_type'];
        }
        if( $this->retrieve() ) {
            $args['ID'] = $this->retrieve()->ID;
        }
        unset( $args['carbon'] );
        unset( $args['meta'] );
        unset( $args['term'] );
        return $args;
    }

    protected function add_carbon() {
        if( isset( $this->data['carbon'] ) ) {
            foreach( $this->data['carbon'] as $key => $value ) {
                $key = 'crb_' . $key;
                carbon_set_post_meta(
                    $this->retrieve()->ID,
                    $key,
                    $value
                );
                if( $value && ! carbon_get_post_meta( $this->retrieve()->ID, $key ) ) {
                    \WP_CLI::warning(
                        "Uh-oh! Issue setting $key for $this->path..."
                    );
                }
            }
        }
    }

    protected function add_terms() {
        if( isset( $this->data["terms"] ) ) {
            foreach( $this->data["terms"] as $key => $value ) {
                wp_set_post_terms( $this->ID, $value, $key );
            }
        }
    }

    protected function add_path() {
        update_post_meta(
            $this->retrieve()->ID,
            '_yaml_content_path',
            $this->path
        );
    }

    public function retrieve() {
        $post = null;
        if( isset( $this->ID ) && get_post( $this->ID ) ) {
            $post = get_post( $this->ID );
        }
        else {
            $posts = get_posts( [
                'post_type'  => $this->options['post_type'],
                'meta_query' => [
                    [
                        'key' => '_yaml_content_path',
                        'value' => $this->path
                    ]
                ]
            ] );
            if( is_array( $posts ) && sizeof( $posts ) > 0 ) {
                $post = $posts[0];
            }
        }
        return $post;
    }

    public function write() {
        $ID = wp_insert_post( $this->args() );
        if( $ID ) {
            $this->set_ID( $ID );
        }
        else {
            \WP_CLI::error(
                "Failed to insert {$this->path}!"
            );
            exit( 1 );
        }
        $this->add_carbon();
        $this->add_terms();
        $this->add_path();
        $type = $this->args()['post_type'];
        do_action( 'yaml/' . $type . '/write', $this );
    }
}

class YAML_Theme_Upload extends YAML_Upload {
    public function write() {
        foreach( $this->data as $key => $value ) {
            $key = 'crb_' . $key;
            carbon_set_theme_option( $key, $value );
        }
    }

    public function retrieve() {
        return NULL;
    }
}

// credit where credit is due to
// https://wordpress.stackexchange.com/a/256834
class YAML_Attachment_Upload extends YAML_Post_Upload {
    public function __construct( $path, $data, $options ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        parent::__construct( $path, $data, ['post_type'=>'attachment'] );
    }

    protected function args() {
        $args = parent::args();
        $file_type = wp_check_filetype( $this->get_file_basename(), null );
        $args = wp_parse_args( [
            'post_mime_type' => $file_type['type'],
            'post_status' => 'inherit'
        ], $args );
        unset( $args['attachment'] );
        return $args;
    }

    private function get_file_basename() {
        if( isset( $this->data['attachment']['filepath'] ) ) {
            return basename( $this->data['attachment']['filepath'] );
        }
        else {
            $parsed = parse_url( $this->data['attachment']['url'], PHP_URL_PATH );
            return basename( $parsed );
        }
    }

    private function get_file_source_path() {
        $dir = YAML_Content_Paths::locateYAMLDir();
        if( isset( $this->data['attachment']['filepath'] ) ) {
            if( '/' === $this->data['attachment']['filepath'][0] ) {
                $file_path_src = $this->data['attachment']['filepath'];
            }
            else {
                $file_path_src = realpath(
                    $dir . '/' . dirname( $this->path ) . '/' . $this->data['attachment']['filepath']
                );
            }
        }
        else {
            $file_path_src = $this->data['attachment']['url'];
        }
        return $file_path_src;
    }

    private function get_file_upload_path() {
        $upload_dir = wp_upload_dir();
        $file_name  = $this->get_file_basename();
        if( $this->retrieve()
            && get_attached_file( $this->retrieve()->ID ) ) {
            $file_path_dest = get_attached_file( $this->retrieve()->ID );
        }
        else {
            if( wp_mkdir_p( $upload_dir['path'] ) ) {
                $file_path_dest = $upload_dir['path'] . '/' . $file_name;
            }
            else {
                $file_path_dest = $upload_dir['basedir'] . '/' . $file_name;
            }
            // if there's already a file there, we'll keep adding underscores
            // to our filename until we have a unique name.
            while( file_exists( $file_path_dest ) ) {
                $basename = basename( $file_path_dest );
                $dir = dirname( $file_path_dest );
                $file_path_dest = $dir . '/_' . $basename;
            }
        }
        return $file_path_dest;
    }

    // the reason we use a checksum instead of just comparing the files
    // directly is that WordPress processes images, modifying even those
    // it doesn't resize.
    private function is_same_as_existing() {
        $file_name = $this->get_file_upload_path();
        $file_src_contents = file_get_contents( $this->get_file_source_path() );
        $checksum = get_post_meta( $this->ID, '_yaml_content_src_checksum', true );
        return file_exists( $file_name ) && $checksum === md5( $file_src_contents );;
    }

    private function upload() {
        if( ! isset( $this->data['attachment'] ) || 
            ! ( isset( $this->data['attachment']['filepath'] ) ||
                isset( $this->data['attachment']['url'] ) ) ) {
            \WP_CLI::error(
                "Attachment with path $this->path is missing filepath or url metadata, " .
                "refusing to upload."
            );
            exit( 1 );
        }
        $file_name = $this->get_file_upload_path();
        $file_src_contents = file_get_contents( $this->get_file_source_path() );
        file_put_contents( $file_name, $file_src_contents );
    }

    public function write() {
        $ID = wp_insert_attachment( $this->args(), $this->get_file_upload_path() );
        if( $ID ) {
            $this->set_ID( $ID );
        }
        else {
            WP_CLI::error(
                "Failed to insert or re-insert {$this->path}!"
            );
            exit( 1 );
        }
        // don't re-do the image rewrites if the image is the same
        // this will save us a lot of time when mass-running the script!
        if( ! $this->is_same_as_existing() || ! $this->ID ) {
            $this->upload();
            $attach_data = wp_generate_attachment_metadata(
                $ID, $this->get_file_upload_path()
            );
            wp_update_attachment_metadata( $ID, $attach_data );
            update_post_meta(
                $ID, '_yaml_content_src_checksum', md5(
                    file_get_contents( $this->get_file_source_path() )
                )
            );
        }
        if( isset( $this->data['attachment']['alt'] ) ) {
            update_post_meta(
                $ID, '_wp_attachment_image_alt', $this->data['attachment']['alt'] 
            );
        }
        $this->add_path();
    }
}

class YAML_Nav_Upload extends YAML_Term_Upload {
    public function __construct($path, $data, $options) {
        parent::__construct($path, $data, $options);
        $this->options = ['taxonomy'=>'nav_menu'];
    }

    protected function args() {
        return $this->data['menu_items'];
    }

    public function write() {
        $existing_menu_items = [];
        if( $this->retrieve() ) {
            $ID = $this->retrieve()->term_id;
            $existing_menu_items = wp_get_nav_menu_items( $ID );
        }
        else {
            $ID = wp_create_nav_menu( $this->data['title'] );
            if( isset( $ID->errors ) ) {
                \WP_CLI::error( "Couldn't create that menu! " . json_encode( $ID->errors ) );
                exit( 1 );
            }
            update_term_meta( $ID, '_yaml_content_path', $this->path );
        }
        foreach( $this->args() as $index => $item ) {
            $item_id = 0;
            $carbon  = isset( $item['carbon'] ) ? $item['carbon'] : [];
            unset( $item['carbon'] );
            // overwrite existing menu items if possible
            if( isset( $existing_menu_items[ $index ] ) ) {
                $item_id = $existing_menu_items[ $index ]->ID;
            }
            wp_update_nav_menu_item( $ID, $item_id, $item );
            foreach( $carbon as $key => $value ) {
                carbon_set_nav_menu_item_meta( $item_id, 'crb_' . $key, $value );
            }
        }
        while( isset( $existing_menu_items[ $index + 1 ] ) ) {
            // clean up extra existing menu items
            $index = $index + 1;
            $item  = $existing_menu_items[ $index ];
            wp_delete_post( $item->ID );
        }
        if( isset( $this->data['menu_location'] ) ) {
            $menu_location = $this->data['menu_location'];
            $locations = get_theme_mod( 'nav_menu_locations' );
            $locations[$menu_location] = $ID;
            set_theme_mod( 'nav_menu_locations', $locations );
        }
    }
}

class YAML_WooCommerce_Product_Upload extends YAML_Post_Upload {
    public function write() {
        $args = $this->args();
        if( $this->retrieve() ) {
            $product = wc_get_product( $this->retrieve()->ID );
        }
        else {
            $product = new \WC_Product();
        }
        $product->set_name( $args['post_title'] );
        if( isset( $args["product"]["price"] ) ) {
            $product->set_regular_price( $args["product"]["price"] );
        }
        if( isset( $args["product"]["sku"] ) ) {
            $product->set_sku( $args["product"]["sku"] );
        }
        $product->save();
        $this->set_ID( $product->get_ID() );
        if( isset( $args["product"]["images"] ) ) {
            update_post_meta( $this->ID, "_product_image_gallery", implode(
                ",", $args["product"]["images"]
            ) );
        }
        if( isset( $args['product']['variation'] ) ) {
            $variation_options = $args['product']["variation"];
            wp_set_object_terms( $this->ID, "variable", "product_type" );
            // fetch again as a variable product
            $product = wc_get_product( $product->get_ID() );
            if( isset( $variation_options["attributes"] ) ) {
                $options = [];
                foreach( $variation_options["attributes"] as $set ) {
                    $name = sanitize_title( $set["name"] );
                    $options[ $name ] = [
                        'name'  => $set["name"],
                        'value' => implode( " | ", $set["values"] ),
                        'is_visible'   => 1,
                        'is_variation' => 1,
                        'is_taxonomy'  => 0
                    ];
                }
                update_post_meta( $this->ID, '_product_attributes', $options );
            }
            \WC_Product_Variable::sync( $this->ID );
            if( isset( $variation_options["variations"] ) ) {
                $existing_variations = $product->get_available_variations();
                foreach( $variation_options["variations"] as $index => $variation ) {
                    $attributes = [];
                    $args = [
                        'post_title'  => $product->get_name(),
                        'post_name'   => 'product-' . $product->get_ID() . '-variation',
                        'post_status' => 'publish',
                        'post_type'   => 'product_variation',
                        'guid'        => $product->get_permalink()
                    ];
                    if( isset( $existing_variations[ $index ] ) ) {
                        $ID = $existing_variations[ $index ]['variation_id'];
                        $args['ID'] = $ID;
                    }
                    $variation_id = wp_insert_post( $args );
                    $child = new \WC_Product_Variation( $variation_id );
                    $child->set_name( $product->get_name() );
                    $child->set_parent_id( $product->get_ID() );
                    if( isset( $variation['image'] ) ) {
                        $child->set_image_id( $variation['image'] );
                    }
                    if( isset( $variation['sku'] ) ) {
                        $child->set_sku( $variation['sku'] );
                    }
                    foreach( $variation['attributes'] as $set ) {
                        $tax_name = sanitize_title( $set["name"] );
                        if( ! taxonomy_exists( $tax_name ) ) {
                            register_taxonomy( $tax_name, 'product_variation', [
                                'hierarchical' => false,
                                'label'     => $set["name"],
                                'query_var' => true,
                                'rewrite'   => [ 'slug' => sanitize_title( $set["name"] ) ]
                            ] );
                        }
                        if( ! term_exists( $set["value"], $tax_name ) ) {
                            wp_insert_term( $set["value"], $tax_name );
                        }
                        $term_slug = get_term_by( 'name', $set["value"], $tax_name )->slug;
                        $post_term_names = wp_get_post_terms( $product->get_ID(), $tax_name );
                        wp_set_post_terms( $product->get_ID(), $set["value"], $tax_name, true );
                        $attributes['attribute_' . $tax_name] = $set["value"];
                    }
                    $child->set_attributes( $attributes );
                    if( isset( $variation["price"] ) ) {
                        $child->set_regular_price( $variation["price"] );
                    }
                    $child->save();
                }
                while( isset( $existing_variations[ $index + 1 ] ) ) {
                    $index = $index + 1;
                    $ID = $existing_variations[ $index ]['variation_id'];
                    wp_delete_post( $ID );
                }
            }
        }
        parent::write();
    }

    protected function args() {
        $args = parent::args();
        if( ! isset( $args["product"] ) ) {
            $args["product"] = [];
        }
        return $args;
    }
}
