<?php

define( 'DISALLOW_FILE_EDIT', true ); //Disable theme and plugin editors

/* BEGIN CUSTOM */

/**
*
* Allows you to calculated the number of days between two Gravity Form date fields and populate that number into a
* field on your Gravity Form.
*
* @version   1.1
* @author    David Smith || Modified by Luciano Nooijen
* @license   GPL-2.0+
* @copyright 2013 Gravity Wiz || 2017 Nooijen Web Solutions
*/
class GWDayCount {
    private static $script_output;
    function __construct( $args ) {
        extract( wp_parse_args( $args, array(
            'form_id'          => false,
            'start_field_id'   => false,
            'end_field_id'     => false,
            'count_field_id'   => false,
            'include_end_date' => true,
            ) ) );
        $this->form_id        = $form_id;
        $this->start_field_id = $start_field_id;
        $this->end_field_id   = $end_field_id;
        $this->count_field_id = $count_field_id;
        $this->count_adjust   = $include_end_date ? 1 : 0;
        add_filter( "gform_pre_render_{$form_id}", array( &$this, 'load_form_script') );
        add_action( "gform_pre_submission_{$form_id}", array( &$this, 'override_submitted_value') );
    }
    function load_form_script( $form ) {
        // workaround to make this work for < 1.7
        $this->form = $form;
        add_filter( 'gform_init_scripts_footer', array( &$this, 'add_init_script' ) );
        if( self::$script_output )
            return $form;
        ?>

        <script type="text/javascript">
        (function($){
            window.gwdc = function( options ) {
                this.options = options;
                this.startDateInput = $( '#input_' + this.options.formId + '_' + this.options.startFieldId );
                this.endDateInput = $( '#input_' + this.options.formId + '_' + this.options.endFieldId );
                this.countInput = $( '#input_' + this.options.formId + '_' + this.options.countFieldId );
                this.init = function() {
                    var gwdc = this;
                    // add data for "format" for parsing date
                    gwdc.startDateInput.data( 'format', this.options.startDateFormat );
                    gwdc.endDateInput.data( 'format', this.options.endDateFormat );
                    gwdc.populateDayCount();
                    gwdc.startDateInput.change( function() {
                        gwdc.populateDayCount();
                    } );
                    gwdc.endDateInput.change( function() {
                        gwdc.populateDayCount();
                    } );
                    $( '#ui-datepicker-div' ).hide();
                }
                this.getDayCount = function() {
                    var startDate = this.parseDate( this.startDateInput.val(), this.startDateInput.data('format') )
                    var endDate = this.parseDate( this.endDateInput.val(), this.endDateInput.data('format') );
                    var dayCount = 0;
                    if( !this.isValidDate( startDate ) || !this.isValidDate( endDate ) )
                        return '';
                    if( startDate > endDate ) {
                        return 0;
                    } else {
                        var diff = endDate - startDate;
                        dayCount = diff / ( 60 * 60 * 24 * 1000 ); // secs * mins * hours * milliseconds
                        dayCount = Math.round( dayCount ) + this.options.countAdjust;
                        return dayCount;
                    }
                }
                this.parseDate = function( value, format ) {
                    if( !value )
                        return false;
                    format = format.split('_');
                    var dateFormat = format[0];
                    var separators = { slash: '/', dash: '-', dot: '.' };
                    var separator = format.length > 1 ? separators[format[1]] : separators.slash;
                    var dateArr = value.split(separator);
                    switch( dateFormat ) {
                    case 'mdy':
                        return new Date( dateArr[2], dateArr[0] - 1, dateArr[1] );
                    case 'dmy':
                        return new Date( dateArr[2], dateArr[1] - 1, dateArr[0] );
                    case 'ymd':
                        return new Date( dateArr[0], dateArr[1] - 1, dateArr[2] );
                    }
                    return false;
                }
                this.populateDayCount = function() {
                    this.countInput.val( this.getDayCount() ).change();
                }
                this.isValidDate = function( date ) {
                    return !isNaN( Date.parse( date ) );
                }
                this.init();
            }
        })(jQuery);
        </script>

        <?php
        self::$script_output = true;
        return $form;
    }
    function add_init_script( $return ) {
        $start_field_format = false;
        $end_field_format = false;
        foreach( $this->form['fields'] as &$field ) {
            if( $field['id'] == $this->start_field_id )
                $start_field_format = $field['dateFormat'] ? $field['dateFormat'] : 'mdy';
            if( $field['id'] == $this->end_field_id )
                $end_field_format = $field['dateFormat'] ? $field['dateFormat'] : 'mdy';
        }
        $script = "new gwdc({
                formId:             {$this->form['id']},
                startFieldId:       {$this->start_field_id},
                startDateFormat:    '$start_field_format',
                endFieldId:         {$this->end_field_id},
                endDateFormat:      '$end_field_format',
                countFieldId:       {$this->count_field_id},
                countAdjust:        {$this->count_adjust}
            });";
        $slug = implode( '_', array( 'gw_display_count', $this->start_field_id, $this->end_field_id, $this->count_field_id ) );
        GFFormDisplay::add_init_script( $this->form['id'], $slug, GFFormDisplay::ON_PAGE_RENDER, $script );
        // remove filter so init script is not output on subsequent forms
        remove_filter( 'gform_init_scripts_footer', array( &$this, 'add_init_script' ) );
        return $return;
    }
    function override_submitted_value( $form ) {
        $start_date = false;
        $end_date = false;
        foreach( $form['fields'] as &$field ) {
            if( $field['id'] == $this->start_field_id )
                $start_date = self::parse_field_date( $field );
            if( $field['id'] == $this->end_field_id )
                $end_date = self::parse_field_date( $field );
        }
        if( $start_date > $end_date ) {
            $day_count = 0;
        } else {
            $diff = $end_date - $start_date;
            $day_count = $diff / ( 60 * 60 * 24 ); // secs * mins * hours
            $day_count = round( $day_count ) + $this->count_adjust;
        }
        $_POST["input_{$this->count_field_id}"] = $day_count;
    }
    static function parse_field_date( $field ) {
        $date_value = rgpost("input_{$field['id']}");
        $date_format = empty( $field['dateFormat'] ) ? 'mdy' : esc_attr( $field['dateFormat'] );
        $date_info = GFCommon::parse_date( $date_value, $date_format );
        if( empty( $date_info ) )
            return false;
        return strtotime( "{$date_info['year']}-{$date_info['month']}-{$date_info['day']}" );
    }
}
# Configuration
// new GWDayCount( array(
//     'form_id'        => 3,
//     'start_field_id' => 55,
//     'end_field_id'   => 56,
//     'count_field_id' => 58
// ) );

