<?php
/*
Plugin Name: Responsive Images for Divi
Plugin URI: https://www.fldtrace.com/better-faster-responsive-images-divi
Description: Better, Faster, Responsive Images for Divi using the HTML &lt;img&gt; srcset attribute
Version: 1.0
Author: Gifford Nowland
Author URI: https://giffordnowland.com/
License: GPL2
*/
namespace divi_srcset;

/**
 * Better, Faster, Responsive Images for Divi
 * @author Lucian Florian
 * @link   https://www.fldtrace.com/better-faster-responsive-images-divi
 * @link   https://gist.github.com/fldtrace/b75fe97c9a60270dd672566b796be936
 */

// Check for Divi, bail if not divi
if( get_option( 'template' ) !== 'Divi' ) {
  return;
}

// enable divi functions
function my_enqueue_assets() {
  wp_enqueue_style( 'parent-style', get_template_directory_uri().'/style.css' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\my_enqueue_assets' );

//add 1440px image size
add_image_size('image_1440', 1440, 9999, false);

//move the 'wp_make_content_images_responsive' filter to run last
remove_filter( 'the_content', 'wp_make_content_images_responsive', 10);
add_filter( 'the_content', 'wp_make_content_images_responsive', 1600, 1);

//filter the content and add wp-image-$id class to the images, allowing responsive feature to work
function hb_add_id_to_images( $content ) {
  global $wpdb;
  if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) ) {
    return $content;
  }

  foreach( $matches[0] as $image ) {
    if ( !preg_match( '/wp-image-([0-9]+)/i', $image ) ) {
      $dom = new DOMDocument();
      $dom->loadHTML($image);
      $img_element = $dom->getElementsByTagName('img')->item(0);
      
      $wp_upload_dir = wp_upload_dir();
      $image_path = str_replace(trailingslashit(preg_replace("(^https?://)", "", $wp_upload_dir['baseurl'])), '', preg_replace("(^https?://)", "", $img_element->getAttribute('src') ));
      
      $attachment = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND BINARY meta_value='%s';", $image_path )); 
      
      if ($attachment) {
        $img_element->setAttribute("class", "wp-image-".$attachment[0]);
        $new_image = $img_element->ownerDocument->saveHTML($img_element);
        
        $content = str_replace ( $image , $new_image , $content);
      }
    }
  }

  return $content;
}
add_filter( 'the_content', __NAMESPACE__ . '\hb_add_id_to_images', 1599, 1);

//lower image max-width to 1080px everywhere (retina compatible)
function hb_content_image_sizes_attr( $sizes, $size ) {
  $width = $size[0];
  
  if ($width >= 1080) $sizes = '(max-width: 1080px) 100vw, 1080px';

  return $sizes;
}
add_filter( 'wp_calculate_image_sizes', __NAMESPACE__ . '\hb_content_image_sizes_attr', 10 , 2 );


//override Divi 'et_pb_maybe_add_advanced_styles' function to fix the problem of browser downloading overridden background image
function hb_pb_maybe_add_advanced_styles() {
  $styles = array();

  // do not output advanced css if Frontend Builder is active
  if ( ! et_fb_is_enabled() ) {
    $styles['et-builder-advanced-style'] = ET_Builder_Element::get_style();
    
    if ( preg_match_all('/\.[_a-z0-9]+\.et_pb_fullwidth_header \{.*background-image.*\}/', $styles['et-builder-advanced-style'], $matches ) ) {
      foreach( $matches[0] as $bg_css ) {
        $styles['et-builder-advanced-style'] = preg_replace('/url\(.*\)/', 'none', $styles['et-builder-advanced-style']);
      }
    }
        
    $styles['et-builder-page-custom-style'] = et_pb_get_page_custom_css();
  }

  foreach( $styles as $id => $style_data ) {
    if ( ! $style_data ) {
      continue;
    }

    printf(
      '<style type="text/css" id="%2$s">
        %1$s
      </style>',
      $style_data,
      esc_attr( $id )
    );
  }
  
  remove_action( 'wp_footer', 'et_pb_maybe_add_advanced_styles' );
}
add_action( 'wp_footer', __NAMESPACE__ . '\hb_pb_maybe_add_advanced_styles', 9 );


