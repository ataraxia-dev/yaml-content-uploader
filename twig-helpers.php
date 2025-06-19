<?php

namespace YAML_Content_Uploader;

use Twig\Loader\ArrayLoader;
use Twig\Environment;

class Closure_Object {
    public function __construct( $lambda ) {
        $this->__obj__ = (object) [];
        $this->__obj__->lambda = $lambda;
    }

    public function __call( $key, $args ) {
        $lambda = $this->__obj__->lambda;
        return $lambda( $key, $args );
    }
}

class Twig_YAML {
    public function __call( $key, $args ) {
        $front = $key;
        return new Closure_object( function( $key, $args ) use ( $front ) {
            $back = $key;
            return YAML_Content_Paths::resolvePathToContent( $front . '/' . $back );
        } );
    }

    public function uploaded( $path ) {
        $content = YAML_Content_Paths::resolvePathToContent( $path );
        if( NULL === $content ) {
            \WP_CLI::warning( "Content not found for path $path." );
            $content = (object) array();
        }
        $title   = null;
        $type    = null;
        $subtype = null;
        $ID      = null;
        $menu_type = null;
        $link    = null;
        if( isset( $content->ID ) ) {
            $title = $content->post_title;
            $type  = 'post';
            $subtype = $content->post_type;
            $menu_type = 'post_type';
            $ID = $content->ID;
            $link = get_the_permalink( $ID );
        }
        else if ( isset( $content->term_id ) ) {
            $title = $content->name;
            $type  = 'term';
            $subtype = $content->taxonomy;
            $menu_type = 'taxonomy';
            $ID = $content->term_id;
            $link = get_term_link( $ID );
        }
        $content->toAssociation = json_encode( [
            'type' => $type,
            'subtype' => $subtype,
            'id' => $ID,
            'value' => $type . ':' . $subtype . ':' . $ID
        ] );
        $content->toRawMenuItem = [
            'menu-item-title' => $title,
            'menu-item-object-id' => $ID,
            'menu-item-object' => $subtype,
            'menu-item-status' => 'publish',
            'menu-item-type'   => $menu_type
        ];
        $content->toMenuItem = json_encode( $content->toRawMenuItem );
        $content->toLink = str_replace( home_url(), "", $link );
        return $content;
    }

    function call() {
        $args = func_get_args();
        $name = array_shift( $args );
        return call_user_func_array( $name, $args );
    }
}

class Twig_Helper {
    public static function parseYAMLTemplate( $path ) {
        if( ! file_exists( $path ) ) {
            echo "Oh no! Tried to load a nonexistent file!\r\n";
        }
        $loader = new \Twig\Loader\ArrayLoader( [
            basename( $path ) => file_get_contents( $path ),
        ] );
        $twig = new \Twig\Environment( $loader, [ 'autoescape' => false ] );
        $twig = apply_filters( 'yaml/twig', $twig );
        $template = $twig->load( basename( $path ) );
        $context  = [
            'yaml' => new Twig_YAML(),
        ];
        $context  = apply_filters( 'yaml/twig/context', $context );
        return $template->render( $context );
    }
}