new GWDayCount( array(
    'form_id'        => 3,
    'start_field_id' => 55,
    'end_field_id'   => 80,
    'count_field_id' => 58
) );

new GWDayCount( array(
    'form_id'        => 3,
    'start_field_id' => 67,
    'end_field_id'   => 68,
    'count_field_id' => 69
) );
/* END CUSTOM */

/*
|----------------------------------------------------
|  Framework: Require once to clean up WordPress
|----------------------------------------------------
*/

require_once("cleanup.php");

/*
|----------------------------------------------------
|  Framework: Hide metaboxes
|----------------------------------------------------
*/

function generate_remove_metaboxes()
{
    remove_action('add_meta_boxes', 'generate_add_layout_meta_box');
    remove_action('add_meta_boxes', 'generate_add_footer_widget_meta_box');
    //remove_action('add_meta_boxes', 'generate_add_de_meta_box');
    remove_action('add_meta_boxes', 'generate_add_page_builder_meta_box');
}
add_action( 'after_setup_theme','generate_remove_metaboxes' );

/*
|----------------------------------------------------
|  Page Builder: Disable generation of css files
|----------------------------------------------------
*/

function wbe_filter_widget_css( $css, $instance, $widget ){
}
add_filter('siteorigin_widgets_instance_css', 'wbe_filter_widget_css', 10, 3);

/*
|----------------------------------------------------
|  Page Builder: Add css to hide forbidden options
|----------------------------------------------------
*/

function admin_style() {
  wp_enqueue_style('admin-styles', get_stylesheet_directory_uri().'/admin.css');
}
add_action('admin_enqueue_scripts', 'admin_style');

/*
|------------------------------------------------------
|  GeneratePress: Disable collapse of secondary nav
|------------------------------------------------------
*/

add_action( 'wp_enqueue_scripts', 'generate_dequeue_secondary_nav_mobile', 999 );
function generate_dequeue_secondary_nav_mobile() {
   wp_dequeue_style( 'generate-secondary-nav-mobile' );
}

/*
|------------------------------------------------------
|  WordPress: Enqueue Custom CSS & JS
|------------------------------------------------------
*/
function add_theme_scripts() {

  // JS
  wp_enqueue_script( 'script', get_stylesheet_directory_uri() . '/js/custom.js', array ( 'jquery' ), 1.0, true);
}
add_action( 'wp_enqueue_scripts', 'add_theme_scripts' );

/*
|----------------------------------------------------
|  WordPress: Remove and/or Rename userrole(s)
|----------------------------------------------------
*/

// Remove Role: Subscriber / Abonnee
if( get_role('subscriber') ){
      remove_role( 'subscriber' );
}

// Remove Role: Author / Auteur
if( get_role('author') ){
      remove_role( 'author' );
}

// Remove Role: Contributor / Schrijver
if( get_role('contributor') ){
      remove_role( 'contributor' );
}

// Remove Role: BackWPup-beheerder
if( get_role('backwpup_admin') ){
      remove_role( 'backwpup_admin' );
}

// Remove Role: BackWPup taken-controle
if( get_role('backwpup_check') ){
      remove_role( 'backwpup_check' );
}

// Remove Role: BackWPup takenhulp
if( get_role('backwpup_helper') ){
      remove_role( 'backwpup_helper' );
}