//add responsiveness for background images
function hb_responsive_bg_image() {
  global $wpdb;
  
  $css = ET_Builder_Element::get_style();


  //find the background-image css in the inline css	
  if ( preg_match_all('/\.[_a-z0-9]+\.et_pb_fullwidth_header \{.*background-image.*\}/', $css, $matches ) ) {
    foreach( $matches[0] as $bg_css ) {
      if (preg_match('/\b(?:(?:https?|ftp|file):\/\/|www\.|ftp\.)[-A-Z0-9+&@#\/%=~_|$?!:,.]*[A-Z0-9+&@#\/%=~_|$]/i', $bg_css, $url_matches)) {
        $url = $url_matches[0];
        
        $wp_upload_dir = wp_upload_dir();
        
        
        $image_path = str_replace(trailingslashit(preg_replace("(^https?://)", "", $wp_upload_dir['baseurl'])), '', preg_replace("(^https?://)", "", $url ));
        
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_wp_attached_file' AND BINARY meta_value='%s';", $image_path )); 
        
        $bg_css = preg_replace('/background-color([^;]*);/', '', $bg_css, 1);
        
        if ($attachment) {
          $image_meta = wp_get_attachment_metadata($attachment[0]);
          
          $extra_css = '';


          //add fullsize background image style, fixing the problem of browser downloading overridden background image
          $extra_css .= '
            @media only screen and ( min-width: 1441px ) {
              ' . $bg_css . '
            }';
          
          //add responsive background image for (non-retina) screen with max-width 1440px (use 1440px image), 1080px (use 1080px image), 768px (use 768px image)
          if ($image_meta['sizes']['image_1440']) {
            $extra_css .= '
            @media only screen and ( max-width: 1440px ) {
              ' . str_replace(basename($url), $image_meta['sizes']['image_1440']['file'], $bg_css) . '
            }';
          }

          if ($image_meta['sizes']['et-pb-portfolio-image-single']) {
            $extra_css .= '
            @media only screen and ( max-width: 1080px ) {
              ' . str_replace(basename($url), $image_meta['sizes']['et-pb-portfolio-image-single']['file'], $bg_css) . '
            }';
          }
          
          if ($image_meta['sizes']['medium_large']) {
            $extra_css .= '
            @media only screen and ( max-width: 768px ) {
              ' . str_replace(basename($url), $image_meta['sizes']['medium_large']['file'], $bg_css) . '
            }';
          }
          
          
          //add responsive background image for retina screen with max-width 1440px (use fullsize image), 768px (use 1440px image), 540px (use 1080px image), 384px (use 768px image)
          $extra_css .= '
          @media
          only screen and ( max-width: 1440px ) and (-webkit-min-device-pixel-ratio: 2),
          only screen and ( max-width: 1440px ) and (   min--moz-device-pixel-ratio: 2),
          only screen and ( max-width: 1440px ) and (     -o-min-device-pixel-ratio: 2/1),
          only screen and ( max-width: 1440px ) and (        min-device-pixel-ratio: 2),
          only screen and ( max-width: 1440px ) and (                min-resolution: 192dpi),
          only screen and ( max-width: 1440px ) and (                min-resolution: 2dppx) { 
            ' . $bg_css . '
          }';

          if ($image_meta['sizes']['image_1440']) {
            $extra_css .= '
            @media
            only screen and ( max-width: 768px ) and (-webkit-min-device-pixel-ratio: 2),
            only screen and ( max-width: 768px ) and (   min--moz-device-pixel-ratio: 2),
            only screen and ( max-width: 768px ) and (     -o-min-device-pixel-ratio: 2/1),
            only screen and ( max-width: 768px ) and (        min-device-pixel-ratio: 2),
            only screen and ( max-width: 768px ) and (                min-resolution: 192dpi),
            only screen and ( max-width: 768px ) and (                min-resolution: 2dppx) { 
              ' . str_replace(basename($url), $image_meta['sizes']['image_1440']['file'], $bg_css) . '
            }';
          }

          if ($image_meta['sizes']['et-pb-portfolio-image-single']) {
            $extra_css .= '
            @media
            only screen and ( max-width: 540px ) and (-webkit-min-device-pixel-ratio: 2),
            only screen and ( max-width: 540px ) and (   min--moz-device-pixel-ratio: 2),
            only screen and ( max-width: 540px ) and (     -o-min-device-pixel-ratio: 2/1),
            only screen and ( max-width: 540px ) and (        min-device-pixel-ratio: 2),
            only screen and ( max-width: 540px ) and (                min-resolution: 192dpi),
            only screen and ( max-width: 540px ) and (                min-resolution: 2dppx) { 
              ' . str_replace(basename($url), $image_meta['sizes']['et-pb-portfolio-image-single']['file'], $bg_css) . '
            }';
          }

          if ($image_meta['sizes']['medium_large']) {
            $extra_css .= '
            @media
            only screen and ( max-width: 384px ) and (-webkit-min-device-pixel-ratio: 2),
            only screen and ( max-width: 384px ) and (   min--moz-device-pixel-ratio: 2),
            only screen and ( max-width: 384px ) and (     -o-min-device-pixel-ratio: 2/1),
            only screen and ( max-width: 384px ) and (        min-device-pixel-ratio: 2),
            only screen and ( max-width: 384px ) and (                min-resolution: 192dpi),
            only screen and ( max-width: 384px ) and (                min-resolution: 2dppx) { 
              ' . str_replace(basename($url), $image_meta['sizes']['medium_large']['file'], $bg_css) . '
            }';
          }
          
          ?>
          <style type="text/css" id="responsive-bg-image-style">
          <?php echo $extra_css;?>
          </style>
          <?php
        }
      }
    }
  }
}
add_action('wp_footer', __NAMESPACE__ . '\hb_responsive_bg_image', 10);