// Remove Role: Translator
if( get_role('translator') ){
      remove_role( 'translator' );
}

// Rename userrole(s)
function change_role_name() {
    global $wp_roles;

    if ( ! isset( $wp_roles ) )
        $wp_roles = new WP_Roles();

    //You can list all currently available roles like this...
    //$roles = $wp_roles->get_names();
    //echo '<pre>',print_r($roles),'</pre>';

    //Default roles are: "administrator", "editor", "author", "contributor" or "subscriber"...
    $wp_roles->roles['administrator']['name'] = 'Webmaster';
    $wp_roles->role_names['administrator'] = 'Webmaster';

    $wp_roles->roles['editor']['name'] = 'Beheer';
    $wp_roles->role_names['editor'] = 'Beheer';

}
add_action('init', 'change_role_name');

/*
|------------------------------------------------------
|  WordPress: Change the Login Logo
|------------------------------------------------------
*/
function my_login_logo() { ?>
    <style type="text/css">
        #login h1 a, .login h1 a {
            background-image: url(/wp-content/themes/generatepress-child/logo.png);
            width: 320px;
            background-size: 160px;
			height: 100px;
        }
    </style>
<?php }
add_action( 'login_enqueue_scripts', 'my_login_logo' );

/*
|----------------------------------------------------
|  WordPress: More unique slugs for attachments
|----------------------------------------------------
*/
add_filter( 'wp_unique_post_slug', 'unique_post_slug', 10, 6 );
function unique_post_slug( $slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug ) {
  if ( 'attachment' == $post_type )
    $slug = $original_slug . uniqid( '-' );
  return $slug;
}

/*
|------------------------------------------------------
|  WordPress: Add searchform to secondary nav
|------------------------------------------------------
*/

// add_filter('wp_nav_menu_items','add_search_box', 10, 2);
function add_search_box($items, $args) {

	if( $args->theme_location == 'secondary')  {
        ob_start();
        get_search_form();
        $searchform = ob_get_contents();
        ob_end_clean();

        $items .= '<li class="search-item">' . $searchform . '</li>';
    }
    return $items;
}


add_action( 'admin_menu', 'gp_remove_menus' );
function gp_remove_menus(){

    //remove_menu_page( 'index.php' );                  //Dashboard
    //remove_menu_page( 'jetpack' );                    //Jetpack*
    remove_menu_page( 'edit.php' );                   //Posts
    //remove_menu_page( 'upload.php' );                 //Media
    //remove_menu_page( 'edit.php?post_type=page' );    //Pages
    remove_menu_page( 'link-manager.php' );           //Links
    remove_menu_page( 'edit-comments.php' );          //Comments
    //remove_menu_page( 'themes.php' );                 //Appearance
    remove_submenu_page( 'themes.php', 'themes.php' );          //Themes selector
    remove_submenu_page( 'themes.php' , 'gp_hooks_settings' );  //GP Hooks
    remove_submenu_page( 'themes.php', 'generate-options' );    //GP Options
    //remove_menu_page( 'plugins.php' );                //Plugins
    //remove_menu_page( 'users.php' );                  //Users
    //remove_menu_page( 'tools.php' );                  //Tools
    //remove_menu_page( 'options-general.php' );        //Settings

}
add_action( 'admin_bar_menu', 'remove_some_nodes_from_admin_top_bar_menu', 999 ); // Remove customizer option from top bar
function remove_some_nodes_from_admin_top_bar_menu( $wp_admin_bar ) {
    $wp_admin_bar->remove_menu( 'customize' );
}

// add_action( 'admin_menu', 'disable_customizer' ); // Remove customizer from wp-admin
// function disable_customizer() {
//     global $submenu;
//     if ( isset( $submenu[ 'themes.php' ] ) ) {
//         foreach ( $submenu[ 'themes.php' ] as $index => $menu_item ) {
//             if ( in_array( 'Customize', $menu_item ) ) {
//                 unset( $submenu[ 'themes.php' ][ $index ] );
//             }
//         }
//     }
// }

function remove_customize() {
    $customize_url_arr = array();
    $customize_url_arr[] = 'customize.php'; // 3.x
    $customize_url = add_query_arg( 'return', urlencode( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'customize.php' );
    $customize_url_arr[] = $customize_url; // 4.0 & 4.1
    if ( current_theme_supports( 'custom-header' ) && current_user_can( 'customize') ) {
        $customize_url_arr[] = add_query_arg( 'autofocus[control]', 'header_image', $customize_url ); // 4.1
        $customize_url_arr[] = 'custom-header'; // 4.0
    }
    if ( current_theme_supports( 'custom-background' ) && current_user_can( 'customize') ) {
        $customize_url_arr[] = add_query_arg( 'autofocus[control]', 'background_image', $customize_url ); // 4.1
        $customize_url_arr[] = 'custom-background'; // 4.0
    }
    foreach ( $customize_url_arr as $customize_url ) {
        remove_submenu_page( 'themes.php', $customize_url );
    }
}
add_action( 'admin_menu', 'remove_customize', 999 );
